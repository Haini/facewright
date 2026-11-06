<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Service;

use OCP\IAppConfig;
use OCP\IDBConnection;

/**
 * The checks and (in later stages) the backfill behind `occ facewright:own-tables`, which moves
 * people and face assignments out of Recognize's tables into ones we own - see
 * docs/own-tables.md.
 *
 * precheck() is read-only and runs before anything is migrated. It counts what the backfill would
 * trip over, so the answer to "which row wins?" is a decision taken with the numbers in hand, not
 * a unique-index violation halfway through a chunk.
 */
class OwnTablesMigrationService {
	/**
	 * Counts that must be zero before the backfill may start. Everything else precheck() returns is
	 * information: legacy rows and empty people are expected, and the plan decides their fate later.
	 */
	public const MUST_BE_ZERO = [
		'unlinked_long_rows',
		'link_user_mismatch',
		'duplicate_user_face_extra_rows',
		'links_missing_face',
		'unknown_sentinel_rows',
	];

	private const APP_ID = 'facewright_bridge';
	private const CURSOR_KEY = 'own_tables_after_id';

	public function __construct(
		private IDBConnection $db,
		private IAppConfig $appConfig,
	) {
	}

	/**
	 * The backfill and the verification are written in MySQL/MariaDB SQL (INSERT ... ON DUPLICATE KEY,
	 * the null-safe <=>), like the rest of the project's deployment, and say so instead of failing
	 * halfway on another platform.
	 */
	public function isSupportedPlatform(): bool {
		return in_array($this->db->getDatabaseProvider(true), [IDBConnection::PLATFORM_MYSQL, IDBConnection::PLATFORM_MARIADB], true);
	}

	public function tablesExist(): bool {
		return $this->db->tableExists('facewright_user_faces') && $this->db->tableExists('facewright_clusters');
	}

	public function isPaused(): bool {
		return $this->appConfig->getValueString(self::APP_ID, 'processing_paused', 'false') === 'true';
	}

	public function getCursor(): int {
		return $this->appConfig->getValueInt(self::APP_ID, self::CURSOR_KEY, 0);
	}

	public function resetCursor(): void {
		$this->appConfig->deleteKey(self::APP_ID, self::CURSOR_KEY);
	}

	public function setCursor(int $afterId): void {
		$this->appConfig->setValueInt(self::APP_ID, self::CURSOR_KEY, $afterId);
	}

	/** The highest detection id; the backfill walks the id range up to it. */
	public function maxDetectionId(): int {
		return $this->count('SELECT COALESCE(MAX(id), 0) FROM ' . $this->table('recognize_face_detections'));
	}

	/**
	 * Copies every person from Recognize's table, including the ones with no face (a named person
	 * with no faces left is still the user's work), in id ranges. Safe to repeat: a row is rewritten
	 * from its source.
	 *
	 * @return array{source: int, inStore: int}
	 */
	public function migrateClusters(int $chunk, bool $dryRun): array {
		$rfc = $this->table('recognize_face_clusters');
		$fc = $this->table('facewright_clusters');
		$max = $this->count("SELECT COALESCE(MAX(id), 0) FROM {$rfc}");

		for ($after = 0; $after < $max && !$dryRun; $after += $chunk) {
			$upper = $after + $chunk;
			$this->db->beginTransaction();
			try {
				$this->db->executeStatement(
					"INSERT INTO {$fc} (id, user_id, title) SELECT id, user_id, title FROM {$rfc} "
					. "WHERE id > {$after} AND id <= {$upper} "
					. 'ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), title = VALUES(title)'
				);
				$this->db->commit();
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}
		}

		return [
			'source' => $this->count("SELECT COUNT(*) FROM {$rfc}"),
			'inStore' => $this->count("SELECT COUNT(*) FROM {$fc}"),
		];
	}

	/**
	 * Copies one id range of linked detection rows into facewright_user_faces. Only id, user, cluster
	 * and the link are read; the vector column is never touched. A face assigned to a person that no
	 * longer exists is copied as pending, as the plan says; rows with no link are not ours and are
	 * not copied.
	 *
	 * @return array{upper: int, source: int}
	 */
	public function migrateFaceRange(int $afterId, int $chunk, bool $dryRun): array {
		$rfd = $this->table('recognize_face_detections');
		$rfc = $this->table('recognize_face_clusters');
		$links = $this->table('facewright_detection_faces');
		$uf = $this->table('facewright_user_faces');
		$upper = $afterId + $chunk;

		$source = $this->count(
			"SELECT COUNT(*) FROM {$rfd} d INNER JOIN {$links} l ON l.detection_id = d.id WHERE d.id > {$afterId} AND d.id <= {$upper}"
		);
		if ($dryRun) {
			return ['upper' => $upper, 'source' => $source];
		}

		$this->db->beginTransaction();
		try {
			$this->db->executeStatement(
				"INSERT INTO {$uf} (id, user_id, face_id, cluster_id, state) "
				. 'SELECT d.id, COALESCE(d.user_id, l.user_id), l.face_id, '
				. '  CASE WHEN d.cluster_id > 0 AND c.id IS NOT NULL THEN d.cluster_id ELSE NULL END, '
				. '  ' . self::stateExpression('d', 'c') . ' '
				. "FROM {$rfd} d "
				. "INNER JOIN {$links} l ON l.detection_id = d.id "
				. "LEFT JOIN {$rfc} c ON c.id = d.cluster_id AND d.cluster_id > 0 "
				. "WHERE d.id > {$afterId} AND d.id <= {$upper} "
				. 'ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), face_id = VALUES(face_id), '
				. 'cluster_id = VALUES(cluster_id), state = VALUES(state)'
			);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return ['upper' => $upper, 'source' => $source];
	}

	/**
	 * What the verification may rely on. Each entry is [value, kind]: kind 'zero' means any other value
	 * is a failure, 'equal' pairs with the entry named in the third element, 'info' is only reported.
	 *
	 * @return array<string, array{0: int, 1: string, 2?: string}>
	 */
	public function verify(): array {
		$rfd = $this->table('recognize_face_detections');
		$rfc = $this->table('recognize_face_clusters');
		$links = $this->table('facewright_detection_faces');
		$uf = $this->table('facewright_user_faces');
		$fc = $this->table('facewright_clusters');

		$dangling = 'd.cluster_id > 0 AND c.id IS NULL';
		$state = self::stateExpression('d', 'c');

		return [
			'detections_linked' => [$this->count("SELECT COUNT(*) FROM {$rfd} d INNER JOIN {$links} l ON l.detection_id = d.id"), 'equal', 'user_faces'],
			'user_faces' => [$this->count("SELECT COUNT(*) FROM {$uf}"), 'equal', 'detections_linked'],
			// Every linked row must have a copy that says the same. A face assigned to a person that no
			// longer exists is left out here and counted below: the backfill reopens it as pending,
			// while a later change would copy it as assigned, and both are correct for such a row.
			'face_row_mismatches' => [$this->count(
				"SELECT COUNT(*) FROM {$rfd} d INNER JOIN {$links} l ON l.detection_id = d.id "
				. "LEFT JOIN {$rfc} c ON c.id = d.cluster_id AND d.cluster_id > 0 "
				. "LEFT JOIN {$uf} u ON u.id = d.id "
				. "WHERE NOT ({$dangling}) AND (u.id IS NULL OR u.user_id <> COALESCE(d.user_id, l.user_id) "
				. "OR u.face_id <> l.face_id OR u.state <> {$state} "
				. 'OR NOT (u.cluster_id <=> IF(d.cluster_id > 0, d.cluster_id, NULL)))'
			), 'zero'],
			'dangling_rows' => [$this->count(
				"SELECT COUNT(*) FROM {$rfd} d INNER JOIN {$links} l ON l.detection_id = d.id "
				. "LEFT JOIN {$rfc} c ON c.id = d.cluster_id AND d.cluster_id > 0 WHERE {$dangling}"
			), 'info'],
			'dangling_rows_missing' => [$this->count(
				"SELECT COUNT(*) FROM {$rfd} d INNER JOIN {$links} l ON l.detection_id = d.id "
				. "LEFT JOIN {$rfc} c ON c.id = d.cluster_id AND d.cluster_id > 0 LEFT JOIN {$uf} u ON u.id = d.id "
				. "WHERE {$dangling} AND u.id IS NULL"
			), 'zero'],
			'copies_without_detection' => [$this->count(
				"SELECT COUNT(*) FROM {$uf} u LEFT JOIN {$rfd} d ON d.id = u.id WHERE d.id IS NULL"
			), 'zero'],
			'copies_without_link' => [$this->count(
				"SELECT COUNT(*) FROM {$uf} u LEFT JOIN {$links} l ON l.detection_id = u.id WHERE l.detection_id IS NULL"
			), 'zero'],
			'state_cluster_violations' => [$this->count(
				"SELECT COUNT(*) FROM {$uf} WHERE (state = 1) <> (cluster_id IS NOT NULL)"
			), 'zero'],
			'people_in_source' => [$this->count("SELECT COUNT(*) FROM {$rfc}"), 'equal', 'people_in_store'],
			'people_in_store' => [$this->count("SELECT COUNT(*) FROM {$fc}"), 'equal', 'people_in_source'],
			'people_row_mismatches' => [$this->count(
				"SELECT COUNT(*) FROM {$rfc} c LEFT JOIN {$fc} f ON f.id = c.id "
				. 'WHERE f.id IS NULL OR f.user_id <> c.user_id OR f.title <> c.title'
			), 'zero'],
			'people_copies_without_source' => [$this->count(
				"SELECT COUNT(*) FROM {$fc} f LEFT JOIN {$rfc} c ON c.id = f.id WHERE c.id IS NULL"
			), 'zero'],
		];
	}

	/**
	 * The state a Recognize row maps to, as SQL: NULL pending, a real person assigned, -1 unassigned,
	 * -2 unclusterable, -3 ignored. A person that no longer exists reads as pending. FaceState is the
	 * same mapping in PHP.
	 */
	private static function stateExpression(string $detection, string $cluster): string {
		return "CASE WHEN {$detection}.cluster_id IS NULL THEN 0 "
			. "WHEN {$detection}.cluster_id > 0 THEN CASE WHEN {$cluster}.id IS NOT NULL THEN 1 ELSE 0 END "
			. "WHEN {$detection}.cluster_id = -1 THEN 2 WHEN {$detection}.cluster_id = -2 THEN 3 "
			. "WHEN {$detection}.cluster_id = -3 THEN 4 ELSE 0 END";
	}

	/**
	 * @return array<string, int> name => count, in the order they are printed
	 */
	public function precheck(): array {
		$rfd = $this->table('recognize_face_detections');
		$rfc = $this->table('recognize_face_clusters');
		$links = $this->table('facewright_detection_faces');
		$faces = $this->table('facewright_faces');
		$unlinked = $this->unlinkedRows($rfd, $links);

		return [
			'detections_total' => $this->count("SELECT COUNT(*) FROM {$rfd}"),
			'detections_linked' => $this->count(
				"SELECT COUNT(*) FROM {$rfd} d INNER JOIN {$links} l ON l.detection_id = d.id"
			),
			// Long enough to be ours by the old proxy but with no link: not yet backfilled by
			// occ facewright:migrate-faces. The own-tables backfill would silently skip them.
			'unlinked_long_rows' => $unlinked['long'],
			// Not ours: Recognize's legacy rows and the degenerate all-zero embedding.
			'unlinked_other_rows' => $unlinked['other'],
			'link_user_mismatch' => $this->count(
				"SELECT COUNT(*) FROM {$rfd} d INNER JOIN {$links} l ON l.detection_id = d.id "
				. 'WHERE l.user_id <> d.user_id'
			),
			// A link whose detection row is gone, e.g. after Recognize deleted it by file id. The
			// backfill walks detection rows, so these are ignored; they are reported because the
			// same deletion path can leave them behind again.
			'links_without_detection' => $this->count(
				"SELECT COUNT(*) FROM {$links} l LEFT JOIN {$rfd} d ON d.id = l.detection_id WHERE d.id IS NULL"
			),
			'links_missing_face' => $this->count(
				"SELECT COUNT(*) FROM {$links} l LEFT JOIN {$faces} f ON f.id = l.face_id WHERE f.id IS NULL"
			),
			// The new table is unique on (user_id, face_id). FaceMigrationService grouped rows by
			// geometry per file with no per-user uniqueness, so this cannot be assumed to be zero.
			'duplicate_user_face_groups' => $this->count(
				"SELECT COUNT(*) FROM (SELECT user_id, face_id FROM {$links} GROUP BY user_id, face_id "
				. 'HAVING COUNT(*) > 1) g'
			),
			'duplicate_user_face_extra_rows' => $this->count(
				"SELECT COALESCE(SUM(c - 1), 0) FROM (SELECT COUNT(*) c FROM {$links} GROUP BY user_id, face_id "
				. 'HAVING COUNT(*) > 1) g'
			),
			// Assigned to a person that no longer exists. The backfill reopens these as pending.
			'dangling_cluster_rows' => $this->count(
				"SELECT COUNT(*) FROM {$rfd} d INNER JOIN {$links} l ON l.detection_id = d.id "
				. "LEFT JOIN {$rfc} c ON c.id = d.cluster_id WHERE d.cluster_id > 0 AND c.id IS NULL"
			),
			// A cluster_id that is neither NULL, a person (> 0) nor one of -1, -2, -3 has no state to map to.
			'unknown_sentinel_rows' => $this->count(
				"SELECT COUNT(*) FROM {$rfd} d INNER JOIN {$links} l ON l.detection_id = d.id "
				. 'WHERE d.cluster_id = 0 OR d.cluster_id < -3'
			),
			'people_total' => $this->count("SELECT COUNT(*) FROM {$rfc}"),
			// People with no linked member. Titled ones are the user's work; legacy people from
			// Recognize's own classifier are among the untitled and titled ones alike.
			'people_without_members_titled' => $this->count($this->emptyPeople($rfc, $rfd, $links) . " AND c.title <> ''"),
			'people_without_members_untitled' => $this->count($this->emptyPeople($rfc, $rfd, $links) . " AND c.title = ''"),
		];
	}

	/**
	 * Splits the detection rows that have no link by whether their vector is long enough to be ours.
	 *
	 * Two steps on purpose. CHAR_LENGTH(face_vector) in the same statement as the join leaves the
	 * order of evaluation to the optimizer, and a plan that measures every row reads every off-page
	 * vector of the table: about a gigabyte on a real library, evicting everyone else's cache. The
	 * ids come from the index-only join first, and only those rows are measured.
	 *
	 * @return array{long: int, other: int}
	 */
	private function unlinkedRows(string $rfd, string $links): array {
		$floor = DetectionService::VECTOR_LENGTH_FLOOR;
		$long = 0;
		$other = 0;

		$result = $this->db->executeQuery(
			"SELECT d.id FROM {$rfd} d LEFT JOIN {$links} l ON l.detection_id = d.id WHERE l.detection_id IS NULL ORDER BY d.id"
		);
		$ids = [];
		while (($id = $result->fetchOne()) !== false) {
			$ids[] = (int)$id;
		}
		$result->closeCursor();

		foreach (array_chunk($ids, 1000) as $chunk) {
			$list = implode(',', $chunk); // integers only
			$row = $this->db->executeQuery(
				"SELECT COALESCE(SUM(CHAR_LENGTH(face_vector) > {$floor}), 0) AS long_rows, COUNT(*) AS total FROM {$rfd} WHERE id IN ({$list})"
			)->fetchAssociative();
			$long += (int)$row['long_rows'];
			$other += (int)$row['total'] - (int)$row['long_rows'];
		}
		return ['long' => $long, 'other' => $other];
	}

	private function emptyPeople(string $rfc, string $rfd, string $links): string {
		return "SELECT COUNT(*) FROM {$rfc} c WHERE NOT EXISTS ("
			. "SELECT 1 FROM {$rfd} d INNER JOIN {$links} l ON l.detection_id = d.id WHERE d.cluster_id = c.id)";
	}

	/** The prefixed name, as the query builder would write it. */
	private function table(string $name): string {
		return $this->db->getQueryBuilder()->getTableName($name);
	}

	private function count(string $sql): int {
		$result = $this->db->executeQuery($sql);
		$value = (int)$result->fetchOne();
		$result->closeCursor();
		return $value;
	}
}

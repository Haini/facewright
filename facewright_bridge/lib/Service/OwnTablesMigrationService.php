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

	/**
	 * How far above Recognize's counters ours are seeded: enough that no id of ours starts on a row Recognize already
	 * holds. It does not keep the spaces apart for good: a push of an explicit id moves Recognize's counter past it,
	 * so its next row lands where our next id will. ProjectionService::reconcile() handles that meeting (a row on the
	 * id of a visible face is overwritten, on a hidden one removed as a squatter).
	 */
	public const SEED_MARGIN = 1000;

	private const APP_ID = 'facewright_bridge';
	private const CURSOR_KEY = 'own_tables_after_id';

	public function __construct(
		private IDBConnection $db,
		private IAppConfig $appConfig,
		private ProjectionService $projection,
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

	/**
	 * Whether our table already holds faces. Since Stage E it is the source of truth, so nothing may copy Recognize's
	 * table over it: that table is a sparse copy, and after one of Recognize's resets a wiped one.
	 */
	public function storeHoldsData(): bool {
		return $this->count('SELECT COUNT(*) FROM (SELECT 1 FROM ' . $this->table('facewright_user_faces') . ' LIMIT 1) x') > 0;
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
		$links = $this->table('facewright_detection_faces');
		$uf = $this->table('facewright_user_faces');
		$fc = $this->table('facewright_clusters');
		$faces = $this->table('facewright_faces');

		// Ours, internally. Since Stage E our tables are the source of truth, so what must hold is about them.
		$checks = [
			'state_cluster_violations' => [$this->count(
				"SELECT COUNT(*) FROM {$uf} WHERE (state = 1) <> (cluster_id IS NOT NULL)"
			), 'zero'],
			'assigned_to_missing_person' => [$this->count(
				"SELECT COUNT(*) FROM {$uf} u LEFT JOIN {$fc} c ON c.id = u.cluster_id AND c.user_id = u.user_id "
				. 'WHERE u.cluster_id IS NOT NULL AND c.id IS NULL'
			), 'zero'],
			'faces_without_physical_face' => [$this->count(
				"SELECT COUNT(*) FROM {$uf} u LEFT JOIN {$faces} f ON f.id = u.face_id WHERE f.id IS NULL"
			), 'zero'],
			'copies_without_link' => [$this->count(
				"SELECT COUNT(*) FROM {$uf} u LEFT JOIN {$links} l ON l.detection_id = u.id WHERE l.detection_id IS NULL"
			), 'zero'],
			'links_without_copy' => [$this->count(
				"SELECT COUNT(*) FROM {$links} l LEFT JOIN {$uf} u ON u.id = l.detection_id WHERE u.id IS NULL"
			), 'zero'],
			'link_user_or_face_mismatch' => [$this->count(
				"SELECT COUNT(*) FROM {$uf} u INNER JOIN {$links} l ON l.detection_id = u.id WHERE l.user_id <> u.user_id OR l.face_id <> u.face_id"
			), 'zero'],
			// A stored checksum must be the blob's: the ExApp's embedding cache trusts it (docs/exapp-clustering.md, S1).
			// NULL is "unknown" and allowed; readers compute it then.
			'embedding_crc_wrong' => [$this->count(
				"SELECT COUNT(*) FROM {$faces} WHERE embedding_crc IS NOT NULL AND embedding_crc <> CRC32(embedding)"
			), 'zero'],
			'embedding_crc_unknown' => [$this->count("SELECT COUNT(*) FROM {$faces} WHERE embedding_crc IS NULL"), 'info'],
			'people_in_store' => [$this->count("SELECT COUNT(*) FROM {$fc}"), 'info'],
			'faces_in_store' => [$this->count("SELECT COUNT(*) FROM {$uf}"), 'info'],
		];

		// Recognize's tables against ours (what Memories shows): the projection's own reconcile, read-only.
		if (!$this->projection->available()) {
			$checks['projection_available'] = [1, 'zero'];
			return $checks;
		}
		$r = $this->projection->reconcile(true);
		$checks['A_visible_faces_wrong'] = [$r['A'], 'zero'];
		$checks['C_stale_copies'] = [$r['C'], 'zero'];
		$checks['D_people_missing'] = [$r['D_missing'], 'zero'];
		$checks['D_people_stale'] = [$r['D_stale'], 'zero'];
		$checks['B_hidden_remnants'] = [$r['B'], 'info'];
		$checks['legacy_rows_untouched'] = [$r['legacy'], 'info'];
		return $checks;
	}

	/**
	 * Raises the id counters of our two tables above Recognize's, plus a margin, so an id we allocate can never
	 * be one Recognize's tables already hold. MariaDB does not advance a counter on
	 * explicit inserts into another table, which is why this is a step of its own. Only ever raises.
	 *
	 * @return array<string, array{current: int, floor: int, raised: bool}>
	 */
	public function seedIds(bool $dryRun, int $margin = self::SEED_MARGIN): array {
		$out = [];
		foreach ([['facewright_user_faces', 'recognize_face_detections'], ['facewright_clusters', 'recognize_face_clusters']] as [$ours, $theirs]) {
			$current = $this->autoIncrement($ours);
			$floor = max(
				$this->autoIncrement($theirs),
				$this->count('SELECT COALESCE(MAX(id), 0) FROM ' . $this->table($theirs)) + 1,
				$this->count('SELECT COALESCE(MAX(id), 0) FROM ' . $this->table($ours)) + 1,
			) + $margin;
			$raised = $current < $floor;
			if ($raised && !$dryRun) {
				$this->db->executeStatement('ALTER TABLE ' . $this->table($ours) . ' AUTO_INCREMENT = ' . $floor);
			}
			$out[$ours] = ['current' => $current, 'floor' => $floor, 'raised' => $raised];
		}
		return $out;
	}

	private function autoIncrement(string $name): int {
		// The name goes into the SQL text, not into a bound parameter: the table prefix is substituted in the text only.
		$result = $this->db->executeQuery(
			"SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . trim($this->table($name), '`') . "'"
		);
		$value = $result->fetchOne();
		$result->closeCursor();
		return (int)$value;
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

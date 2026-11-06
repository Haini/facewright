<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Service;

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
	];

	public function __construct(
		private IDBConnection $db,
	) {
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

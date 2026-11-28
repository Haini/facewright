<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Service;

use OCP\IDBConnection;

/**
 * The checks behind `occ facewright:own-tables --verify`: our tables internally, and the copy Memories reads against them.
 */
class OwnTablesCheckService {
	public function __construct(
		private IDBConnection $db,
		private ProjectionService $projection,
	) {
	}

	/** The verification is written in MySQL/MariaDB SQL, like the rest of the project's deployment, and says so instead of failing halfway on another platform. */
	public function isSupportedPlatform(): bool {
		return in_array($this->db->getDatabaseProvider(true), [IDBConnection::PLATFORM_MYSQL, IDBConnection::PLATFORM_MARIADB], true);
	}

	public function tablesExist(): bool {
		return $this->db->tableExists('facewright_user_faces') && $this->db->tableExists('facewright_clusters');
	}

	/**
	 * What the verification may rely on. Each entry is [value, kind]: kind 'zero' means any other value
	 * is a failure, 'equal' pairs with the entry named in the third element, 'info' is only reported.
	 *
	 * @return array<string, array{0: int, 1: string, 2?: string}>
	 */
	public function verify(): array {
		$uf = $this->table('facewright_user_faces');
		$fc = $this->table('facewright_clusters');
		$faces = $this->table('facewright_faces');
		$cs = $this->table('facewright_cluster_stats');

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
			// A stored checksum must be the blob's: the ExApp's embedding cache trusts it (docs/exapp-clustering.md, S1).
			// NULL is "unknown" and allowed; readers compute it then.
			'embedding_crc_wrong' => [$this->count(
				"SELECT COUNT(*) FROM {$faces} WHERE embedding_crc IS NOT NULL AND embedding_crc <> CRC32(embedding)"
			), 'zero'],
			'embedding_crc_unknown' => [$this->count("SELECT COUNT(*) FROM {$faces} WHERE embedding_crc IS NULL"), 'info'],
			// Each person's stored size and cover (FaceStore keeps them with every write; --recount rebuilds them).
			'people_stats_missing' => [$this->count(
				"SELECT COUNT(*) FROM {$fc} c LEFT JOIN {$cs} s ON s.cluster_id = c.id WHERE s.cluster_id IS NULL OR s.user_id <> c.user_id"
			), 'zero'],
			'people_stats_size_wrong' => [$this->count(
				"SELECT COUNT(*) FROM {$cs} s WHERE s.face_count <> (SELECT COUNT(*) FROM {$uf} u WHERE u.user_id = s.user_id AND u.cluster_id = s.cluster_id)"
			), 'zero'],
			// No cover while it has faces, or a cover that is not one of them.
			'people_stats_cover_invalid' => [$this->count(
				"SELECT COUNT(*) FROM {$cs} s WHERE (s.cover_id IS NULL) <> (s.face_count = 0) OR (s.cover_id IS NOT NULL AND NOT EXISTS "
				. "(SELECT 1 FROM {$uf} u WHERE u.id = s.cover_id AND u.user_id = s.user_id AND u.cluster_id = s.cluster_id))"
			), 'zero'],
			// A valid cover that is not the best face: only a forced re-detection that changed a score in place does that.
			'people_stats_cover_not_best' => [$this->count(
				"SELECT COUNT(*) FROM {$cs} s INNER JOIN (SELECT id, cluster_id FROM (SELECT u.id, u.cluster_id, ROW_NUMBER() OVER "
				. '(PARTITION BY u.cluster_id ORDER BY ' . FaceStore::COVER_ORDER . ") AS rn FROM {$uf} u INNER JOIN {$faces} f ON f.id = u.face_id "
				. 'WHERE u.cluster_id IS NOT NULL) t WHERE rn = 1) b ON b.cluster_id = s.cluster_id WHERE s.cover_id <> b.id'
			), 'info'],
			'people_in_store' => [$this->count("SELECT COUNT(*) FROM {$fc}"), 'info'],
			'faces_in_store' => [$this->count("SELECT COUNT(*) FROM {$uf}"), 'info'],
		];

		// The copy Memories reads against ours: the projection's own reconcile, read-only.
		if (!$this->projection->available()) {
			$checks['projection_available'] = [1, 'zero'];
			return $checks;
		}
		$r = $this->projection->reconcile(true);
		$checks['A_visible_faces_wrong'] = [$r['A'], 'zero'];
		$checks['C_stale_rows'] = [$r['C'], 'zero'];
		$checks['D_people_missing'] = [$r['D_missing'], 'zero'];
		$checks['D_people_stale'] = [$r['D_stale'], 'zero'];
		return $checks;
	}

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

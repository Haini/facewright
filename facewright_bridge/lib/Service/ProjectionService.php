<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Service;

use OCP\IAppConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Makes Recognize's `recognize_face_detections` (rfd) and `recognize_face_clusters` (rfc) a copy of our own
 * tables, which Memories reads (docs/own-tables.md, Stage E). Our tables win, always.
 *
 * - Sparse: only ASSIGNED and UNASSIGNED faces are copied, so Recognize's table never holds a NULL cluster_id
 *   from us (which is what arms its MaintenanceJob) and never the -2 / -3 sentinels.
 * - `face_vector` is '[]' for rows we create and is never touched on rows that exist. It is safe only because
 *   the DAV router makes Recognize's "remove face" (the one reader of a real vector) unreachable.
 * - One write shape: INSERT ... SELECT ... ON DUPLICATE KEY UPDATE. Delete-then-insert would race when two
 *   pushes touch the same ids and would overwrite real vectors.
 * - Order: people that exist, then faces, then deleted people. No face ever points at a person that is gone.
 * - Called after the caller's own transaction has committed (detected: inside one it logs, flags dirty and
 *   leaves the copy to the repair job), and it never throws to the caller:
 *   a change in Recognize's schema must not break our own pages. A failure sets the dirty flag; the hourly
 *   job (and `occ facewright:project`) repairs.
 * - MariaDB only, like the rest of the project's deployment.
 *
 * Column order trap: Recognize's physical order is x, y, height, width. Every statement names its columns.
 */
class ProjectionService {
	private const APP_ID = 'facewright_bridge';
	/** Faces per statement. */
	private const CHUNK = 500;
	/** Rows of Recognize's table inspected per reconcile step. */
	private const PAGE = 5000;
	/** Vector lengths that mark a row as a copy of ours: '[]' and anything longer than the 128-dim legacy band. */
	private const STALE_EMPTY_LENGTH = 2;

	public const KEY_DIRTY = 'projection_dirty_seq';
	public const KEY_CLEAN = 'projection_clean_seq';
	public const KEY_ERROR = 'projection_last_error';
	public const KEY_ERROR_AT = 'projection_last_error_at';
	public const KEY_OK_AT = 'projection_last_ok_at';

	private ?bool $available = null;

	public function __construct(
		private IDBConnection $db,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}

	// ---------------------------------------------------------------------------------------------------
	// Availability and the dirty flag
	// ---------------------------------------------------------------------------------------------------

	/** Probed once per process: MariaDB, our tables, and the columns of Recognize's that the statements name. */
	public function available(): bool {
		if ($this->available !== null) {
			return $this->available;
		}
		try {
			$ok = in_array($this->db->getDatabaseProvider(true), [IDBConnection::PLATFORM_MYSQL, IDBConnection::PLATFORM_MARIADB], true)
				&& $this->db->tableExists('recognize_face_detections')
				&& $this->db->tableExists('recognize_face_clusters')
				&& $this->db->tableExists('facewright_user_faces')
				&& $this->db->tableExists('facewright_clusters');
			if ($ok) {
				$have = $this->columns('recognize_face_detections');
				$ok = array_diff(['id', 'user_id', 'file_id', 'x', 'y', 'height', 'width', 'face_vector', 'cluster_id', 'threshold'], $have) === []
					&& array_diff(['id', 'user_id', 'title'], $this->columns('recognize_face_clusters')) === [];
			}
		} catch (\Throwable $e) {
			$this->logger->error('facewright: projection availability check failed: ' . $e->getMessage(), ['app' => self::APP_ID]);
			$ok = false;
		}
		return $this->available = $ok;
	}

	/** @return list<string> */
	private function columns(string $name): array {
		$result = $this->db->executeQuery('SHOW COLUMNS FROM ' . $this->table($name));
		$cols = [];
		while (($row = $result->fetch()) !== false) {
			$cols[] = (string)$row['Field'];
		}
		$result->closeCursor();
		return $cols;
	}

	public function markDirty(string $message): void {
		$seq = $this->appConfig->getValueInt(self::APP_ID, self::KEY_DIRTY, 0) + 1;
		$this->appConfig->setValueInt(self::APP_ID, self::KEY_DIRTY, $seq);
		$this->appConfig->setValueString(self::APP_ID, self::KEY_ERROR, mb_substr($message, 0, 500));
		$this->appConfig->setValueInt(self::APP_ID, self::KEY_ERROR_AT, time());
	}

	public function dirtySeq(): int {
		return $this->appConfig->getValueInt(self::APP_ID, self::KEY_DIRTY, 0);
	}

	public function isDirty(): bool {
		return $this->dirtySeq() > $this->appConfig->getValueInt(self::APP_ID, self::KEY_CLEAN, 0);
	}

	/** Clears the flag only if nothing failed since $seenSeq was read: a failure that arrived meanwhile stays. */
	public function markClean(int $seenSeq): void {
		if ($this->dirtySeq() === $seenSeq) {
			$this->appConfig->setValueInt(self::APP_ID, self::KEY_CLEAN, $seenSeq);
			$this->appConfig->deleteKey(self::APP_ID, self::KEY_ERROR);
			$this->appConfig->deleteKey(self::APP_ID, self::KEY_ERROR_AT);
		}
		$this->appConfig->setValueInt(self::APP_ID, self::KEY_OK_AT, time());
	}

	/** @return array{dirty: bool, error: string, errorAt: int, okAt: int} */
	public function status(): array {
		return [
			'dirty' => $this->isDirty(),
			'error' => $this->appConfig->getValueString(self::APP_ID, self::KEY_ERROR, ''),
			'errorAt' => $this->appConfig->getValueInt(self::APP_ID, self::KEY_ERROR_AT, 0),
			'okAt' => $this->appConfig->getValueInt(self::APP_ID, self::KEY_OK_AT, 0),
		];
	}

	// ---------------------------------------------------------------------------------------------------
	// Push
	// ---------------------------------------------------------------------------------------------------

	/**
	 * Copies the given faces and people into Recognize's tables, from ours. Pass every id the caller touched,
	 * visible or not: an id that is no longer visible (or no longer exists) is removed from the copy.
	 *
	 * @param list<int|string> $faceIds  facewright_user_faces.id, which is also the rfd id
	 * @param list<int|string> $clusterIds facewright_clusters.id, which is also the rfc id
	 * @return bool whether the copy is now current; false has set the dirty flag and logged why
	 */
	public function push(array $faceIds, array $clusterIds = []): bool {
		if ($this->db->inTransaction()) {
			// A caller bug, but not one worth breaking the caller's write for: this runs from listeners (a file
			// being deleted) that must not fail. Say so loudly and let the repair job do the copy.
			$this->logger->error('facewright: ProjectionService::push() was called inside a transaction; the copy is left to the repair job', ['app' => self::APP_ID, 'exception' => new \LogicException('push inside a transaction')]);
			$this->markDirty('push() was called inside a transaction');
			return false;
		}
		$faceIds = $this->ids($faceIds);
		$clusterIds = $this->ids($clusterIds);
		if ($faceIds === [] && $clusterIds === []) {
			return true;
		}
		if (!$this->available()) {
			$this->markDirty('the projection is not available (unsupported database or Recognize\'s tables changed)');
			return false;
		}
		try {
			foreach (array_chunk($clusterIds, self::CHUNK) as $chunk) {
				DeadlockRetry::run(fn () => $this->upsertPeople($chunk));
			}
			foreach (array_chunk($faceIds, self::CHUNK) as $chunk) {
				DeadlockRetry::run(fn () => $this->upsertFaces($chunk));
			}
			foreach (array_chunk($clusterIds, self::CHUNK) as $chunk) {
				DeadlockRetry::run(fn () => $this->deleteGonePeople($chunk));
			}
			return true;
		} catch (\Throwable $e) {
			$this->logger->error('facewright: projection push failed: ' . $e->getMessage(), ['app' => self::APP_ID, 'exception' => $e]);
			$this->markDirty($e->getMessage());
			return false;
		}
	}

	/** @param list<int> $ids */
	private function upsertPeople(array $ids): void {
		$rfc = $this->table('recognize_face_clusters');
		$fc = $this->table('facewright_clusters');
		$this->db->executeStatement(
			"INSERT INTO {$rfc} (id, user_id, title) SELECT c.id, c.user_id, c.title FROM {$fc} c WHERE c.id IN (" . implode(',', $ids) . ') ORDER BY c.id '
			. 'ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), title = VALUES(title)'
		);
	}

	/** @param list<int> $ids */
	private function deleteGonePeople(array $ids): void {
		$this->db->executeStatement(
			'DELETE FROM ' . $this->table('recognize_face_clusters') . ' WHERE id IN (' . implode(',', $ids) . ') '
			. 'AND id NOT IN (SELECT id FROM ' . $this->table('facewright_clusters') . ')'
		);
	}

	/** @param list<int> $ids */
	private function upsertFaces(array $ids): void {
		$rfd = $this->table('recognize_face_detections');
		$uf = $this->table('facewright_user_faces');
		$faces = $this->table('facewright_faces');
		$list = implode(',', $ids);
		$visible = implode(',', FaceState::VISIBLE);
		$this->db->beginTransaction();
		try {
			$this->db->executeStatement(
				"INSERT INTO {$rfd} (id, user_id, file_id, x, y, width, height, face_vector, cluster_id, threshold) "
				. 'SELECT u.id, u.user_id, f.file_id, f.x, f.y, f.width, f.height, \'[]\', ' . $this->visibleClusterExpr('u') . ', 0 '
				. "FROM {$uf} u INNER JOIN {$faces} f ON f.id = u.face_id WHERE u.id IN ({$list}) AND u.state IN ({$visible}) ORDER BY u.id "
				. 'ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), file_id = VALUES(file_id), x = VALUES(x), y = VALUES(y), '
				. 'width = VALUES(width), height = VALUES(height), cluster_id = VALUES(cluster_id)'
			);
			$this->db->executeStatement(
				"DELETE FROM {$rfd} WHERE id IN ({$list}) AND id NOT IN (SELECT id FROM {$uf} WHERE state IN ({$visible}))"
			);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/** What Recognize's cluster_id is for a visible face: the person, or -1 for unassigned. */
	private function visibleClusterExpr(string $alias): string {
		return "CASE WHEN {$alias}.state = " . FaceState::ASSIGNED . " THEN {$alias}.cluster_id ELSE " . ClusteringService::UNASSIGNED . ' END';
	}

	// ---------------------------------------------------------------------------------------------------
	// Hydrate (rollback)
	// ---------------------------------------------------------------------------------------------------

	/**
	 * Writes every face and person of ours into Recognize's tables with all five states as the sentinels
	 * Stage D code understands (NULL, -1, -2, -3), deleting nothing. It exists for rollback only: after it,
	 * Recognize's tables are the full copy Stage C and D code expects.
	 *
	 * @return array{faces: int, people: int}
	 */
	public function hydrate(?string $userId, bool $dryRun): array {
		$rfd = $this->table('recognize_face_detections');
		$rfc = $this->table('recognize_face_clusters');
		$uf = $this->table('facewright_user_faces');
		$fc = $this->table('facewright_clusters');
		$faces = $this->table('facewright_faces');
		$userSql = $userId === null ? '' : ' AND u.user_id = ' . $this->quote($userId);
		$peopleUserSql = $userId === null ? '' : ' AND c.user_id = ' . $this->quote($userId);
		$sentinel = 'CASE u.state WHEN ' . FaceState::PENDING . ' THEN NULL WHEN ' . FaceState::ASSIGNED . ' THEN u.cluster_id'
			. ' WHEN ' . FaceState::UNASSIGNED . ' THEN ' . ClusteringService::UNASSIGNED
			. ' WHEN ' . FaceState::UNCLUSTERABLE . ' THEN ' . ClusteringService::UNCLUSTERABLE
			. ' WHEN ' . FaceState::IGNORED . ' THEN ' . ClusteringService::IGNORED . ' END';
		$nFaces = $nPeople = 0;
		$maxPeople = $this->count("SELECT COALESCE(MAX(id), 0) FROM {$fc}");
		for ($after = 0; $after < $maxPeople; $after += self::PAGE) {
			$upper = $after + self::PAGE;
			$range = "c.id > {$after} AND c.id <= {$upper}{$peopleUserSql}";
			$nPeople += $this->count("SELECT COUNT(*) FROM {$fc} c WHERE {$range}");
			if (!$dryRun) {
				DeadlockRetry::run(fn () => $this->db->executeStatement(
					"INSERT INTO {$rfc} (id, user_id, title) SELECT c.id, c.user_id, c.title FROM {$fc} c WHERE {$range} ORDER BY c.id "
					. 'ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), title = VALUES(title)'
				));
			}
		}
		$max = $this->count("SELECT COALESCE(MAX(id), 0) FROM {$uf}");
		for ($after = 0; $after < $max; $after += self::PAGE) {
			$upper = $after + self::PAGE;
			$range = "u.id > {$after} AND u.id <= {$upper}{$userSql}";
			$nFaces += $this->count("SELECT COUNT(*) FROM {$uf} u WHERE {$range}");
			if (!$dryRun) {
				DeadlockRetry::run(fn () => $this->db->executeStatement(
					"INSERT INTO {$rfd} (id, user_id, file_id, x, y, width, height, face_vector, cluster_id, threshold) "
					. "SELECT u.id, u.user_id, f.file_id, f.x, f.y, f.width, f.height, '[]', {$sentinel}, 0 "
					. "FROM {$uf} u INNER JOIN {$faces} f ON f.id = u.face_id WHERE {$range} ORDER BY u.id "
					. 'ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), file_id = VALUES(file_id), x = VALUES(x), y = VALUES(y), '
					. 'width = VALUES(width), height = VALUES(height), cluster_id = VALUES(cluster_id)'
				));
			}
		}
		return ['faces' => $nFaces, 'people' => $nPeople];
	}

	// ---------------------------------------------------------------------------------------------------
	// Reconcile
	// ---------------------------------------------------------------------------------------------------

	/**
	 * Compares Recognize's tables with ours, optionally repairs, and reports by category:
	 *  A  visible faces of ours that are missing from, or differ in, Recognize's table
	 *  B  rows of Recognize's table for faces that are hidden in ours (tolerated until Stage F; never touched)
	 *  C  rows with no row of ours that look like copies of ours ('[]' or a vector longer than the legacy band);
	 *     rows of Recognize's own legacy kind are counted separately and never touched
	 *  D  people missing or different in Recognize's table (missing), and people there that nothing of ours or
	 *     Recognize's references (stale)
	 * Vectors are read only for rows with no row of ours (C), never for rows we own.
	 *
	 * @param int $limit repairs per category at most; 0 = unlimited
	 * @return array{A: int, B: int, C: int, legacy: int, D_missing: int, D_stale: int, repaired: array<string,int>, truncated: bool}
	 */
	public function reconcile(bool $dryRun, ?string $userId = null, int $limit = 0): array {
		$report = ['A' => 0, 'B' => 0, 'C' => 0, 'legacy' => 0, 'D_missing' => 0, 'D_stale' => 0, 'repaired' => ['A' => 0, 'C' => 0, 'D_missing' => 0, 'D_stale' => 0], 'truncated' => false];
		$fix = ['A' => [], 'C' => [], 'D_missing' => [], 'D_stale' => []];
		$found = function (string $category, int $id) use (&$report, &$fix, $limit): void {
			$report[$category]++;
			if ($limit === 0 || count($fix[$category]) < $limit) {
				$fix[$category][] = $id;
			} else {
				$report['truncated'] = true;
			}
		};

		// People first, because the faces in A need theirs to exist.
		$this->findPeopleDrift($userId, $found);
		$this->findFaceDrift($userId, $found, $report);
		$this->findStrayRows($userId, $found, $report);

		if (!$dryRun) {
			$this->repair($report, $fix['D_missing'], $fix['A'], $fix['C'], $fix['D_stale']);
		}
		return $report;
	}

	/** @param callable(string, int): void $found */
	private function findPeopleDrift(?string $userId, callable $found): void {
		$rfd = $this->table('recognize_face_detections');
		$rfc = $this->table('recognize_face_clusters');
		$fc = $this->table('facewright_clusters');
		$cu = $this->userFilter('c', $userId);
		$ru = $this->userFilter('r', $userId);
		$max = max($this->count("SELECT COALESCE(MAX(id), 0) FROM {$fc}"), $this->count("SELECT COALESCE(MAX(id), 0) FROM {$rfc}"));
		for ($after = 0; $after < $max; $after += self::PAGE) {
			$upper = $after + self::PAGE;
			foreach ($this->column(
				"SELECT c.id FROM {$fc} c LEFT JOIN {$rfc} r ON r.id = c.id WHERE c.id > {$after} AND c.id <= {$upper}{$cu} "
				. 'AND (r.id IS NULL OR NOT (r.user_id <=> c.user_id AND r.title <=> c.title)) ORDER BY c.id'
			) as $id) {
				$found('D_missing', $id);
			}
			foreach ($this->column(
				"SELECT r.id FROM {$rfc} r LEFT JOIN {$fc} c ON c.id = r.id WHERE r.id > {$after} AND r.id <= {$upper}{$ru} "
				. "AND c.id IS NULL AND NOT EXISTS (SELECT 1 FROM {$rfd} d WHERE d.cluster_id = r.id) ORDER BY r.id"
			) as $id) {
				$found('D_stale', $id);
			}
		}
	}

	/**
	 * Walks our faces by id: A, B, and the squatters of C.
	 *
	 * @param callable(string, int): void $found
	 * @param array<string, mixed> $report
	 */
	private function findFaceDrift(?string $userId, callable $found, array &$report): void {
		$rfd = $this->table('recognize_face_detections');
		$uf = $this->table('facewright_user_faces');
		$faces = $this->table('facewright_faces');
		$visible = implode(',', FaceState::VISIBLE);
		$expected = $this->visibleClusterExpr('u');
		$uu = $this->userFilter('u', $userId);
		$max = $this->count("SELECT COALESCE(MAX(id), 0) FROM {$uf}");
		for ($after = 0; $after < $max; $after += self::PAGE) {
			$upper = $after + self::PAGE;
			foreach ($this->column(
				"SELECT u.id FROM {$uf} u INNER JOIN {$faces} f ON f.id = u.face_id LEFT JOIN {$rfd} d ON d.id = u.id "
				. "WHERE u.id > {$after} AND u.id <= {$upper}{$uu} AND u.state IN ({$visible}) AND (d.id IS NULL OR NOT ("
				. "d.user_id <=> u.user_id AND d.file_id <=> f.file_id AND d.cluster_id <=> ({$expected}) "
				. 'AND d.x <=> f.x AND d.y <=> f.y AND d.width <=> f.width AND d.height <=> f.height)) ORDER BY u.id'
			) as $id) {
				$found('A', $id);
			}
			// B: a hidden face of ours whose row in Recognize's table is the same face (a Stage D remnant).
			$report['B'] += $this->count(
				"SELECT COUNT(*) FROM {$uf} u INNER JOIN {$faces} f ON f.id = u.face_id INNER JOIN {$rfd} d ON d.id = u.id "
				. "WHERE u.id > {$after} AND u.id <= {$upper}{$uu} AND u.state NOT IN ({$visible}) AND d.user_id <=> u.user_id AND d.file_id <=> f.file_id"
			);
			// C (squatters): a row sitting on the id of a hidden face of ours but belonging to another user or file.
			// Recognize hands out ids from its own counter, which MariaDB moves past every id we push, so its next
			// row lands exactly where our next id will. A visible face overwrites it (A); a hidden one never will.
			foreach ($this->column(
				"SELECT u.id FROM {$uf} u INNER JOIN {$faces} f ON f.id = u.face_id INNER JOIN {$rfd} d ON d.id = u.id "
				. "WHERE u.id > {$after} AND u.id <= {$upper}{$uu} AND u.state NOT IN ({$visible}) AND NOT (d.user_id <=> u.user_id AND d.file_id <=> f.file_id) ORDER BY u.id"
			) as $id) {
				$found('C', $id);
			}
		}
	}

	/**
	 * C: rows of Recognize's table that have no row of ours. The vector length is read only for these.
	 *
	 * @param callable(string, int): void $found
	 * @param array<string, mixed> $report
	 */
	private function findStrayRows(?string $userId, callable $found, array &$report): void {
		$rfd = $this->table('recognize_face_detections');
		$uf = $this->table('facewright_user_faces');
		$du = $this->userFilter('d', $userId);
		$max = $this->count("SELECT COALESCE(MAX(id), 0) FROM {$rfd}");
		for ($after = 0; $after < $max; $after += self::PAGE) {
			$upper = $after + self::PAGE;
			$result = $this->db->executeQuery(
				"SELECT d.id, CHAR_LENGTH(d.face_vector) AS len FROM {$rfd} d LEFT JOIN {$uf} u ON u.id = d.id "
				. "WHERE d.id > {$after} AND d.id <= {$upper}{$du} AND u.id IS NULL ORDER BY d.id"
			);
			while (($row = $result->fetch()) !== false) {
				$len = (int)$row['len'];
				if ($len === self::STALE_EMPTY_LENGTH || $len > DetectionService::VECTOR_LENGTH_FLOOR) {
					$found('C', (int)$row['id']);
				} else {
					$report['legacy']++;
				}
			}
			$result->closeCursor();
		}
	}

	private function userFilter(string $alias, ?string $userId): string {
		return $userId === null ? '' : " AND {$alias}.user_id = " . $this->quote($userId);
	}

	/**
	 * @param array<string, mixed> $report
	 * @param list<int> $fixDm
	 * @param list<int> $fixA
	 * @param list<int> $fixC
	 * @param list<int> $fixDs
	 */
	private function repair(array &$report, array $fixDm, array $fixA, array $fixC, array $fixDs): void {
		if ($fixDm !== [] || $fixA !== []) {
			if (!$this->push($fixA, $fixDm)) {
				throw new \RuntimeException('Repair failed: ' . $this->status()['error']);
			}
			$report['repaired']['D_missing'] = count($fixDm);
			$report['repaired']['A'] = count($fixA);
		}
		$rfd = $this->table('recognize_face_detections');
		$rfc = $this->table('recognize_face_clusters');
		$uf = $this->table('facewright_user_faces');
		$fc = $this->table('facewright_clusters');
		foreach (array_chunk($fixC, self::CHUNK) as $chunk) {
			// Re-checked in the statement: only a row with no visible face of ours behind it. (A hidden face of
			// ours has no claim on a row in Recognize's table, and a row with no face of ours at all is a stale copy.)
			$visible = implode(',', FaceState::VISIBLE);
			$report['repaired']['C'] += DeadlockRetry::run(fn () => $this->db->executeStatement(
				"DELETE FROM {$rfd} WHERE id IN (" . implode(',', $chunk) . ") AND id NOT IN (SELECT id FROM {$uf} WHERE state IN ({$visible}))"
			));
		}
		foreach (array_chunk($fixDs, self::CHUNK) as $chunk) {
			$report['repaired']['D_stale'] += DeadlockRetry::run(fn () => $this->db->executeStatement(
				"DELETE FROM {$rfc} WHERE id IN (" . implode(',', $chunk) . ") AND id NOT IN (SELECT id FROM {$fc}) "
				. "AND NOT EXISTS (SELECT 1 FROM {$rfd} d WHERE d.cluster_id = {$rfc}.id)"
			));
		}
	}

	// ---------------------------------------------------------------------------------------------------

	private function quote(string $value): string {
		return (string)$this->db->getQueryBuilder()->expr()->literal($value);
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

	/** @return list<int> */
	private function column(string $sql): array {
		$result = $this->db->executeQuery($sql);
		$out = [];
		while (($value = $result->fetchOne()) !== false) {
			$out[] = (int)$value;
		}
		$result->closeCursor();
		return $out;
	}

	/**
	 * @param list<int|string> $ids
	 * @return list<int>
	 */
	private function ids(array $ids): array {
		$ids = array_values(array_unique(array_map('intval', $ids)));
		sort($ids);
		return $ids;
	}
}

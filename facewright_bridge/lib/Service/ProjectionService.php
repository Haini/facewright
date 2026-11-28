<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Service;

use OCP\IAppConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Keeps the copy that Memories reads current: `facewright_bridge_face_detections` / `_clusters`, tables nothing else
 * writes (only what Memories reads: no vector, no threshold), which the fork of Memories reads when its provider
 * setting is this app (docs/memories-fork.md, M6). Our tables win, always.
 *
 * - Sparse: only ASSIGNED and UNASSIGNED faces are copied, as the person's id or -1. Pending, unclusterable and
 *   ignored faces have no row.
 * - One write shape: INSERT ... SELECT ... ON DUPLICATE KEY UPDATE. Delete-then-insert would race when two pushes
 *   touch the same ids.
 * - Order: people that exist, then faces, then deleted people. No face ever points at a person that is gone.
 * - Called after the caller's own transaction has committed (inside one it logs, flags dirty and leaves the copy to
 *   the repair job), and it never throws to the caller: a failure sets the dirty flag and is logged; the hourly job
 *   (and `occ facewright:project`) repairs.
 * - MariaDB only, like the rest of the project's deployment.
 *
 * Reconcile compares the copy with ours: A visible faces missing or different, C rows that are not a visible face of
 * ours, D people missing, different or stale. Nothing else writes these tables, so every such row is drift.
 */
class ProjectionService {
	private const DETECTIONS = 'facewright_bridge_face_detections';
	private const PEOPLE = 'facewright_bridge_face_clusters';

	private const APP_ID = 'facewright_bridge';
	/** Faces per statement. */
	private const CHUNK = 500;
	/** Rows of the copy inspected per reconcile step. */
	private const PAGE = 5000;

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

	/**
	 * Probed once per process: MariaDB, our tables, and the columns of the copy's tables that the statements name.
	 */
	public function available(): bool {
		if ($this->available !== null) {
			return $this->available;
		}
		try {
			$ok = in_array($this->db->getDatabaseProvider(true), [IDBConnection::PLATFORM_MYSQL, IDBConnection::PLATFORM_MARIADB], true)
				&& $this->db->tableExists(self::DETECTIONS)
				&& $this->db->tableExists(self::PEOPLE)
				&& $this->db->tableExists('facewright_user_faces')
				&& $this->db->tableExists('facewright_clusters');
			if ($ok) {
				$ok = array_diff(['id', 'user_id', 'file_id', 'x', 'y', 'width', 'height', 'cluster_id'], $this->columns(self::DETECTIONS)) === []
					&& array_diff(['id', 'user_id', 'title'], $this->columns(self::PEOPLE)) === [];
			}
		} catch (\Throwable $e) {
			$this->logger->error('facewright: projection availability check failed: ' . $e->getMessage(), ['app' => self::APP_ID]);
			$ok = false;
		}
		return $this->available = $ok;
	}

	private function detections(): string {
		return $this->table(self::DETECTIONS);
	}

	private function people(): string {
		return $this->table(self::PEOPLE);
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
	 * Copies the given faces and people into the copy, from ours. Pass every id the caller touched, visible or not:
	 * an id that is no longer visible (or no longer exists) is removed from the copy.
	 *
	 * @param list<int|string> $faceIds  facewright_user_faces.id, which is also the copy's detection id
	 * @param list<int|string> $clusterIds facewright_clusters.id, which is also the copy's person id
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
		return $this->write($faceIds, $clusterIds);
	}

	/**
	 * Empties the copy (every row of both tables), for a full reset of our data. Outside any transaction; never throws:
	 * a failure sets the dirty flag and is logged, and the repair job then removes what is left.
	 */
	public function clear(): void {
		if ($this->db->inTransaction()) {
			$this->logger->error('facewright: ProjectionService::clear() was called inside a transaction; the copy is left to the repair job', ['app' => self::APP_ID, 'exception' => new \LogicException('clear inside a transaction')]);
			$this->markDirty('clear() was called inside a transaction');
			return;
		}
		if (!$this->available()) {
			$this->markDirty('the projection is not available (unsupported database, or the copy\'s tables are missing or changed)');
			return;
		}
		try {
			$this->db->executeStatement('DELETE FROM ' . $this->detections());
			$this->db->executeStatement('DELETE FROM ' . $this->people());
		} catch (\Throwable $e) {
			$this->logger->error('facewright: emptying the copy failed: ' . $e->getMessage(), ['app' => self::APP_ID, 'exception' => $e]);
			$this->markDirty($e->getMessage());
		}
	}

	/**
	 * The writes of a push. Never throws: false has set the dirty flag and logged why.
	 *
	 * @param list<int> $faceIds
	 * @param list<int> $clusterIds
	 */
	private function write(array $faceIds, array $clusterIds): bool {
		if (!$this->available()) {
			$this->markDirty('the projection is not available (unsupported database, or the copy\'s tables are missing or changed)');
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
		$fc = $this->table('facewright_clusters');
		$this->db->executeStatement(
			'INSERT INTO ' . $this->people() . " (id, user_id, title) SELECT c.id, c.user_id, c.title FROM {$fc} c WHERE c.id IN (" . implode(',', $ids) . ') ORDER BY c.id '
			. 'ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), title = VALUES(title)'
		);
	}

	/** @param list<int> $ids */
	private function deleteGonePeople(array $ids): void {
		$this->db->executeStatement(
			'DELETE FROM ' . $this->people() . ' WHERE id IN (' . implode(',', $ids) . ') '
			. 'AND id NOT IN (SELECT id FROM ' . $this->table('facewright_clusters') . ')'
		);
	}

	/** @param list<int> $ids */
	private function upsertFaces(array $ids): void {
		$det = $this->detections();
		$uf = $this->table('facewright_user_faces');
		$faces = $this->table('facewright_faces');
		$list = implode(',', $ids);
		$visible = implode(',', FaceState::VISIBLE);
		$this->db->beginTransaction();
		try {
			$this->db->executeStatement(
				"INSERT INTO {$det} (id, user_id, file_id, x, y, width, height, cluster_id) "
				. 'SELECT u.id, u.user_id, f.file_id, f.x, f.y, f.width, f.height, ' . $this->visibleClusterExpr('u') . ' '
				. "FROM {$uf} u INNER JOIN {$faces} f ON f.id = u.face_id WHERE u.id IN ({$list}) AND u.state IN ({$visible}) ORDER BY u.id "
				. 'ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), file_id = VALUES(file_id), x = VALUES(x), y = VALUES(y), '
				. 'width = VALUES(width), height = VALUES(height), cluster_id = VALUES(cluster_id)'
			);
			$this->db->executeStatement(
				"DELETE FROM {$det} WHERE id IN ({$list}) AND id NOT IN (SELECT id FROM {$uf} WHERE state IN ({$visible}))"
			);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/** What the copy's cluster_id is for a visible face: the person, or -1 for unassigned. */
	private function visibleClusterExpr(string $alias): string {
		return "CASE WHEN {$alias}.state = " . FaceState::ASSIGNED . " THEN {$alias}.cluster_id ELSE " . ClusteringService::UNASSIGNED . ' END';
	}

	// ---------------------------------------------------------------------------------------------------
	// Reconcile
	// ---------------------------------------------------------------------------------------------------

	/**
	 * Compares the copy with ours, optionally repairs, and reports by category:
	 *  A  visible faces of ours that are missing from the copy, or differ in it
	 *  C  rows of the copy that are not a visible face of ours (nothing else writes it: every such row is stale)
	 *  D  people missing or different in the copy (missing), and people there that no longer exist (stale)
	 *
	 * @param int $limit repairs per category at most; 0 = unlimited
	 * @return array{A: int, C: int, D_missing: int, D_stale: int, repaired: array<string,int>, truncated: bool}
	 */
	public function reconcile(bool $dryRun, ?string $userId = null, int $limit = 0): array {
		$report = ['A' => 0, 'C' => 0, 'D_missing' => 0, 'D_stale' => 0, 'repaired' => ['A' => 0, 'C' => 0, 'D_missing' => 0, 'D_stale' => 0], 'truncated' => false];
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
		$this->findFaceDrift($userId, $found);
		$this->findStrayRows($userId, $found);

		if (!$dryRun) {
			$this->repair($report, $fix['D_missing'], $fix['A'], $fix['C'], $fix['D_stale']);
		}
		return $report;
	}

	/** @param callable(string, int): void $found */
	private function findPeopleDrift(?string $userId, callable $found): void {
		$people = $this->people();
		$fc = $this->table('facewright_clusters');
		$cu = $this->userFilter('c', $userId);
		$ru = $this->userFilter('r', $userId);
		$max = max($this->count("SELECT COALESCE(MAX(id), 0) FROM {$fc}"), $this->count("SELECT COALESCE(MAX(id), 0) FROM {$people}"));
		for ($after = 0; $after < $max; $after += self::PAGE) {
			$upper = $after + self::PAGE;
			foreach ($this->column(
				"SELECT c.id FROM {$fc} c LEFT JOIN {$people} r ON r.id = c.id WHERE c.id > {$after} AND c.id <= {$upper}{$cu} "
				. 'AND (r.id IS NULL OR NOT (r.user_id <=> c.user_id AND r.title <=> c.title)) ORDER BY c.id'
			) as $id) {
				$found('D_missing', $id);
			}
			foreach ($this->column(
				"SELECT r.id FROM {$people} r LEFT JOIN {$fc} c ON c.id = r.id WHERE r.id > {$after} AND r.id <= {$upper}{$ru} "
				. 'AND c.id IS NULL ORDER BY r.id'
			) as $id) {
				$found('D_stale', $id);
			}
		}
	}

	/**
	 * Walks our faces by id: A.
	 *
	 * @param callable(string, int): void $found
	 */
	private function findFaceDrift(?string $userId, callable $found): void {
		$det = $this->detections();
		$uf = $this->table('facewright_user_faces');
		$faces = $this->table('facewright_faces');
		$visible = implode(',', FaceState::VISIBLE);
		$expected = $this->visibleClusterExpr('u');
		$uu = $this->userFilter('u', $userId);
		$max = $this->count("SELECT COALESCE(MAX(id), 0) FROM {$uf}");
		for ($after = 0; $after < $max; $after += self::PAGE) {
			$upper = $after + self::PAGE;
			foreach ($this->column(
				"SELECT u.id FROM {$uf} u INNER JOIN {$faces} f ON f.id = u.face_id LEFT JOIN {$det} d ON d.id = u.id "
				. "WHERE u.id > {$after} AND u.id <= {$upper}{$uu} AND u.state IN ({$visible}) AND (d.id IS NULL OR NOT ("
				. "d.user_id <=> u.user_id AND d.file_id <=> f.file_id AND d.cluster_id <=> ({$expected}) "
				. 'AND d.x <=> f.x AND d.y <=> f.y AND d.width <=> f.width AND d.height <=> f.height)) ORDER BY u.id'
			) as $id) {
				$found('A', $id);
			}
		}
	}

	/**
	 * C: every row of the copy that is not a visible face of ours.
	 *
	 * @param callable(string, int): void $found
	 */
	private function findStrayRows(?string $userId, callable $found): void {
		$det = $this->detections();
		$uf = $this->table('facewright_user_faces');
		$du = $this->userFilter('d', $userId);
		$max = $this->count("SELECT COALESCE(MAX(id), 0) FROM {$det}");
		$visible = implode(',', FaceState::VISIBLE);
		for ($after = 0; $after < $max; $after += self::PAGE) {
			$upper = $after + self::PAGE;
			foreach ($this->column(
				"SELECT d.id FROM {$det} d LEFT JOIN {$uf} u ON u.id = d.id AND u.state IN ({$visible}) "
				. "WHERE d.id > {$after} AND d.id <= {$upper}{$du} AND u.id IS NULL ORDER BY d.id"
			) as $id) {
				$found('C', $id);
			}
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
			if (!$this->write($fixA, $fixDm)) {
				throw new \RuntimeException('Repair failed: ' . $this->status()['error']);
			}
			$report['repaired']['D_missing'] = count($fixDm);
			$report['repaired']['A'] = count($fixA);
		}
		$det = $this->detections();
		$people = $this->people();
		$uf = $this->table('facewright_user_faces');
		$fc = $this->table('facewright_clusters');
		$visible = implode(',', FaceState::VISIBLE);
		foreach (array_chunk($fixC, self::CHUNK) as $chunk) {
			// Re-checked in the statement: only a row with no visible face of ours behind it.
			$report['repaired']['C'] += DeadlockRetry::run(fn () => $this->db->executeStatement(
				"DELETE FROM {$det} WHERE id IN (" . implode(',', $chunk) . ") AND id NOT IN (SELECT id FROM {$uf} WHERE state IN ({$visible}))"
			));
		}
		foreach (array_chunk($fixDs, self::CHUNK) as $chunk) {
			$report['repaired']['D_stale'] += DeadlockRetry::run(fn () => $this->db->executeStatement(
				"DELETE FROM {$people} WHERE id IN (" . implode(',', $chunk) . ") AND id NOT IN (SELECT id FROM {$fc})"
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

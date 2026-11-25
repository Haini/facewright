<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * The only writer of facewright_user_faces, facewright_clusters and facewright_cluster_stats: people and face assignments, as the source
 * of truth (docs/own-tables.md, Stage E). Recognize's tables are a copy that ProjectionService writes.
 *
 * Callers wrap these methods in their own transaction, and push what they touched to ProjectionService after it
 * has committed. Every method that changes rows returns the ids it changed; that list, not what the caller meant
 * to do, is what gets pushed, so a guard that skipped a row or a concurrent edit that won decides for the copy
 * too. Nothing here opens a transaction or reads Recognize's face_vector.
 *
 * Locking: a write that targets a person takes a shared lock on that person's row first (so a concurrent delete
 * of the person waits, and the face never lands in a person that is gone), then locks the face rows it will
 * change. Parallel moves into one person therefore do not block each other.
 *
 * The one place Recognize's table is read: emptyClusterIds() / deleteEmptyUntitled() also treat a person that
 * one of Recognize's own legacy rows (a row with no row of ours) still points at as not empty, so a person whose
 * only members are legacy rows is never deleted (docs/own-tables.md, F.3).
 */
class FaceStore {
	private const CHUNK = 1000;

	/**
	 * Which face is a person's cover: the highest detector confidence, then the largest, then the lowest id. det_score
	 * is NULL for backfilled rows, which sort last. Over facewright_user_faces `u` joined to facewright_faces `f`.
	 */
	public const COVER_ORDER = '(f.det_score IS NULL), f.det_score DESC, (f.width * f.height) DESC, u.id';

	public function __construct(
		private IDBConnection $db,
	) {
	}

	// ---------------------------------------------------------------------------------------------------
	// Faces
	// ---------------------------------------------------------------------------------------------------

	/**
	 * Adds one face for a user in state PENDING; the id comes from our own counter (seeded above Recognize's by
	 * `occ facewright:own-tables --seed-ids`).
	 */
	public function addPendingFace(string $userId, int $faceId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('facewright_user_faces')
			->values([
				'user_id' => $qb->createNamedParameter($userId),
				'face_id' => $qb->createNamedParameter($faceId, IQueryBuilder::PARAM_INT),
				'cluster_id' => $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
				'state' => $qb->createNamedParameter(FaceState::PENDING, IQueryBuilder::PARAM_INT),
			]);
		$qb->executeStatement();
		return (int)$qb->getLastInsertId();
	}

	/**
	 * Sets the state of $userId's faces among $ids, restricted to those currently in one of $onlyStates (empty =
	 * any). Pass $clusterId only for ASSIGNED, and then the person must exist for this user.
	 *
	 * "Matched" is the contract: a face that already is in the target state still counts, like the UPDATE this
	 * replaces did under FOUND_ROWS.
	 *
	 * @param list<int> $ids
	 * @param list<int> $onlyStates FaceState constants
	 * @return list<int> the ids that matched and are now ($state, $clusterId)
	 * @throws \InvalidArgumentException when $clusterId is not one of this user's people
	 */
	public function setState(string $userId, array $ids, int $state, ?int $clusterId = null, array $onlyStates = []): array {
		FaceState::toSentinel($state, $clusterId); // rejects an unknown state and an ASSIGNED face without a person
		if ($clusterId !== null && $state !== FaceState::ASSIGNED) {
			throw new \InvalidArgumentException('Only an assigned face has a person.');
		}
		if ($clusterId !== null && !$this->lockCluster($userId, $clusterId)) {
			throw new \InvalidArgumentException("Person {$clusterId} does not exist for {$userId}.");
		}
		$touched = [];
		foreach (array_chunk($this->ints($ids), self::CHUNK) as $chunk) {
			$previous = $this->lockedFaces($userId, $chunk, $onlyStates);
			if ($previous === []) {
				continue;
			}
			$matched = array_keys($previous);
			$this->db->executeStatement(
				'UPDATE ' . $this->table('facewright_user_faces') . ' SET state = ' . $state . ', cluster_id = ' . ($clusterId === null ? 'NULL' : (string)$clusterId)
				. ' WHERE id IN (' . implode(',', $matched) . ')'
			);
			array_push($touched, ...$matched);
			// Faces that changed person (a face already in $clusterId, or moving between states without one, did not).
			$changed = array_filter($previous, fn (?int $from): bool => $from !== $clusterId);
			if ($changed !== []) {
				$this->refreshPeople($userId, [...array_filter(array_values($changed)), ...($clusterId !== null ? [$clusterId] : [])],
					$clusterId !== null ? [$clusterId => array_keys($changed)] : []);
			}
		}
		return $touched;
	}

	/**
	 * Moves every face that is currently in one of these people. Used by merge (to the surviving person) and by
	 * delete (to UNASSIGNED).
	 *
	 * @param list<int> $fromClusterIds
	 * @return list<int> the faces moved
	 * @throws \InvalidArgumentException when $toCluster is not one of this user's people
	 */
	public function moveMembers(string $userId, array $fromClusterIds, int $state, ?int $toCluster = null): array {
		FaceState::toSentinel($state, $toCluster);
		$from = $this->ints($fromClusterIds);
		if ($from === []) {
			return [];
		}
		if ($toCluster !== null && !$this->lockCluster($userId, $toCluster)) {
			throw new \InvalidArgumentException("Person {$toCluster} does not exist for {$userId}.");
		}
		$result = $this->db->executeQuery(
			'SELECT id FROM ' . $this->table('facewright_user_faces') . ' WHERE user_id = ? AND state = ' . FaceState::ASSIGNED
			. ' AND cluster_id IN (' . implode(',', $from) . ') ORDER BY id FOR UPDATE',
			[$userId]
		);
		$members = [];
		while (($id = $result->fetchOne()) !== false) {
			$members[] = (int)$id;
		}
		$result->closeCursor();
		foreach (array_chunk($members, self::CHUNK) as $chunk) {
			$this->db->executeStatement(
				'UPDATE ' . $this->table('facewright_user_faces') . ' SET state = ' . $state . ', cluster_id = ' . ($toCluster === null ? 'NULL' : (string)$toCluster)
				. ' WHERE id IN (' . implode(',', $chunk) . ')'
			);
		}
		if ($members !== []) {
			$this->refreshPeople($userId, [...$from, ...($toCluster !== null ? [$toCluster] : [])],
				$toCluster !== null ? [$toCluster => $members] : []);
		}
		return $members;
	}

	/**
	 * Removes faces (and nothing else). Their links are the caller's: only the caller knows whether the physical
	 * face is shared.
	 *
	 * @param list<int> $ids
	 */
	public function deleteFaces(array $ids): void {
		$uf = $this->table('facewright_user_faces');
		foreach (array_chunk($this->ints($ids), self::CHUNK) as $chunk) {
			// The people these faces leave, whatever user they belong to (prune and reset pass no user).
			$result = $this->db->executeQuery(
				"SELECT user_id, cluster_id FROM {$uf} WHERE id IN (" . implode(',', $chunk) . ') AND cluster_id IS NOT NULL ORDER BY id FOR UPDATE'
			);
			$left = [];
			while ($row = $result->fetch()) {
				$left[(string)$row['user_id']][] = (int)$row['cluster_id'];
			}
			$result->closeCursor();
			$this->db->executeStatement("DELETE FROM {$uf} WHERE id IN (" . implode(',', $chunk) . ')');
			ksort($left);
			foreach ($left as $userId => $clusterIds) {
				$this->refreshPeople((string)$userId, $clusterIds);
			}
		}
	}

	/**
	 * Every one of $userId's faces goes back to PENDING except the ones a human ignored, and all their people are
	 * deleted. Returns what changed so the caller can push it: the faces whose state or person changed (this is
	 * how hidden remnants leave Recognize's table) and every deleted person.
	 *
	 * @return array{faces: list<int>, clusters: list<int>}
	 */
	public function resetUser(string $userId): array {
		$uf = $this->table('facewright_user_faces');
		$fc = $this->table('facewright_clusters');
		$faces = $this->column(
			"SELECT id FROM {$uf} WHERE user_id = ? AND state <> " . FaceState::IGNORED . ' AND state <> ' . FaceState::PENDING . ' ORDER BY id FOR UPDATE',
			[$userId]
		);
		$clusters = $this->column("SELECT id FROM {$fc} WHERE user_id = ? ORDER BY id FOR UPDATE", [$userId]);
		$this->db->executeStatement(
			"UPDATE {$uf} SET state = " . FaceState::PENDING . ', cluster_id = NULL WHERE user_id = ? AND state <> ' . FaceState::IGNORED,
			[$userId]
		);
		$this->db->executeStatement("DELETE FROM {$fc} WHERE user_id = ?", [$userId]);
		$this->db->executeStatement('DELETE FROM ' . $this->table('facewright_cluster_stats') . ' WHERE user_id = ?', [$userId]);
		return ['faces' => $faces, 'clusters' => $clusters];
	}

	// ---------------------------------------------------------------------------------------------------
	// People
	// ---------------------------------------------------------------------------------------------------

	/** Creates a person, with its stored size (0) and no cover; the id comes from our own counter. */
	public function allocateCluster(string $userId, string $title = ''): int {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('facewright_clusters')
			->values([
				'user_id' => $qb->createNamedParameter($userId),
				'title' => $qb->createNamedParameter($title),
			]);
		$qb->executeStatement();
		$id = (int)$qb->getLastInsertId();
		$this->db->executeStatement(
			'INSERT INTO ' . $this->table('facewright_cluster_stats') . ' (cluster_id, user_id, face_count, cover_id) VALUES (?, ?, 0, NULL)',
			[$id, $userId],
			[IQueryBuilder::PARAM_INT, IQueryBuilder::PARAM_STR]
		);
		return $id;
	}

	/** @return bool whether a person of this user was renamed */
	public function renameCluster(string $userId, int $clusterId, string $title): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->update('facewright_clusters')
			->set('title', $qb->createNamedParameter($title))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($clusterId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return $qb->executeStatement() > 0;
	}

	/**
	 * Deletes these people of $userId. The caller has already moved or reassigned their faces.
	 *
	 * @param list<int> $clusterIds
	 * @return list<int> the people that existed and are now gone
	 */
	public function deleteClusters(string $userId, array $clusterIds): array {
		$ids = $this->ints($clusterIds);
		if ($ids === []) {
			return [];
		}
		$fc = $this->table('facewright_clusters');
		$existing = $this->column("SELECT id FROM {$fc} WHERE user_id = ? AND id IN (" . implode(',', $ids) . ') ORDER BY id FOR UPDATE', [$userId]);
		if ($existing !== []) {
			$this->db->executeStatement("DELETE FROM {$fc} WHERE user_id = ? AND id IN (" . implode(',', $existing) . ')', [$userId]);
			$this->db->executeStatement('DELETE FROM ' . $this->table('facewright_cluster_stats') . ' WHERE cluster_id IN (' . implode(',', $existing) . ')');
		}
		return $existing;
	}

	/**
	 * Deletes people whatever their user; for the instance-wide reset, which has collected the ids itself.
	 *
	 * @param list<int> $clusterIds
	 */
	public function deleteClustersById(array $clusterIds): void {
		foreach (array_chunk($this->ints($clusterIds), self::CHUNK) as $chunk) {
			$this->db->executeStatement('DELETE FROM ' . $this->table('facewright_clusters') . ' WHERE id IN (' . implode(',', $chunk) . ')');
			$this->db->executeStatement('DELETE FROM ' . $this->table('facewright_cluster_stats') . ' WHERE cluster_id IN (' . implode(',', $chunk) . ')');
		}
	}

	/**
	 * Which of $userId's people have no member: no face of ours points at them and no Recognize legacy row does.
	 * Optionally restricted to $among, and to untitled ones.
	 *
	 * @param list<int>|null $among
	 * @return list<int>
	 */
	public function emptyClusterIds(string $userId, ?array $among = null, bool $untitledOnly = false): array {
		if ($among !== null && $this->ints($among) === []) {
			return [];
		}
		$fc = $this->table('facewright_clusters');
		$uf = $this->table('facewright_user_faces');
		$rfd = $this->table('recognize_face_detections');
		$sql = "SELECT c.id FROM {$fc} c WHERE c.user_id = ?"
			. ($among !== null ? ' AND c.id IN (' . implode(',', $this->ints($among)) . ')' : '')
			. ($untitledOnly ? " AND c.title = ''" : '')
			. " AND NOT EXISTS (SELECT 1 FROM {$uf} u WHERE u.user_id = c.user_id AND u.cluster_id = c.id)"
			// A row of Recognize's own that points at the person and has no row of ours: not ours to orphan.
			. " AND NOT EXISTS (SELECT 1 FROM {$rfd} d WHERE d.cluster_id = c.id AND NOT EXISTS (SELECT 1 FROM {$uf} u2 WHERE u2.id = d.id))"
			. ' ORDER BY c.id';
		return $this->column($sql, [$userId]);
	}

	/**
	 * Deletes the untitled ones among $clusterIds that have no member left. A titled person is kept even when
	 * empty: the user may be about to move faces into it.
	 *
	 * @param list<int> $clusterIds
	 * @return list<int> the people deleted
	 */
	public function deleteEmptyUntitled(string $userId, array $clusterIds): array {
		$candidates = $this->emptyClusterIds($userId, $clusterIds, true);
		return $this->deleteClusters($userId, $candidates);
	}

	/** Takes a shared lock on the person; false when it is not this user's. */
	private function lockCluster(string $userId, int $clusterId): bool {
		$found = $this->column(
			'SELECT id FROM ' . $this->table('facewright_clusters') . ' WHERE id = ? AND user_id = ? LOCK IN SHARE MODE',
			[$clusterId, $userId]
		);
		return $found !== [];
	}

	// ---------------------------------------------------------------------------------------------------

	// ---------------------------------------------------------------------------------------------------
	// Each person's size and cover (facewright_cluster_stats)
	// ---------------------------------------------------------------------------------------------------

	/**
	 * Brings the stored size and cover of these people of $userId up to date after their members changed, inside the
	 * caller's transaction. Every method above that changes which person a face is in calls this.
	 *
	 * The lock comes first: an upsert that changes nothing locks the person's row whether or not it existed yet, in id
	 * order. Nextcloud runs at READ COMMITTED, so the count that follows sees every writer that committed before us,
	 * and a writer still in flight waits for this row and counts again after us. That is why this recounts rather than
	 * adding and subtracting: whatever happened before, the stored size is the real one once a write commits.
	 *
	 * The cover is the best face (COVER_ORDER). A face joining can only replace it, so the new cover is
	 * the best of the old one and $joined; only when the cover itself left the person (or there was none) are all
	 * members sorted, which for a person of 18k faces costs about 100 ms.
	 *
	 * @param list<int> $clusterIds the people faces joined or left
	 * @param array<int, list<int>> $joined person id => the faces that just joined it
	 */
	private function refreshPeople(string $userId, array $clusterIds, array $joined = []): void {
		$ids = $this->ints($clusterIds);
		if ($ids === []) {
			return;
		}
		$cs = $this->table('facewright_cluster_stats');
		$uf = $this->table('facewright_user_faces');
		foreach (array_chunk($ids, self::CHUNK) as $chunk) {
			$list = implode(',', $chunk);
			// Only people that still exist get a row; a person deleted meanwhile is skipped.
			$this->db->executeStatement(
				"INSERT INTO {$cs} (cluster_id, user_id, face_count, cover_id) SELECT c.id, c.user_id, 0, NULL FROM "
				. $this->table('facewright_clusters') . " c WHERE c.user_id = ? AND c.id IN ({$list}) ORDER BY c.id "
				. 'ON DUPLICATE KEY UPDATE face_count = face_count',
				[$userId]
			);
			$result = $this->db->executeQuery(
				"SELECT s.cluster_id, s.cover_id, (SELECT COUNT(*) FROM {$uf} u WHERE u.user_id = s.user_id AND u.cluster_id = s.cluster_id) AS n, "
				. "(SELECT COUNT(*) FROM {$uf} u WHERE u.id = s.cover_id AND u.user_id = s.user_id AND u.cluster_id = s.cluster_id) AS cover_ok "
				. "FROM {$cs} s WHERE s.user_id = ? AND s.cluster_id IN ({$list})",
				[$userId]
			);
			$people = [];
			while ($row = $result->fetch()) {
				$people[(int)$row['cluster_id']] = ['n' => (int)$row['n'], 'cover' => (int)$row['cover_ok'] === 1 ? (int)$row['cover_id'] : null];
			}
			$result->closeCursor();

			$full = [];
			$candidates = [];
			foreach ($people as $id => $person) {
				if ($person['n'] === 0) {
					$people[$id]['cover'] = null;
				} elseif ($person['cover'] === null) {
					$full[] = $id;
				} elseif (($joined[$id] ?? []) !== []) {
					array_push($candidates, $person['cover'], ...$joined[$id]);
				}
			}
			$incremental = array_keys(array_diff_key($joined, array_flip($full)));
			foreach ($this->bestFaces($userId, $full) + $this->bestFaces($userId, $incremental, $candidates) as $id => $cover) {
				if (isset($people[$id])) {
					$people[$id]['cover'] = $cover;
				}
			}
			foreach ($people as $id => $person) {
				$this->db->executeStatement(
					"UPDATE {$cs} SET face_count = ?, cover_id = ? WHERE cluster_id = ?",
					[$person['n'], $person['cover'], $id],
					[IQueryBuilder::PARAM_INT, $person['cover'] === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT, IQueryBuilder::PARAM_INT]
				);
			}
		}
	}

	/**
	 * The best face of each of these people of $userId, by COVER_ORDER; among $amongFaces only, when
	 * given. One query per 1000 people, ROW_NUMBER() per person (QueryBuilder cannot express a window function).
	 *
	 * @param list<int> $clusterIds
	 * @param list<int>|null $amongFaces
	 * @return array<int, int> person id => face (facewright_user_faces.id)
	 */
	public function bestFaces(string $userId, array $clusterIds, ?array $amongFaces = null): array {
		$ids = $this->ints($clusterIds);
		$among = $amongFaces === null ? null : $this->ints($amongFaces);
		if ($ids === [] || $among === []) {
			return [];
		}
		$best = [];
		foreach (array_chunk($ids, self::CHUNK) as $chunk) {
			$sql = 'SELECT id, cluster_id FROM (SELECT u.id, u.cluster_id, ROW_NUMBER() OVER (PARTITION BY u.cluster_id ORDER BY '
				. self::COVER_ORDER . ') AS rn FROM ' . $this->table('facewright_user_faces') . ' u'
				. ' INNER JOIN ' . $this->table('facewright_faces') . ' f ON f.id = u.face_id'
				. ' WHERE u.user_id = ? AND u.cluster_id IN (' . implode(',', $chunk) . ')'
				. ($among !== null ? ' AND u.id IN (' . implode(',', $among) . ')' : '') . ') t WHERE rn = 1';
			$result = $this->db->executeQuery($sql, [$userId]);
			while ($row = $result->fetch()) {
				$best[(int)$row['cluster_id']] = (int)$row['id'];
			}
			$result->closeCursor();
		}
		return $best;
	}

	/**
	 * Rebuilds every stored size and cover, of one user or of all: after `own-tables --migrate` filled our tables, in
	 * the migration that created the table, and as the repair (`occ facewright:own-tables --recount`). One transaction;
	 * a writer meanwhile waits for it and then counts again itself.
	 *
	 * @return int people with a stored size afterwards
	 */
	public function rebuildPeopleStats(?string $userId = null): int {
		$cs = $this->table('facewright_cluster_stats');
		$fc = $this->table('facewright_clusters');
		$uf = $this->table('facewright_user_faces');
		$faces = $this->table('facewright_faces');
		$where = $userId === null ? '' : ' WHERE c.user_id = ?';
		$params = $userId === null ? [] : [$userId];
		$this->db->beginTransaction();
		try {
			$this->db->executeStatement("DELETE FROM {$cs}" . ($userId === null ? '' : ' WHERE user_id = ?'), $params);
			$this->db->executeStatement(
				"INSERT INTO {$cs} (cluster_id, user_id, face_count, cover_id) SELECT c.id, c.user_id, COUNT(u.id), NULL FROM {$fc} c "
				. "LEFT JOIN {$uf} u ON u.user_id = c.user_id AND u.cluster_id = c.id{$where} GROUP BY c.id, c.user_id ORDER BY c.id",
				$params
			);
			$this->db->executeStatement(
				"UPDATE {$cs} s INNER JOIN (SELECT id, cluster_id FROM (SELECT u.id, u.cluster_id, ROW_NUMBER() OVER (PARTITION BY u.cluster_id ORDER BY "
				. self::COVER_ORDER . ") AS rn FROM {$uf} u INNER JOIN {$faces} f ON f.id = u.face_id WHERE u.cluster_id IS NOT NULL"
				. ($userId === null ? '' : ' AND u.user_id = ?') . ') t WHERE rn = 1) b ON b.cluster_id = s.cluster_id SET s.cover_id = b.id',
				$params
			);
			$count = (int)$this->db->executeQuery("SELECT COUNT(*) FROM {$cs}" . ($userId === null ? '' : ' WHERE user_id = ?'), $params)->fetchOne();
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return $count;
	}

	// ---------------------------------------------------------------------------------------------------

	/**
	 * @param list<int> $ids
	 * @param list<int> $onlyStates
	 * @return array<int, ?int> the faces among $ids that are $userId's and in one of $onlyStates, locked for update,
	 *     each with the person it is in now
	 */
	private function lockedFaces(string $userId, array $ids, array $onlyStates): array {
		$states = $this->ints($onlyStates);
		$result = $this->db->executeQuery(
			'SELECT id, cluster_id FROM ' . $this->table('facewright_user_faces') . ' WHERE user_id = ? AND id IN (' . implode(',', $ids) . ')'
			. ($states !== [] ? ' AND state IN (' . implode(',', $states) . ')' : '') . ' ORDER BY id FOR UPDATE',
			[$userId]
		);
		$faces = [];
		while ($row = $result->fetch()) {
			$faces[(int)$row['id']] = $row['cluster_id'] === null ? null : (int)$row['cluster_id'];
		}
		$result->closeCursor();
		return $faces;
	}

	/**
	 * @param list<mixed> $params
	 * @return list<int>
	 */
	private function column(string $sql, array $params = []): array {
		$result = $this->db->executeQuery($sql, $params);
		$out = [];
		while (($value = $result->fetchOne()) !== false) {
			$out[] = (int)$value;
		}
		$result->closeCursor();
		return $out;
	}

	private function table(string $name): string {
		return $this->db->getQueryBuilder()->getTableName($name);
	}

	/**
	 * @param list<int|string> $ids
	 * @return list<int>
	 */
	private function ints(array $ids): array {
		$ids = array_values(array_unique(array_map('intval', $ids)));
		sort($ids);
		return $ids;
	}
}

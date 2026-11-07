<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * The only writer of facewright_user_faces and facewright_clusters: people and face assignments, as the source
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
			$matched = $this->lockedFaceIds($userId, $chunk, $onlyStates);
			if ($matched === []) {
				continue;
			}
			$this->db->executeStatement(
				'UPDATE ' . $this->table('facewright_user_faces') . ' SET state = ' . $state . ', cluster_id = ' . ($clusterId === null ? 'NULL' : (string)$clusterId)
				. ' WHERE id IN (' . implode(',', $matched) . ')'
			);
			array_push($touched, ...$matched);
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
		return $members;
	}

	/**
	 * Removes faces (and nothing else). Their links are the caller's: only the caller knows whether the physical
	 * face is shared.
	 *
	 * @param list<int> $ids
	 */
	public function deleteFaces(array $ids): void {
		foreach (array_chunk($this->ints($ids), self::CHUNK) as $chunk) {
			$this->db->executeStatement(
				'DELETE FROM ' . $this->table('facewright_user_faces') . ' WHERE id IN (' . implode(',', $chunk) . ')'
			);
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
		return ['faces' => $faces, 'clusters' => $clusters];
	}

	// ---------------------------------------------------------------------------------------------------
	// People
	// ---------------------------------------------------------------------------------------------------

	/** Creates a person; the id comes from our own counter. */
	public function allocateCluster(string $userId, string $title = ''): int {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('facewright_clusters')
			->values([
				'user_id' => $qb->createNamedParameter($userId),
				'title' => $qb->createNamedParameter($title),
			]);
		$qb->executeStatement();
		return (int)$qb->getLastInsertId();
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

	/**
	 * @param list<int> $ids
	 * @param list<int> $onlyStates
	 * @return list<int> the ids among $ids that are $userId's and in one of $onlyStates, locked for update
	 */
	private function lockedFaceIds(string $userId, array $ids, array $onlyStates): array {
		$states = $this->ints($onlyStates);
		return $this->column(
			'SELECT id FROM ' . $this->table('facewright_user_faces') . ' WHERE user_id = ? AND id IN (' . implode(',', $ids) . ')'
			. ($states !== [] ? ' AND state IN (' . implode(',', $states) . ')' : '') . ' ORDER BY id FOR UPDATE',
			[$userId]
		);
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

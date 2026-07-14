<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Merges two face clusters that turned out to be the same real person into one - something
 * Recognize's own DAV rename endpoint explicitly refuses to do
 * (recognize/lib/Dav/Faces/FaceRoot.php::setName() throws Forbidden on a duplicate name rather
 * than treating it as a merge signal). Facewright already writes into these tables directly, so
 * it can do the actual merge Recognize's UI has no path to at all.
 *
 * Deliberately *not* dimension-scoped the way ClusteringService's matching is: merging is a
 * human judgement about identity, not a computed distance, so a legacy 128-dim cluster and a
 * 512-dim Facewright cluster can validly be the same person and get merged - the "never mix
 * embedding spaces" rule is specifically about distance math, which a merge never does.
 */
class ClusterMergeService {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * Reassigns every detection in $mergeId to $keepId, then deletes the now-empty $mergeId
	 * cluster row. Both clusters must belong to $userId - clusters are private per user, so
	 * merging across users would leak one person's cluster into another's rather than just being
	 * a harmless no-op, and is refused outright instead.
	 *
	 * @return int number of face detections moved
	 * @throws \InvalidArgumentException if either cluster doesn't exist, they don't belong to
	 *     $userId, or they're the same cluster
	 */
	public function merge(string $userId, int $keepId, int $mergeId): int {
		if ($keepId === $mergeId) {
			throw new \InvalidArgumentException('Cannot merge a cluster into itself.');
		}

		$keepOwner = $this->ownerOf($keepId);
		if ($keepOwner === null) {
			throw new \InvalidArgumentException("Cluster {$keepId} does not exist.");
		}
		$mergeOwner = $this->ownerOf($mergeId);
		if ($mergeOwner === null) {
			throw new \InvalidArgumentException("Cluster {$mergeId} does not exist.");
		}
		if ($keepOwner !== $userId || $mergeOwner !== $userId) {
			throw new \InvalidArgumentException("Both clusters must belong to {$userId}.");
		}

		$qb = $this->db->getQueryBuilder();
		$qb->update('recognize_face_detections')
			->set('cluster_id', $qb->createNamedParameter($keepId, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('cluster_id', $qb->createNamedParameter($mergeId, IQueryBuilder::PARAM_INT)));
		$moved = $qb->executeStatement();

		$qb = $this->db->getQueryBuilder();
		$qb->delete('recognize_face_clusters')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($mergeId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();

		return $moved;
	}

	private function ownerOf(int $clusterId): ?string {
		$qb = $this->db->getQueryBuilder();
		$qb->select('user_id')
			->from('recognize_face_clusters')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($clusterId, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetchAssociative();
		return $row === false ? null : $row['user_id'];
	}
}

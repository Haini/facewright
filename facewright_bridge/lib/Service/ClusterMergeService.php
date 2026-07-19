<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Merges two face clusters that turned out to be the same real person into one - something
 * Recognize's own DAV rename endpoint explicitly refuses to do
 * (recognize/lib/Dav/Faces/FaceRoot.php::setName() throws Forbidden on a duplicate name rather
 * than treating it as a merge signal - confirmed live: renaming an unnamed cluster to an
 * already-used name throws Sabre\DAV\Exception\Forbidden("Not allowed to create duplicate
 * names"), which is exactly what happens from Memories when a user renames a newly-detected
 * cluster to an existing person's name expecting it to merge them). Facewright already writes
 * into these tables directly, so it can do the actual merge Recognize's UI has no path to at all.
 *
 * Deliberately *not* dimension-scoped the way ClusteringService's matching is: merging is a
 * human judgement about identity, not a computed distance, so a legacy 128-dim cluster and a
 * 512-dim Facewright cluster can validly be the same person and get merged - the "never mix
 * embedding spaces" rule is specifically about distance math, which a merge never does.
 *
 * Accepts either a cluster's numeric ID or its name for both sides - nobody has cluster IDs
 * memorized, but they do know the name they want to keep. A purely numeric input is always
 * treated as an ID rather than a name lookup: an unnamed Facewright cluster's "name" (as shown by
 * Memories/Recognize) is exactly its numeric ID, and real titles are never purely numeric anyway
 * (Recognize's own FacesHome::createDirectory() refuses to create one), so there's no ambiguity.
 */
class ClusterMergeService {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * @return int number of face detections moved
	 * @throws \InvalidArgumentException if either cluster can't be resolved for $userId, or they
	 *     resolve to the same cluster
	 */
	public function merge(string $userId, string $keep, string $merge): int {
		$keepId = $this->resolve($userId, $keep);
		$mergeId = $this->resolve($userId, $merge);

		if ($keepId === $mergeId) {
			throw new \InvalidArgumentException('Cannot merge a cluster into itself.');
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

	/**
	 * @throws \InvalidArgumentException if $idOrName doesn't resolve to exactly one of $userId's
	 *     clusters
	 */
	private function resolve(string $userId, string $idOrName): int {
		if (preg_match('/^[0-9]+$/', $idOrName) === 1) {
			$id = (int)$idOrName;
			if ($this->ownerOf($id) !== $userId) {
				throw new \InvalidArgumentException("Cluster {$id} does not exist for {$userId}.");
			}
			return $id;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('recognize_face_clusters')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('title', $qb->createNamedParameter($idOrName)));
		$rows = $qb->executeQuery()->fetchAll();

		if ($rows === []) {
			throw new \InvalidArgumentException("No person named \"{$idOrName}\" found for {$userId}.");
		}
		if (count($rows) > 1) {
			throw new \InvalidArgumentException("\"{$idOrName}\" is ambiguous - more than one cluster has that name. Use the numeric cluster ID instead.");
		}

		return (int)$rows[0]['id'];
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

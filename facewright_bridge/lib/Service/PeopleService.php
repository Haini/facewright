<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * The one service that owns every people-level operation on our clusters: list, create, rename,
 * merge, delete a person; list, move, detach a face. Written as the seam Stage 0 of
 * docs/independence-implementation.md exists to create - the OCS controller in this stage and the
 * DAV shim in Stage 2 are both thin callers of this, so there is exactly one place that knows the
 * table shape.
 *
 * Every method takes $userId first and scopes every query to it - there is no method here that
 * can read or write another user's clusters. Bad input (an id/title that doesn't resolve, or is
 * invalid) is reported as \InvalidArgumentException with a user-readable message; callers map
 * that to their own transport (400 for the OCS controller, Sabre exceptions for the DAV shim).
 *
 * No method here ever selects face_vector.
 */
class PeopleService {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	/** @return list<array{id: int, title: string, count: int}> */
	public function listClusters(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('c.id', 'c.title')
			->selectAlias($qb->func()->count('d.id'), 'c')
			->from('recognize_face_clusters', 'c')
			->leftJoin('c', 'recognize_face_detections', 'd', $qb->expr()->eq('d.cluster_id', 'c.id'))
			->where($qb->expr()->eq('c.user_id', $qb->createNamedParameter($userId)))
			->groupBy('c.id', 'c.title')
			// Named clusters first, then largest first, then stable by id - matches Recognize's
			// own FacesHome::getChildren() ordering closely enough for Memories' list to look
			// sane without depending on it.
			->orderBy($qb->createFunction("(c.title <> '')"), 'DESC')
			->addOrderBy('c', 'DESC')
			->addOrderBy('c.id', 'ASC');

		$clusters = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$clusters[] = ['id' => (int)$row['id'], 'title' => (string)$row['title'], 'count' => (int)$row['c']];
		}
		$result->closeCursor();
		return $clusters;
	}

	/** @return array{id: int, title: string} */
	public function getCluster(string $userId, int $clusterId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'title')
			->from('recognize_face_clusters')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($clusterId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$row = $qb->executeQuery()->fetchAssociative();
		if ($row === false) {
			throw new \InvalidArgumentException("Person {$clusterId} does not exist for {$userId}.");
		}
		return ['id' => (int)$row['id'], 'title' => (string)$row['title']];
	}

	/**
	 * Numeric input is always an id (an untitled cluster is displayed by its id, and a real title
	 * can never be purely numeric - see createCluster()); anything else is an exact title match.
	 *
	 * @throws \InvalidArgumentException if $idOrTitle doesn't resolve to exactly one of $userId's
	 *     clusters
	 */
	public function resolveCluster(string $userId, string $idOrTitle): int {
		if (preg_match('/^[0-9]+$/', $idOrTitle) === 1) {
			return $this->getCluster($userId, (int)$idOrTitle)['id'];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('recognize_face_clusters')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('title', $qb->createNamedParameter($idOrTitle)));
		$rows = $qb->executeQuery()->fetchAll();

		if ($rows === []) {
			throw new \InvalidArgumentException("No person named \"{$idOrTitle}\" found for {$userId}.");
		}
		if (count($rows) > 1) {
			throw new \InvalidArgumentException("\"{$idOrTitle}\" is ambiguous - more than one person has that name. Use the numeric id instead.");
		}
		return (int)$rows[0]['id'];
	}

	/** @return list<array{id: int, file_id: int, x: float, y: float, width: float, height: float}> */
	public function listFaces(string $userId, int $clusterId): array {
		return $this->listFacesByClusterId($userId, $clusterId);
	}

	/** @return list<array{id: int, file_id: int, x: float, y: float, width: float, height: float}> */
	public function listUnassignedFaces(string $userId): array {
		return $this->listFacesByClusterId($userId, ClusteringService::UNASSIGNED);
	}

	/** @return list<array{id: int, file_id: int, x: float, y: float, width: float, height: float}> */
	private function listFacesByClusterId(string $userId, int $clusterId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'file_id', 'x', 'y', 'width', 'height')
			->from('recognize_face_detections')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('cluster_id', $qb->createNamedParameter($clusterId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');

		$faces = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$faces[] = [
				'id' => (int)$row['id'],
				'file_id' => (int)$row['file_id'],
				'x' => (float)$row['x'],
				'y' => (float)$row['y'],
				'width' => (float)$row['width'],
				'height' => (float)$row['height'],
			];
		}
		$result->closeCursor();
		return $faces;
	}

	/**
	 * Backs the `nc:face-detections` DAV property (Stage 2) - every one of $userId's own faces on
	 * one file, with the person title if assigned. Restricted to ownRowsExpr() so a legacy
	 * 128-dim row never reaches a UI through us.
	 *
	 * @return list<array{id: int, x: float, y: float, width: float, height: float, cluster_id: ?int, title: ?string}>
	 */
	public function facesForFile(string $userId, int $fileId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('d.id', 'd.x', 'd.y', 'd.width', 'd.height', 'd.cluster_id', 'c.title')
			->from('recognize_face_detections', 'd')
			->leftJoin('d', 'recognize_face_clusters', 'c', $qb->expr()->eq('d.cluster_id', 'c.id'))
			->where($qb->expr()->eq('d.user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('d.file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere(DetectionService::ownRowsExpr($qb))
			->orderBy('d.id', 'ASC');

		$faces = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$faces[] = [
				'id' => (int)$row['id'],
				'x' => (float)$row['x'],
				'y' => (float)$row['y'],
				'width' => (float)$row['width'],
				'height' => (float)$row['height'],
				'cluster_id' => $row['cluster_id'] !== null ? (int)$row['cluster_id'] : null,
				'title' => $row['title'] !== null ? (string)$row['title'] : null,
			];
		}
		$result->closeCursor();
		return $faces;
	}

	/** @throws \InvalidArgumentException on an empty, purely numeric, or already-used title */
	public function createCluster(string $userId, string $title): int {
		$this->assertValidTitle($userId, $title, null);

		$qb = $this->db->getQueryBuilder();
		$qb->insert('recognize_face_clusters')
			->values([
				'title' => $qb->createNamedParameter($title),
				'user_id' => $qb->createNamedParameter($userId),
			]);
		$qb->executeStatement();
		return $qb->getLastInsertId();
	}

	/** @throws \InvalidArgumentException if $clusterId doesn't exist for $userId, or $title is invalid */
	public function renameCluster(string $userId, int $clusterId, string $title): void {
		$this->getCluster($userId, $clusterId); // throws if not $userId's
		$this->assertValidTitle($userId, $title, $clusterId);

		$qb = $this->db->getQueryBuilder();
		$qb->update('recognize_face_clusters')
			->set('title', $qb->createNamedParameter($title))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($clusterId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}

	/**
	 * @throws \InvalidArgumentException an empty title, a purely numeric one (ambiguous with an
	 *     untitled cluster's own id-as-name), or one already used by another of $userId's clusters
	 */
	private function assertValidTitle(string $userId, string $title, ?int $excludingClusterId): void {
		if ($title === '') {
			throw new \InvalidArgumentException('A person\'s name cannot be empty.');
		}
		if (preg_match('/^[0-9]+$/', $title) === 1) {
			throw new \InvalidArgumentException('A person\'s name cannot be purely numeric.');
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('recognize_face_clusters')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('title', $qb->createNamedParameter($title)));
		if ($excludingClusterId !== null) {
			$qb->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($excludingClusterId, IQueryBuilder::PARAM_INT)));
		}
		if ($qb->executeQuery()->fetchOne() !== false) {
			throw new \InvalidArgumentException("A person named \"{$title}\" already exists; merge instead.");
		}
	}

	/**
	 * Merges two of $userId's clusters that turned out to be the same real person - something
	 * Recognize's own DAV rename endpoint has no path to at all (it throws Forbidden on a
	 * duplicate title rather than treating it as a merge signal; see renameCluster()/
	 * assertValidTitle()). Deliberately not dimension-scoped: merging is a human judgement about
	 * identity, not a computed distance, so a legacy 128-dim cluster and one of ours can validly
	 * be the same person.
	 *
	 * @return array{keepId: int, mergeId: int, moved: int}
	 * @throws \InvalidArgumentException if either side can't be resolved for $userId, or they
	 *     resolve to the same cluster
	 */
	public function mergeClusters(string $userId, string $keep, string $merge, bool $dryRun = false): array {
		$keepId = $this->resolveCluster($userId, $keep);
		$mergeId = $this->resolveCluster($userId, $merge);

		if ($keepId === $mergeId) {
			throw new \InvalidArgumentException('Cannot merge a person into themselves.');
		}

		if ($dryRun) {
			$qb = $this->db->getQueryBuilder();
			$qb->select($qb->func()->count('*'))
				->from('recognize_face_detections')
				->where($qb->expr()->eq('cluster_id', $qb->createNamedParameter($mergeId, IQueryBuilder::PARAM_INT)));
			$moved = (int)$qb->executeQuery()->fetchOne();
			return ['keepId' => $keepId, 'mergeId' => $mergeId, 'moved' => $moved];
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

		return ['keepId' => $keepId, 'mergeId' => $mergeId, 'moved' => $moved];
	}

	/**
	 * Unassigns every member (cluster_id = -1) and deletes the cluster - byte-for-byte what
	 * Recognize's own FaceClusterMapper::delete() does, on purpose: both write paths must agree
	 * until Stage 2 removes one of them.
	 *
	 * @return int members unassigned
	 * @throws \InvalidArgumentException if $clusterId doesn't exist for $userId
	 */
	public function deleteCluster(string $userId, int $clusterId): int {
		$this->getCluster($userId, $clusterId); // throws if not $userId's

		$qb = $this->db->getQueryBuilder();
		$qb->update('recognize_face_detections')
			->set('cluster_id', $qb->createNamedParameter(ClusteringService::UNASSIGNED, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('cluster_id', $qb->createNamedParameter($clusterId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$unassigned = $qb->executeStatement();

		$qb = $this->db->getQueryBuilder();
		$qb->delete('recognize_face_clusters')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($clusterId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();

		return $unassigned;
	}

	/** @throws \InvalidArgumentException if $detectionId doesn't belong to $userId or isn't in a real cluster */
	public function detachFace(string $userId, int $detectionId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('recognize_face_detections')
			->set('cluster_id', $qb->createNamedParameter(ClusteringService::UNASSIGNED, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($detectionId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->gt('cluster_id', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		if ($qb->executeStatement() === 0) {
			throw new \InvalidArgumentException("Face {$detectionId} does not exist for {$userId}, or is not currently assigned to a person.");
		}
	}

	/**
	 * Moves a face into a cluster - accepts a face currently unassigned, unclusterable, still
	 * pending, or already in a different cluster.
	 *
	 * @throws \InvalidArgumentException if $targetClusterId doesn't belong to $userId, or
	 *     $detectionId doesn't belong to $userId
	 */
	public function moveFace(string $userId, int $detectionId, int $targetClusterId): void {
		$this->getCluster($userId, $targetClusterId); // throws if not $userId's

		$qb = $this->db->getQueryBuilder();
		$qb->update('recognize_face_detections')
			->set('cluster_id', $qb->createNamedParameter($targetClusterId, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($detectionId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		if ($qb->executeStatement() === 0) {
			throw new \InvalidArgumentException("Face {$detectionId} does not exist for {$userId}.");
		}
	}

	/**
	 * Deletes every one of $userId's clusters with no remaining members. Not called
	 * automatically anywhere yet - exposed for `occ facewright:people --delete-empty` and for a
	 * future PruneService cleanup pass (CLAUDE.md Open item 1).
	 *
	 * @return int clusters deleted
	 */
	public function deleteEmptyClusters(string $userId): int {
		$sub = $this->db->getQueryBuilder();
		$sub->select($sub->expr()->literal(1))
			->from('recognize_face_detections')
			->where($sub->expr()->eq('cluster_id', $sub->createFunction('c.id')));

		$qb = $this->db->getQueryBuilder();
		$qb->delete('recognize_face_clusters', 'c')
			->where($qb->expr()->eq('c.user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->createFunction('NOT EXISTS (' . $sub->getSQL() . ')'));
		return $qb->executeStatement();
	}
}

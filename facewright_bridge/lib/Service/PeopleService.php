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

	/**
	 * Named people are always listed; $minCount only hides untitled ones with fewer faces, so a
	 * named person with one face can't become unreachable. $limit null = everything (the CLI).
	 *
	 * @return list<array{id: int, title: string, count: int, cover?: ?int}>
	 */
	public function listClusters(string $userId, ?int $limit = null, int $offset = 0, int $minCount = 0, string $search = '', bool $withCovers = false): array {
		$qb = $this->clusterListQuery($userId, $minCount, $search);
		$qb->select('c.id', 'c.title')
			->selectAlias($qb->func()->count('d.id'), 'c')
			// Named clusters first, then largest first, then stable by id - matches Recognize's
			// own FacesHome::getChildren() ordering closely enough for Memories' list to look
			// sane without depending on it.
			->orderBy($qb->createFunction("(c.title <> '')"), 'DESC')
			->addOrderBy('c', 'DESC')
			->addOrderBy('c.id', 'ASC')
			->setFirstResult($offset);
		if ($limit !== null) {
			$qb->setMaxResults($limit);
		}

		$clusters = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$clusters[] = ['id' => (int)$row['id'], 'title' => (string)$row['title'], 'count' => (int)$row['c']];
		}
		$result->closeCursor();

		if ($withCovers) {
			$covers = $this->coversFor($userId, array_column($clusters, 'id'));
			foreach ($clusters as &$cluster) {
				$cluster['cover'] = $covers[$cluster['id']] ?? null;
			}
			unset($cluster);
		}
		return $clusters;
	}

	/** How many people listClusters() would return with the same filters and no paging. */
	public function countClusters(string $userId, int $minCount = 0, string $search = ''): int {
		$qb = $this->clusterListQuery($userId, $minCount, $search);
		$qb->select('c.id');
		// A derived table is the only way to count groups; QueryBuilder has no way to express it.
		$result = $this->db->executeQuery('SELECT COUNT(*) FROM (' . $qb->getSQL() . ') t', $qb->getParameters(), $qb->getParameterTypes());
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	private function clusterListQuery(string $userId, int $minCount, string $search): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();
		$qb->from('recognize_face_clusters', 'c')
			->leftJoin('c', 'recognize_face_detections', 'd', $qb->expr()->eq('d.cluster_id', 'c.id'))
			->where($qb->expr()->eq('c.user_id', $qb->createNamedParameter($userId)))
			->groupBy('c.id', 'c.title');
		if ($search !== '') {
			$qb->andWhere($qb->expr()->iLike('c.title', $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($search) . '%')));
		}
		if ($minCount > 0) {
			$qb->having($qb->expr()->orX(
				$qb->expr()->gte($qb->createFunction('COUNT(d.id)'), $qb->createNamedParameter($minCount, IQueryBuilder::PARAM_INT)),
				$qb->expr()->neq('c.title', $qb->createNamedParameter('')),
			));
		}
		return $qb;
	}

	/**
	 * Each cluster's cover: its best-scoring face (highest detector confidence, then largest),
	 * one query per cluster on the page. det_score is NULL for backfilled rows, which sort last.
	 *
	 * @param list<int> $clusterIds
	 * @return array<int, int> cluster id => detection id
	 */
	private function coversFor(string $userId, array $clusterIds): array {
		$covers = [];
		foreach ($clusterIds as $clusterId) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('d.id')
				->from('recognize_face_detections', 'd')
				->innerJoin('d', 'facewright_detection_faces', 'l', $qb->expr()->eq('l.detection_id', 'd.id'))
				->innerJoin('l', 'facewright_faces', 'f', $qb->expr()->eq('f.id', 'l.face_id'))
				->where($qb->expr()->eq('d.user_id', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->eq('d.cluster_id', $qb->createNamedParameter($clusterId, IQueryBuilder::PARAM_INT)))
				->orderBy($qb->createFunction('(f.det_score IS NULL)'), 'ASC')
				->addOrderBy('f.det_score', 'DESC')
				->addOrderBy($qb->createFunction('(f.width * f.height)'), 'DESC')
				->addOrderBy('d.id', 'ASC')
				->setMaxResults(1);
			$id = $qb->executeQuery()->fetchOne();
			if ($id !== false) {
				$covers[$clusterId] = (int)$id;
			}
		}
		return $covers;
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
	public function listFaces(string $userId, int $clusterId, ?int $limit = null, int $offset = 0): array {
		return $this->listFacesByClusterId($userId, $clusterId, $limit, $offset);
	}

	/** @return list<array{id: int, file_id: int, x: float, y: float, width: float, height: float}> */
	public function listUnassignedFaces(string $userId, ?int $limit = null, int $offset = 0): array {
		return $this->listFacesByClusterId($userId, ClusteringService::UNASSIGNED, $limit, $offset);
	}

	/** @return list<array{id: int, file_id: int, x: float, y: float, width: float, height: float}> */
	public function listIgnoredFaces(string $userId, ?int $limit = null, int $offset = 0): array {
		return $this->listFacesByClusterId($userId, ClusteringService::IGNORED, $limit, $offset);
	}

	/** Faces at $clusterId, which may be a real cluster or one of ClusteringService's sentinels. */
	public function countFaces(string $userId, int $clusterId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from('recognize_face_detections')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('cluster_id', $qb->createNamedParameter($clusterId, IQueryBuilder::PARAM_INT)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	/** @return list<array{id: int, file_id: int, x: float, y: float, width: float, height: float}> */
	private function listFacesByClusterId(string $userId, int $clusterId, ?int $limit, int $offset): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'file_id', 'x', 'y', 'width', 'height')
			->from('recognize_face_detections')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('cluster_id', $qb->createNamedParameter($clusterId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC')
			->setFirstResult($offset);
		if ($limit !== null) {
			$qb->setMaxResults($limit);
		}

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
			->andWhere(DetectionService::ownRowsExpr($qb, 'd'))
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
		$result = $this->mergeMany($userId, $keepId, [$mergeId], $dryRun);
		return ['keepId' => $keepId, 'mergeId' => $mergeId, 'moved' => $result['moved']];
	}

	/**
	 * Merges several of $userId's clusters into $keepId (whose title survives). All-or-nothing:
	 * every id is resolved before anything is written, and the move and the deletes share one
	 * transaction.
	 *
	 * @param list<int> $mergeIds
	 * @return array{keepId: int, merged: list<int>, moved: int}
	 * @throws \InvalidArgumentException if any id isn't $userId's, $mergeIds is empty, or it
	 *     contains $keepId
	 */
	public function mergeMany(string $userId, int $keepId, array $mergeIds, bool $dryRun = false): array {
		$mergeIds = array_values(array_unique($mergeIds));
		if ($mergeIds === []) {
			throw new \InvalidArgumentException('Pick at least one person to merge.');
		}
		if (in_array($keepId, $mergeIds, true)) {
			throw new \InvalidArgumentException('Cannot merge a person into themselves.');
		}
		$this->getCluster($userId, $keepId);
		foreach ($mergeIds as $id) {
			$this->getCluster($userId, $id);
		}

		if ($dryRun) {
			$qb = $this->db->getQueryBuilder();
			$qb->select($qb->func()->count('*'))
				->from('recognize_face_detections')
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->in('cluster_id', $qb->createNamedParameter($mergeIds, IQueryBuilder::PARAM_INT_ARRAY)));
			return ['keepId' => $keepId, 'merged' => $mergeIds, 'moved' => (int)$qb->executeQuery()->fetchOne()];
		}

		$this->db->beginTransaction();
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->update('recognize_face_detections')
				->set('cluster_id', $qb->createNamedParameter($keepId, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->in('cluster_id', $qb->createNamedParameter($mergeIds, IQueryBuilder::PARAM_INT_ARRAY)));
			$moved = $qb->executeStatement();

			$qb = $this->db->getQueryBuilder();
			$qb->delete('recognize_face_clusters')
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->in('id', $qb->createNamedParameter($mergeIds, IQueryBuilder::PARAM_INT_ARRAY)));
			$qb->executeStatement();
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		return ['keepId' => $keepId, 'merged' => $mergeIds, 'moved' => $moved];
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
		return $this->deleteMany($userId, [$clusterId]);
	}

	/**
	 * deleteCluster() for several people at once, all-or-nothing.
	 *
	 * @param list<int> $clusterIds
	 * @return int members unassigned
	 * @throws \InvalidArgumentException if any id doesn't exist for $userId
	 */
	public function deleteMany(string $userId, array $clusterIds): int {
		$clusterIds = array_values(array_unique($clusterIds));
		foreach ($clusterIds as $id) {
			$this->getCluster($userId, $id);
		}
		if ($clusterIds === []) {
			return 0;
		}

		$this->db->beginTransaction();
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->update('recognize_face_detections')
				->set('cluster_id', $qb->createNamedParameter(ClusteringService::UNASSIGNED, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->in('cluster_id', $qb->createNamedParameter($clusterIds, IQueryBuilder::PARAM_INT_ARRAY)));
			$unassigned = $qb->executeStatement();

			$qb = $this->db->getQueryBuilder();
			$qb->delete('recognize_face_clusters')
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->in('id', $qb->createNamedParameter($clusterIds, IQueryBuilder::PARAM_INT_ARRAY)));
			$qb->executeStatement();
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		return $unassigned;
	}

	/** @throws \InvalidArgumentException if $detectionId doesn't belong to $userId or isn't in a real cluster */
	public function detachFace(string $userId, int $detectionId): void {
		if ($this->detachFaces($userId, [$detectionId]) === 0) {
			throw new \InvalidArgumentException("Face {$detectionId} does not exist for {$userId}, or is not currently assigned to a person.");
		}
	}

	/**
	 * Takes faces out of their people (cluster_id = -1). Faces that aren't in a real cluster are
	 * left as they are and not counted; untitled people left empty are deleted.
	 *
	 * @param list<int> $detectionIds
	 * @return int faces detached
	 */
	public function detachFaces(string $userId, array $detectionIds): int {
		return $this->setFacesCluster($userId, $detectionIds, ClusteringService::UNASSIGNED, null, true);
	}

	/**
	 * Moves a face into a cluster - accepts a face currently unassigned, unclusterable, still
	 * pending, or already in a different cluster.
	 *
	 * @throws \InvalidArgumentException if $targetClusterId doesn't belong to $userId, or
	 *     $detectionId doesn't belong to $userId
	 */
	public function moveFace(string $userId, int $detectionId, int $targetClusterId): void {
		if ($this->moveFaces($userId, [$detectionId], $targetClusterId) === 0) {
			throw new \InvalidArgumentException("Face {$detectionId} does not exist for {$userId}.");
		}
	}

	/**
	 * Moves faces into one of $userId's clusters from any state, including unassigned and
	 * ignored; untitled people left empty are deleted.
	 *
	 * @param list<int> $detectionIds
	 * @return int faces moved
	 * @throws \InvalidArgumentException if $targetClusterId doesn't belong to $userId
	 */
	public function moveFaces(string $userId, array $detectionIds, int $targetClusterId): int {
		$this->getCluster($userId, $targetClusterId); // throws if not $userId's
		return $this->setFacesCluster($userId, $detectionIds, $targetClusterId, null);
	}

	/**
	 * Creates a person and moves the faces into it in one transaction.
	 *
	 * @param list<int> $detectionIds
	 * @return array{clusterId: int, moved: int}
	 * @throws \InvalidArgumentException on an invalid or already-used title
	 */
	public function moveFacesToNewPerson(string $userId, array $detectionIds, string $title): array {
		$this->db->beginTransaction();
		try {
			$clusterId = $this->createCluster($userId, $title);
			$moved = $this->moveFaces($userId, $detectionIds, $clusterId);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return ['clusterId' => $clusterId, 'moved' => $moved];
	}

	/**
	 * Marks faces "not a face" (IGNORED): clustering leaves them alone and no UI lists them under
	 * a person. Accepts a face in any state, including one already unassigned; untitled people
	 * left with no members are deleted.
	 *
	 * @param list<int> $detectionIds
	 * @return int faces ignored
	 */
	public function ignoreFaces(string $userId, array $detectionIds): int {
		return $this->setFacesCluster($userId, $detectionIds, ClusteringService::IGNORED, null);
	}

	/**
	 * Puts ignored faces back to pending (NULL) so clustering may place them again.
	 *
	 * @param list<int> $detectionIds
	 * @return int faces restored
	 */
	public function unignoreFaces(string $userId, array $detectionIds): int {
		return $this->setFacesCluster($userId, $detectionIds, null, ClusteringService::IGNORED);
	}

	/**
	 * The one set-based "change these faces' cluster_id" statement, scoped to $userId. $onlyFrom
	 * restricts it to rows currently at that value, $onlyAssigned to rows in a real cluster. The
	 * count is rows matched (the connection runs with FOUND_ROWS), so a face already at $to still
	 * counts. Untitled people emptied by the change are deleted in the same transaction.
	 *
	 * @param list<int> $detectionIds
	 */
	private function setFacesCluster(string $userId, array $detectionIds, ?int $to, ?int $onlyFrom, bool $onlyAssigned = false): int {
		$changed = 0;
		$this->db->beginTransaction();
		try {
			foreach (array_chunk(array_values(array_unique($detectionIds)), 1000) as $batch) {
				$sources = $this->sourceClusters($userId, $batch);

				$qb = $this->db->getQueryBuilder();
				$qb->update('recognize_face_detections')
					->set('cluster_id', $qb->createNamedParameter($to, $to === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT))
					->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
					->andWhere($qb->expr()->in('id', $qb->createNamedParameter($batch, IQueryBuilder::PARAM_INT_ARRAY)));
				if ($onlyFrom !== null) {
					$qb->andWhere($qb->expr()->eq('cluster_id', $qb->createNamedParameter($onlyFrom, IQueryBuilder::PARAM_INT)));
				}
				if ($onlyAssigned) {
					$qb->andWhere($qb->expr()->gt('cluster_id', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
				}
				$changed += $qb->executeStatement();

				$this->deleteEmptyUntitledClusters($userId, $sources);
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return $changed;
	}

	/**
	 * The distinct real clusters these faces currently belong to - read before a change, so
	 * deleteEmptyUntitledClusters() knows which people may have just been emptied.
	 *
	 * @param list<int> $detectionIds
	 * @return list<int>
	 */
	private function sourceClusters(string $userId, array $detectionIds): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('cluster_id')
			->from('recognize_face_detections')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->in('id', $qb->createNamedParameter($detectionIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->gt('cluster_id', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		return array_map('intval', $qb->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));
	}

	/**
	 * Deletes those of $clusterIds that are untitled and have no member left. A titled person is
	 * kept even when empty: the user may be about to move faces into it.
	 *
	 * @param list<int> $clusterIds
	 */
	private function deleteEmptyUntitledClusters(string $userId, array $clusterIds): void {
		if ($clusterIds === []) {
			return;
		}
		$sub = $this->db->getQueryBuilder();
		$sub->select($sub->expr()->literal(1))
			->from('recognize_face_detections')
			->where($sub->expr()->eq('cluster_id', $sub->createFunction('c.id')));

		$qb = $this->db->getQueryBuilder();
		$qb->delete('recognize_face_clusters', 'c')
			->where($qb->expr()->eq('c.user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->in('c.id', $qb->createNamedParameter($clusterIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('c.title', $qb->createNamedParameter('')))
			->andWhere($qb->createFunction('NOT EXISTS (' . $sub->getSQL() . ')'));
		$qb->executeStatement();
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

<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * The one service that owns every people-level operation on our clusters: list, create, rename,
 * merge, delete a person; list, move, detach a face. The OCS controller, occ facewright:people and the DAV
 * router are thin callers of this, so there is exactly one place that knows how those work.
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
		private FaceStore $faceStore,
		private ProjectionService $projection,
	) {
	}

	/**
	 * Runs $work in one transaction, then pushes what it touched to Recognize's tables (what Memories reads).
	 * $work returns [result, face ids touched, person ids touched]. The push runs after the commit, never
	 * inside it, and never throws: a failed push marks the projection dirty and the hourly job repairs it.
	 *
	 * @template T
	 * @param callable(): array{0: T, 1: list<int>, 2: list<int>} $work
	 * @return T
	 */
	private function writeAndPush(callable $work): mixed {
		$this->db->beginTransaction();
		try {
			[$result, $faces, $clusters] = $work();
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		$this->projection->push($faces, $clusters);
		return $result;
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
			->selectAlias($qb->func()->count('u.id'), 'c')
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
		$qb->from('facewright_clusters', 'c')
			// user_id too: the index is (user_id, cluster_id), and without it this walks every face for every person.
			->leftJoin('c', 'facewright_user_faces', 'u', $qb->expr()->andX(
				$qb->expr()->eq('u.user_id', 'c.user_id'),
				$qb->expr()->eq('u.cluster_id', 'c.id'),
			))
			->where($qb->expr()->eq('c.user_id', $qb->createNamedParameter($userId)))
			->groupBy('c.id', 'c.title');
		if ($search !== '') {
			$qb->andWhere($qb->expr()->iLike('c.title', $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($search) . '%')));
		}
		if ($minCount > 0) {
			$qb->having($qb->expr()->orX(
				$qb->expr()->gte($qb->createFunction('COUNT(u.id)'), $qb->createNamedParameter($minCount, IQueryBuilder::PARAM_INT)),
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
	public function coversFor(string $userId, array $clusterIds): array {
		$covers = [];
		foreach ($clusterIds as $clusterId) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('u.id')
				->from('facewright_user_faces', 'u')
				->innerJoin('u', 'facewright_faces', 'f', $qb->expr()->eq('f.id', 'u.face_id'))
				->where($qb->expr()->eq('u.user_id', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->eq('u.cluster_id', $qb->createNamedParameter($clusterId, IQueryBuilder::PARAM_INT)))
				->orderBy($qb->createFunction('(f.det_score IS NULL)'), 'ASC')
				->addOrderBy('f.det_score', 'DESC')
				->addOrderBy($qb->createFunction('(f.width * f.height)'), 'DESC')
				->addOrderBy('u.id', 'ASC')
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
			->from('facewright_clusters')
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
			->from('facewright_clusters')
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
		return $this->listFacesWhere($userId, FaceState::ASSIGNED, $clusterId, $limit, $offset);
	}

	/** @return list<array{id: int, file_id: int, x: float, y: float, width: float, height: float}> */
	public function listUnassignedFaces(string $userId, ?int $limit = null, int $offset = 0): array {
		return $this->listFacesWhere($userId, FaceState::UNASSIGNED, null, $limit, $offset);
	}

	/** @return list<array{id: int, file_id: int, x: float, y: float, width: float, height: float}> */
	public function listIgnoredFaces(string $userId, ?int $limit = null, int $offset = 0): array {
		return $this->listFacesWhere($userId, FaceState::IGNORED, null, $limit, $offset);
	}

	/** Faces at $clusterId, which may be a real cluster or one of ClusteringService's sentinels. */
	public function countFaces(string $userId, int $clusterId): int {
		try {
			$match = FaceState::fromSentinel($clusterId);
		} catch (\InvalidArgumentException) {
			return 0; // not a person and not one of the sentinels: no face is there
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from('facewright_user_faces')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('state', $qb->createNamedParameter($match['state'], IQueryBuilder::PARAM_INT)));
		if ($match['cluster_id'] !== null) {
			$qb->andWhere($qb->expr()->eq('cluster_id', $qb->createNamedParameter($match['cluster_id'], IQueryBuilder::PARAM_INT)));
		}
		return (int)$qb->executeQuery()->fetchOne();
	}

	/**
	 * Where one of $userId's faces currently sits: a person's id, or one of ClusteringService's
	 * sentinels, or null while it is still pending. The DAV router needs it to refuse a request
	 * whose path names the wrong person.
	 *
	 * @throws \InvalidArgumentException if $detectionId isn't $userId's
	 */
	public function faceAssignment(string $userId, int $detectionId): ?int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('state', 'cluster_id')
			->from('facewright_user_faces')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($detectionId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$row = $qb->executeQuery()->fetchAssociative();
		if ($row === false) {
			throw new \InvalidArgumentException("Face {$detectionId} does not exist for {$userId}.");
		}
		return FaceState::toSentinel((int)$row['state'], $row['cluster_id'] !== null ? (int)$row['cluster_id'] : null);
	}

	/**
	 * Faces in one state, and in one person when the state is ASSIGNED. Geometry and file come from
	 * the physical face (facewright_faces).
	 *
	 * @return list<array{id: int, file_id: int, x: float, y: float, width: float, height: float}>
	 */
	private function listFacesWhere(string $userId, int $state, ?int $clusterId, ?int $limit, int $offset): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('u.id', 'f.file_id', 'f.x', 'f.y', 'f.width', 'f.height')
			->from('facewright_user_faces', 'u')
			->innerJoin('u', 'facewright_faces', 'f', $qb->expr()->eq('f.id', 'u.face_id'))
			->where($qb->expr()->eq('u.user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('u.state', $qb->createNamedParameter($state, IQueryBuilder::PARAM_INT)))
			->orderBy('u.id', 'ASC')
			->setFirstResult($offset);
		if ($clusterId !== null) {
			$qb->andWhere($qb->expr()->eq('u.cluster_id', $qb->createNamedParameter($clusterId, IQueryBuilder::PARAM_INT)));
		}
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
	 * Every one of $userId's own faces on
	 * one file, with the person title if assigned. Reads facewright_user_faces, so a legacy
	 * 128-dim row never reaches a UI through us.
	 *
	 * @return list<array{id: int, x: float, y: float, width: float, height: float, cluster_id: ?int, title: ?string}>
	 */
	public function facesForFile(string $userId, int $fileId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('u.id', 'f.x', 'f.y', 'f.width', 'f.height', 'u.state', 'u.cluster_id', 'c.title')
			->from('facewright_user_faces', 'u')
			->innerJoin('u', 'facewright_faces', 'f', $qb->expr()->eq('f.id', 'u.face_id'))
			->leftJoin('u', 'facewright_clusters', 'c', $qb->expr()->eq('u.cluster_id', 'c.id'))
			->where($qb->expr()->eq('u.user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('f.file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->orderBy('u.id', 'ASC');

		$faces = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$faces[] = [
				'id' => (int)$row['id'],
				'x' => (float)$row['x'],
				'y' => (float)$row['y'],
				'width' => (float)$row['width'],
				'height' => (float)$row['height'],
				// The sentinel the UIs read (-1, -3, null...), not our state column.
				'cluster_id' => FaceState::toSentinel((int)$row['state'], $row['cluster_id'] !== null ? (int)$row['cluster_id'] : null),
				'title' => $row['title'] !== null ? (string)$row['title'] : null,
			];
		}
		$result->closeCursor();
		return $faces;
	}

	/** @throws \InvalidArgumentException on an empty, purely numeric, or already-used title */
	public function createCluster(string $userId, string $title): int {
		$this->assertValidTitle($userId, $title, null);

		return $this->writeAndPush(function () use ($userId, $title): array {
			$id = $this->faceStore->allocateCluster($userId, $title);
			return [$id, [], [$id]];
		});
	}

	/** @throws \InvalidArgumentException if $clusterId doesn't exist for $userId, or $title is invalid */
	public function renameCluster(string $userId, int $clusterId, string $title): void {
		$this->getCluster($userId, $clusterId); // throws if not $userId's
		$this->assertValidTitle($userId, $title, $clusterId);

		$this->writeAndPush(function () use ($userId, $clusterId, $title): array {
			$this->faceStore->renameCluster($userId, $clusterId, $title);
			return [null, [], [$clusterId]];
		});
	}

	/**
	 * @throws \InvalidArgumentException an empty title, a purely numeric one (ambiguous with an
	 *     untitled cluster's own id-as-name); DuplicateTitleException for one already used by
	 *     another of $userId's clusters
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
			->from('facewright_clusters')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('title', $qb->createNamedParameter($title)));
		if ($excludingClusterId !== null) {
			$qb->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($excludingClusterId, IQueryBuilder::PARAM_INT)));
		}
		if ($qb->executeQuery()->fetchOne() !== false) {
			throw new DuplicateTitleException("A person named \"{$title}\" already exists; merge instead.");
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
				->from('facewright_user_faces')
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->in('cluster_id', $qb->createNamedParameter($mergeIds, IQueryBuilder::PARAM_INT_ARRAY)));
			return ['keepId' => $keepId, 'merged' => $mergeIds, 'moved' => (int)$qb->executeQuery()->fetchOne()];
		}

		return $this->writeAndPush(function () use ($userId, $keepId, $mergeIds): array {
			$members = $this->faceStore->moveMembers($userId, $mergeIds, FaceState::ASSIGNED, $keepId);
			$legacy = $this->moveLegacyMembers($userId, $mergeIds, $keepId);
			$this->faceStore->deleteClusters($userId, $mergeIds);
			$this->forgetClusters($userId, $mergeIds);
			$this->forgetCentroids($userId, [$keepId]); // its size changed
			return [
				['keepId' => $keepId, 'merged' => $mergeIds, 'moved' => count($members) + $legacy],
				$members,
				array_merge($mergeIds, [$keepId]),
			];
		});
	}

	/**
	 * Unassigns every member and deletes the cluster: the same result as Recognize's own
	 * FaceClusterMapper::delete(), which is what Memories expects from deleting a person.
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

		return $this->writeAndPush(function () use ($userId, $clusterIds): array {
			$members = $this->faceStore->moveMembers($userId, $clusterIds, FaceState::UNASSIGNED, null);
			$legacy = $this->moveLegacyMembers($userId, $clusterIds, ClusteringService::UNASSIGNED);
			$this->faceStore->deleteClusters($userId, $clusterIds);
			$this->forgetClusters($userId, $clusterIds);
			return [count($members) + $legacy, $members, $clusterIds];
		});
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
		return $this->setFacesCluster($userId, $detectionIds, FaceState::UNASSIGNED, null, [FaceState::ASSIGNED]);
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
		return $this->setFacesCluster($userId, $detectionIds, FaceState::ASSIGNED, $targetClusterId);
	}

	/**
	 * Creates a person and moves the faces into it in one transaction.
	 *
	 * @param list<int> $detectionIds
	 * @return array{clusterId: int, moved: int}
	 * @throws \InvalidArgumentException on an invalid or already-used title
	 */
	public function moveFacesToNewPerson(string $userId, array $detectionIds, string $title): array {
		$this->assertValidTitle($userId, $title, null);
		return $this->writeAndPush(function () use ($userId, $detectionIds, $title): array {
			$clusterId = $this->faceStore->allocateCluster($userId, $title);
			[$moved, $faces, $clusters] = $this->setFacesClusterInTx($userId, $detectionIds, FaceState::ASSIGNED, $clusterId, []);
			return [['clusterId' => $clusterId, 'moved' => $moved], $faces, array_merge($clusters, [$clusterId])];
		});
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
		return $this->setFacesCluster($userId, $detectionIds, FaceState::IGNORED, null);
	}

	/**
	 * Puts ignored faces back to pending (NULL) so clustering may place them again.
	 *
	 * @param list<int> $detectionIds
	 * @return int faces restored
	 */
	public function unignoreFaces(string $userId, array $detectionIds): int {
		return $this->setFacesCluster($userId, $detectionIds, FaceState::PENDING, null, [FaceState::IGNORED]);
	}

	/**
	 * The one set-based "change these faces" operation, scoped to $userId. $onlyStates restricts it to faces
	 * currently in one of those states. The count is the faces matched, so a face already at the target still
	 * counts. Untitled people emptied by the change are deleted in the same transaction.
	 *
	 * @param list<int> $detectionIds
	 * @param list<int> $onlyStates
	 */
	private function setFacesCluster(string $userId, array $detectionIds, int $state, ?int $to, array $onlyStates = []): int {
		return $this->writeAndPush(fn (): array => $this->setFacesClusterInTx($userId, $detectionIds, $state, $to, $onlyStates));
	}

	/**
	 * The body of setFacesCluster() for callers that already hold a transaction.
	 *
	 * @param list<int> $detectionIds
	 * @param list<int> $onlyStates
	 * @return array{0: int, 1: list<int>, 2: list<int>} [faces matched, face ids touched, person ids touched]
	 */
	private function setFacesClusterInTx(string $userId, array $detectionIds, int $state, ?int $to, array $onlyStates): array {
		$count = 0;
		$faces = [];
		$clusters = $to !== null ? [$to] : [];
		foreach (array_chunk(array_values(array_unique($detectionIds)), 1000) as $batch) {
			$sources = $this->sourceClusters($userId, $batch);
			$matched = $this->faceStore->setState($userId, $batch, $state, $to, $onlyStates);
			$count += count($matched);
			array_push($faces, ...$matched);
			array_push($clusters, ...$this->faceStore->deleteEmptyUntitled($userId, $sources));
		}
		return [$count, $faces, $clusters];
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
			->from('facewright_user_faces')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->in('id', $qb->createNamedParameter($detectionIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('state', $qb->createNamedParameter(FaceState::ASSIGNED, IQueryBuilder::PARAM_INT)));
		return array_map('intval', $qb->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));
	}

	/**
	 * Recognize's own legacy rows (rows with no row of ours) that point at one of these people move with them:
	 * to the surviving person on a merge, to -1 on a delete, which is what Recognize's own code does.
	 *
	 * @param list<int> $fromClusterIds
	 * @return int legacy rows moved
	 */
	private function moveLegacyMembers(string $userId, array $fromClusterIds, int $to): int {
		$ids = array_values(array_unique(array_map('intval', $fromClusterIds)));
		if ($ids === []) {
			return 0;
		}
		return $this->db->executeStatement(
			'UPDATE ' . $this->db->getQueryBuilder()->getTableName('recognize_face_detections') . ' SET cluster_id = ' . $to
			. ' WHERE user_id = ? AND cluster_id IN (' . implode(',', $ids) . ') AND id NOT IN (SELECT id FROM '
			. $this->db->getQueryBuilder()->getTableName('facewright_user_faces') . ')',
			[$userId]
		);
	}

	/**
	 * Drops what SuggestionService keeps about people that no longer exist: their cached
	 * centroids and every dismissal that mentions them. Only the merge and delete paths call
	 * this; a person removed from Memories' side leaves rows behind that are inert, since cluster
	 * ids are never reused.
	 *
	 * @param list<int> $clusterIds
	 */
	private function forgetClusters(string $userId, array $clusterIds): void {
		$this->forgetCentroids($userId, $clusterIds);

		$qb = $this->db->getQueryBuilder();
		$ids = $qb->createNamedParameter($clusterIds, IQueryBuilder::PARAM_INT_ARRAY);
		$qb->delete('facewright_merge_dismissals')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->in('cluster_a', $ids),
				$qb->expr()->in('cluster_b', $ids),
			));
		$qb->executeStatement();
	}

	/** @param list<int> $clusterIds */
	private function forgetCentroids(string $userId, array $clusterIds): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('facewright_cluster_centroids')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->in('cluster_id', $qb->createNamedParameter($clusterIds, IQueryBuilder::PARAM_INT_ARRAY)));
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
		// The decision lives in FaceStore::emptyClusterIds(): no face of ours points at the person, and neither
		// does a legacy row of Recognize's, so a person whose only members are legacy rows is never chosen.
		$ids = $this->faceStore->emptyClusterIds($userId);
		return $this->writeAndPush(function () use ($userId, $ids): array {
			$deleted = $this->faceStore->deleteClusters($userId, $ids);
			return [count($deleted), [], $deleted];
		});
	}
}

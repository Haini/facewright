<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Wipes facewright's own data: every face and person of ours (facewright_user_faces, facewright_clusters and what hangs
 * on them), the physical faces nothing refers to any more, and the processed-file tracking, for a "clean start" during
 * clustering-threshold experimentation. Then the copy Memories reads is emptied (ProjectionService::clear()), and the
 * ExApp's embedding cache. This is instance-wide, not scoped to one user, unlike ClusteringService::resetUser()
 * (which only clears the people of one user and puts their faces back to pending).
 *
 * Nothing of Recognize's is read or written.
 */
class ResetService {
	private const CHUNK_SIZE = 500;

	public function __construct(
		private IDBConnection $db,
		private FaceStore $faceStore,
		private ProjectionService $projection,
		private ExAppClusteringClient $exApp,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * `cacheCleared` is false if the ExApp's embedding cache could not be emptied (logged; it is disposable, and the
	 * ExApp prunes what no pass asks for within 30 days).
	 *
	 * @return array{deletedDetections: int, deletedClusters: int, deletedFaces: int, clearedProcessed: int, cacheCleared: bool}
	 */
	public function resetFacewrightData(): array {
		[$detectionIds, $clusterIds, $faceIds] = $this->findOwnRows();

		// People first: deleting their faces then has no stored size left to recount.
		$this->faceStore->deleteClustersById($clusterIds);
		$this->faceStore->deleteFaces($detectionIds);
		$deletedFaces = $this->deleteOrphanedFaces($faceIds);

		$qb = $this->db->getQueryBuilder();
		$qb->delete('facewright_processed');
		$clearedProcessed = $qb->executeStatement();

		// Both reference the clusters deleted above, and nothing else.
		foreach (['facewright_cluster_centroids', 'facewright_merge_dismissals'] as $table) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($table);
			$qb->executeStatement();
		}

		// Every face of ours is gone, so the copy Memories reads is empty (outside any transaction), and so is the
		// ExApp's whole cache (docs/exapp-clustering.md, S4).
		$this->projection->clear();
		$cacheCleared = true;
		try {
			$this->exApp->clear();
		} catch (ExAppClusteringException $e) {
			$cacheCleared = false;
			$this->logger->warning('facewright: reset could not empty the ExApp\'s embedding cache: ' . $e->getMessage(), ['app' => 'facewright_bridge']);
		}

		return [
			'cacheCleared' => $cacheCleared,
			'deletedDetections' => count($detectionIds),
			'deletedClusters' => count($clusterIds),
			'deletedFaces' => $deletedFaces,
			'clearedProcessed' => $clearedProcessed,
		];
	}

	/**
	 * The ids to delete, decided entirely in the SELECT, from our own table.
	 *
	 * @return array{0: list<int>, 1: list<int>, 2: list<int>} [detectionIds, clusterIds, faceIds]
	 */
	private function findOwnRows(): array {
		// Every row of ours.
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'cluster_id', 'face_id')
			->from('facewright_user_faces');
		$result = $qb->executeQuery();

		$detectionIds = [];
		$clusterIds = [];
		$faceIds = [];
		while ($row = $result->fetch()) {
			$detectionIds[] = (int)$row['id'];
			// cluster_id is only set for an assigned face, and is always a real person: the
			// sentinels (-1, -2, -3) are states here, not ids, so there is nothing to filter out.
			if ($row['cluster_id'] !== null) {
				$clusterIds[(int)$row['cluster_id']] = true;
			}
			$faceIds[(int)$row['face_id']] = true;
		}
		$result->closeCursor();

		// Every person of ours too, including the ones with no face left (a named person with no faces is still ours).
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('facewright_clusters');
		$result = $qb->executeQuery();
		while (($id = $result->fetchOne()) !== false) {
			$clusterIds[(int)$id] = true;
		}
		$result->closeCursor();

		return [$detectionIds, array_keys($clusterIds), array_keys($faceIds)];
	}

	/**
	 * Deletes $faceIds that no row of facewright_user_faces refers to - called after our rows are gone, so a face
	 * shared with a user this reset didn't touch (not possible for the instance-wide reset today, but the check costs
	 * nothing) survives.
	 *
	 * @param list<int> $faceIds
	 */
	private function deleteOrphanedFaces(array $faceIds): int {
		$deleted = 0;
		foreach (array_chunk($faceIds, self::CHUNK_SIZE) as $chunk) {
			// Correlated on the real table name: MariaDB before 11.6 rejects an alias on a single-table DELETE.
			$existsQb = $this->db->getQueryBuilder();
			$existsQb->select($existsQb->expr()->literal(1))
				->from('facewright_user_faces')
				->where($existsQb->expr()->eq('face_id', $existsQb->createFunction($existsQb->getTableName('facewright_faces') . '.id')));

			$qb = $this->db->getQueryBuilder();
			$qb->delete('facewright_faces')
				->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($qb->createFunction('NOT EXISTS (' . $existsQb->getSQL() . ')'));
			$deleted += $qb->executeStatement();
		}
		return $deleted;
	}
}

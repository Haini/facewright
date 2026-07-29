<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Wipes facewright's own contributions to Recognize's tables (512-dim InsightFace detections,
 * and any cluster made up of them) plus our own facewright_processed tracking, for a "clean
 * start" during clustering-threshold experimentation - without touching Recognize's own legacy
 * 128-dim data (the ~539 pre-existing named people/clusters found still living in this instance's
 * tables, see CLAUDE.md). This is instance-wide, not scoped to one user, unlike
 * ClusteringService::resetUser() (which only clears cluster_id for one user and, critically, does
 * so without any dimensionality filter - safe for its own narrow purpose, but not a substitute
 * for this).
 *
 * Relies on the same invariant ClusteringService's algorithm guarantees: a cluster is only ever
 * populated from detections of one dimensionality, since clustering strictly filters to 512-dim
 * before matching. So "every cluster referenced by a 512-dim detection" and "every cluster we
 * ever created" are the same set - safe to delete outright rather than requiring it to end up
 * empty first.
 */
class ResetService {
	private const CHUNK_SIZE = 500;

	public function __construct(
		private IDBConnection $db,
	) {
	}

	/** @return array{deletedDetections: int, deletedClusters: int, clearedProcessed: int} */
	public function resetFacewrightData(): array {
		[$detectionIds, $clusterIds] = $this->findOwnRows();

		$deletedDetections = $this->deleteByIds('recognize_face_detections', 'id', $detectionIds);
		$deletedClusters = $this->deleteByIds('recognize_face_clusters', 'id', $clusterIds);

		$qb = $this->db->getQueryBuilder();
		$qb->delete('facewright_processed');
		$clearedProcessed = $qb->executeStatement();

		return [
			'deletedDetections' => $deletedDetections,
			'deletedClusters' => $deletedClusters,
			'clearedProcessed' => $clearedProcessed,
		];
	}

	/** @return array{0: list<int>, 1: list<int>} [detectionIds, clusterIds] */
	private function findOwnRows(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'face_vector', 'cluster_id')->from('recognize_face_detections');
		$rows = $qb->executeQuery()->fetchAll();

		$detectionIds = [];
		$clusterIds = [];
		foreach ($rows as $row) {
			$vector = json_decode($row['face_vector'], true);
			if (!is_array($vector) || count($vector) !== ClusteringService::EXPECTED_DIMENSIONS) {
				continue;
			}
			$detectionIds[] = (int)$row['id'];
			// Negative cluster ids are sentinels (Facewright's UNCLUSTERABLE, Recognize's
			// RECOGNIZE_REJECTED), not real recognize_face_clusters rows - collecting them would
			// issue DELETEs for cluster ids that never exist. Harmless today, but it would
			// quietly become wrong if anything ever allocated a cluster with a negative id.
			if ($row['cluster_id'] !== null && (int)$row['cluster_id'] > 0) {
				$clusterIds[(int)$row['cluster_id']] = true;
			}
		}

		return [$detectionIds, array_keys($clusterIds)];
	}

	/** @param list<int> $ids */
	private function deleteByIds(string $table, string $column, array $ids): int {
		$deleted = 0;
		foreach (array_chunk($ids, self::CHUNK_SIZE) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($table)
				->where($qb->expr()->in($column, $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$deleted += $qb->executeStatement();
		}
		return $deleted;
	}
}

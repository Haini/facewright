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
 *
 * "Ours" is decided by DetectionService::ownRowsExpr(), in the SELECT, rather than by decoding
 * every embedding in PHP - see findOwnRows() for why that mattered enough to change on a
 * destructive command.
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

	/**
	 * The ids to delete, decided entirely in the SELECT.
	 *
	 * This used to fetchAll() the whole table with face_vector selected and json_decode() each row
	 * to test its dimensionality - the same anti-pattern already removed from loadDetections() and
	 * countUnclusteredByUser(), missed here because the reset command is rarely run. At ~196k rows
	 * averaging ~10.7 KB that is ~2.1 GB of off-page BLOB before decoding, and it died on PHP's
	 * 4 GB memory_limit. It failed before any DELETE ran, so no reset was ever left half-applied,
	 * but both the preview and the wipe were unusable.
	 *
	 * Projecting id + cluster_id never faults the face_vector pages at all, and the rows are
	 * streamed rather than materialised, so peak memory is now the id list alone.
	 *
	 * Note this changes the dimensionality test on a *destructive* path from exact (decode, count
	 * elements) to ownRowsExpr()'s char_length proxy. Both error directions are safe here, which is
	 * what licenses it: over-deleting a legacy row would need one longer than 6,000 characters, and
	 * 128 floats cannot reach that (measured 1,292-2,658; it would take ~280 dimensions), while
	 * under-deleting one of ours merely leaves a row behind for the next run.
	 *
	 * @return array{0: list<int>, 1: list<int>} [detectionIds, clusterIds]
	 */
	private function findOwnRows(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'cluster_id')
			->from('recognize_face_detections')
			->where(DetectionService::ownRowsExpr($qb));
		$result = $qb->executeQuery();

		$detectionIds = [];
		$clusterIds = [];
		while ($row = $result->fetch()) {
			$detectionIds[] = (int)$row['id'];
			// Negative cluster ids are sentinels (Facewright's UNCLUSTERABLE, Recognize's
			// RECOGNIZE_REJECTED), not real recognize_face_clusters rows - collecting them would
			// issue DELETEs for cluster ids that never exist. Harmless today, but it would
			// quietly become wrong if anything ever allocated a cluster with a negative id.
			if ($row['cluster_id'] !== null && (int)$row['cluster_id'] > 0) {
				$clusterIds[(int)$row['cluster_id']] = true;
			}
		}
		$result->closeCursor();

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

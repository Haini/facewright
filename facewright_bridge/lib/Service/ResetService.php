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

	/** @return array{deletedDetections: int, deletedClusters: int, deletedFaces: int, clearedProcessed: int} */
	public function resetFacewrightData(): array {
		[$detectionIds, $clusterIds, $faceIds] = $this->findOwnRows();

		// Links before detections before faces: a face is only ours to delete once nothing still
		// links to it (Stage 1 of docs/independence-implementation.md - fan-out can mean a face is
		// shared, though a whole-instance reset removes every one of our own detection rows, so in
		// practice every face this collected becomes orphaned).
		$this->deleteByIds('facewright_detection_faces', 'detection_id', $detectionIds);
		$deletedDetections = $this->deleteByIds('recognize_face_detections', 'id', $detectionIds);
		$deletedClusters = $this->deleteByIds('recognize_face_clusters', 'id', $clusterIds);
		$deletedFaces = $this->deleteOrphanedFaces($faceIds);

		$qb = $this->db->getQueryBuilder();
		$qb->delete('facewright_processed');
		$clearedProcessed = $qb->executeStatement();

		return [
			'deletedDetections' => $deletedDetections,
			'deletedClusters' => $deletedClusters,
			'deletedFaces' => $deletedFaces,
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
	 * A LEFT JOIN to facewright_detection_faces, not an INNER one: a row written during Stage 1's
	 * transitional window (recognized as ours by length alone, not yet backfilled by
	 * occ facewright:migrate-faces) has no link yet and must still be collected for deletion, just
	 * with no face id to go with it.
	 *
	 * @return array{0: list<int>, 1: list<int>, 2: list<int>} [detectionIds, clusterIds, faceIds]
	 */
	private function findOwnRows(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('d.id', 'd.cluster_id', 'l.face_id')
			->from('recognize_face_detections', 'd')
			->leftJoin('d', 'facewright_detection_faces', 'l', $qb->expr()->eq('l.detection_id', 'd.id'))
			->where(DetectionService::ownRowsExpr($qb, 'd'));
		$result = $qb->executeQuery();

		$detectionIds = [];
		$clusterIds = [];
		$faceIds = [];
		while ($row = $result->fetch()) {
			$detectionIds[] = (int)$row['id'];
			// Negative cluster ids are sentinels (ClusteringService::UNCLUSTERABLE, ::UNASSIGNED),
			// not real recognize_face_clusters rows - collecting them would issue DELETEs for
			// cluster ids that never exist. Harmless today, but it would quietly become wrong if
			// anything ever allocated a cluster with a negative id.
			if ($row['cluster_id'] !== null && (int)$row['cluster_id'] > 0) {
				$clusterIds[(int)$row['cluster_id']] = true;
			}
			if ($row['face_id'] !== null) {
				$faceIds[(int)$row['face_id']] = true;
			}
		}
		$result->closeCursor();

		return [$detectionIds, array_keys($clusterIds), array_keys($faceIds)];
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

	/**
	 * Deletes $faceIds that no longer have any facewright_detection_faces link - called after
	 * detection rows and their links are already gone, so a face fan-out shared with a user this
	 * reset didn't touch (not possible for the instance-wide reset today, but the check costs
	 * nothing) survives.
	 *
	 * @param list<int> $faceIds
	 */
	private function deleteOrphanedFaces(array $faceIds): int {
		$deleted = 0;
		foreach (array_chunk($faceIds, self::CHUNK_SIZE) as $chunk) {
			$existsQb = $this->db->getQueryBuilder();
			$existsQb->select($existsQb->expr()->literal(1))
				->from('facewright_detection_faces')
				->where($existsQb->expr()->eq('face_id', $existsQb->createFunction('f.id')));

			$qb = $this->db->getQueryBuilder();
			$qb->delete('facewright_faces', 'f')
				->where($qb->expr()->in('f.id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($qb->createFunction('NOT EXISTS (' . $existsQb->getSQL() . ')'));
			$deleted += $qb->executeStatement();
		}
		return $deleted;
	}
}

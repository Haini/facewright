<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;

/**
 * Prototype clustering, extracted from the facewright:cluster command so both it and the admin
 * page's "Run now" trigger share one implementation. Incremental nearest-neighbor assignment
 * modeled on Immich's approach rather than Recognize's own batch HDBSCAN (see CLAUDE.md for
 * why): a face joins/starts a cluster once it has at least $minFaces matches (including itself)
 * within $maxDistance cosine distance of an existing detection. Reclusters everything from
 * scratch each run, but only ever touches detections with no cluster_id yet, so a re-run after
 * new detections have landed is exactly "catch up on the new ones" - already-clustered
 * detections are left alone.
 *
 * Simplification vs. Immich: when a core face has no directly-matched cluster, it always
 * starts a new one, rather than also falling back to the single globally-closest clustered
 * face beyond the initial match set. Close enough for threshold experimentation.
 */
class ClusteringService {
	// recognize_face_detections predates this project on this instance (Nextcloud app
	// uninstalls don't drop tables) and still holds real rows from Recognize's own old
	// face-api.js classifier - 128-dim vectors, not our 512-dim InsightFace ones. Mixing the
	// two in a distance computation would silently corrupt every match, so filter strictly.
	// Public: ResetService reuses this as the same "is this row ours" test, so the two never
	// drift apart into different definitions of "our data".
	public const EXPECTED_DIMENSIONS = 512;

	public function __construct(
		private IDBConnection $db,
		private IUserManager $userManager,
	) {
	}

	/** @return array{newClusters: int, joined: int, skipped: int, skippedForeign: int} */
	public function clusterUser(string $userId, float $maxDistance, int $minFaces): array {
		['detections' => $detections, 'skippedForeign' => $skippedForeign] = $this->loadDetections($userId);

		$idsToProcess = array_values(array_filter(
			array_keys($detections),
			fn (int $id): bool => $detections[$id]['cluster_id'] === null,
		));

		$stats = $this->assignClusters($detections, $idsToProcess, $userId, $maxDistance, $minFaces);
		$this->persistAssignments($detections, $stats['changedIds']);

		return [
			'newClusters' => $stats['newClusters'],
			'joined' => $stats['joined'],
			'skipped' => $stats['skipped'],
			'skippedForeign' => $skippedForeign,
		];
	}

	/**
	 * Chunked counterpart to clusterUser(), for users with too many detections to match and
	 * persist in one synchronous request/job run - see ClusterScanJob, which owns pacing this
	 * across cron cycles via the $afterId cursor. Still loads and matches against every one of
	 * the user's detections (correct nearest-neighbor matching needs the full candidate pool
	 * regardless of chunk size), but only *attempts* up to $chunkSize currently-unclustered ids
	 * with id > $afterId this call - that's what bounds the O(chunk x total) comparison cost and
	 * the persisted-write cost per invocation, not the memory floor of holding the candidate
	 * pool, which stays proportional to the user's total detection count either way.
	 *
	 * @return array{attempted: int, newClusters: int, joined: int, skipped: int,
	 *     skippedForeign: int, lastId: ?int, remainingUnclustered: int}
	 */
	public function clusterUserChunk(string $userId, float $maxDistance, int $minFaces, int $afterId, int $chunkSize): array {
		['detections' => $detections, 'skippedForeign' => $skippedForeign] = $this->loadDetections($userId);

		$unclusteredIds = array_values(array_filter(
			array_keys($detections),
			fn (int $id): bool => $detections[$id]['cluster_id'] === null && $id > $afterId,
		));
		$idsToProcess = array_slice($unclusteredIds, 0, $chunkSize);

		$stats = $this->assignClusters($detections, $idsToProcess, $userId, $maxDistance, $minFaces);
		$this->persistAssignments($detections, $stats['changedIds']);

		$remainingUnclustered = 0;
		foreach ($detections as $d) {
			if ($d['cluster_id'] === null) {
				$remainingUnclustered++;
			}
		}

		return [
			'attempted' => count($idsToProcess),
			'newClusters' => $stats['newClusters'],
			'joined' => $stats['joined'],
			'skipped' => $stats['skipped'],
			'skippedForeign' => $skippedForeign,
			'lastId' => $idsToProcess === [] ? null : max($idsToProcess),
			'remainingUnclustered' => $remainingUnclustered,
		];
	}

	/**
	 * Runs clusterUser() across every seen user - used by occ facewright:cluster for small,
	 * synchronous, single- or all-user runs. The admin page's "Run now" trigger and
	 * occ facewright:cluster-scan use ClusterScanJob (chunked) instead, since this blocks for
	 * the whole user's detection set in one request - fine for a prototype-scale library, not
	 * for the ~14k-detection scale this instance reached (see CLAUDE.md).
	 *
	 * @return array{newClusters: int, joined: int, skipped: int, perUser: array<string, array{newClusters: int, joined: int, skipped: int}>}
	 */
	public function runForAllUsers(float $maxDistance, int $minFaces): array {
		$totals = ['newClusters' => 0, 'joined' => 0, 'skipped' => 0, 'perUser' => []];

		$this->userManager->callForSeenUsers(function (IUser $user) use (&$totals, $maxDistance, $minFaces): void {
			$result = $this->clusterUser($user->getUID(), $maxDistance, $minFaces);
			$totals['newClusters'] += $result['newClusters'];
			$totals['joined'] += $result['joined'];
			$totals['skipped'] += $result['skipped'];
			$totals['perUser'][$user->getUID()] = [
				'newClusters' => $result['newClusters'],
				'joined' => $result['joined'],
				'skipped' => $result['skipped'],
			];
		});

		return $totals;
	}

	/**
	 * Counts unclustered Facewright (512-dim) detections per user - the denominator behind the
	 * admin page's "still need to be clustered" indicator, and how ClusterTriggerService decides
	 * which users actually need a fresh ClusterScanJob rather than starting one for every seen
	 * user regardless of whether they have anything unclustered.
	 *
	 * Same dimension-filter-in-PHP approach as loadDetections() (see its docblock) rather than a
	 * SQL JSON_LENGTH() filter, for the same portability reason - this project runs across
	 * whatever DB backend the Nextcloud instance uses, not just MySQL/MariaDB.
	 *
	 * @return array<string, int> userId => count
	 */
	public function countUnclusteredByUser(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('user_id', 'face_vector')
			->from('recognize_face_detections')
			->where($qb->expr()->isNull('cluster_id'));
		$result = $qb->executeQuery();

		$counts = [];
		while ($row = $result->fetch()) {
			$vector = json_decode($row['face_vector'], true);
			if (!is_array($vector) || count($vector) !== self::EXPECTED_DIMENSIONS) {
				continue;
			}
			$counts[$row['user_id']] = ($counts[$row['user_id']] ?? 0) + 1;
		}
		$result->closeCursor();

		return $counts;
	}

	public function resetUser(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('recognize_face_detections')
			->set('cluster_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();

		$qb = $this->db->getQueryBuilder();
		$qb->delete('recognize_face_clusters')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}

	/**
	 * Attempts to assign a cluster to each of $idsToProcess (in order), matching against the
	 * full $detections candidate pool - shared by clusterUser() and clusterUserChunk() so the
	 * matching/join/create logic exists exactly once. Mutates $detections in place (both the
	 * processed ids and any other still-unclustered match that gets pulled in as a side effect,
	 * same as the original single-pass algorithm did) and reports which ids actually changed, so
	 * the caller persists only those instead of rewriting every already-clustered row again.
	 *
	 * @param array<int, array{file_id: int, vector: list<float>, cluster_id: ?int}> $detections
	 * @param list<int> $idsToProcess
	 * @return array{newClusters: int, joined: int, skipped: int, changedIds: list<int>}
	 */
	private function assignClusters(array &$detections, array $idsToProcess, string $userId, float $maxDistance, int $minFaces): array {
		$newClusters = 0;
		$joined = 0;
		$skipped = 0;
		$changedIds = [];

		foreach ($idsToProcess as $id) {
			if ($detections[$id]['cluster_id'] !== null) {
				// already assigned as a side effect of an earlier id in this same pass
				continue;
			}

			$matches = $this->findMatches($detections, $id, $maxDistance);
			// "matches" excludes $id itself here, so +1 mirrors Immich's "includes the face itself".
			if (count($matches) + 1 < $minFaces) {
				$skipped++;
				continue;
			}

			$clusterId = null;
			foreach ($matches as $match) {
				if ($detections[$match['id']]['cluster_id'] !== null) {
					$clusterId = $detections[$match['id']]['cluster_id'];
					break;
				}
			}

			if ($clusterId === null) {
				$clusterId = $this->createCluster($userId);
				$newClusters++;
			} else {
				$joined++;
			}

			$detections[$id]['cluster_id'] = $clusterId;
			$changedIds[] = $id;
			foreach ($matches as $match) {
				if ($detections[$match['id']]['cluster_id'] === null) {
					$detections[$match['id']]['cluster_id'] = $clusterId;
					$changedIds[] = $match['id'];
				}
			}
		}

		return ['newClusters' => $newClusters, 'joined' => $joined, 'skipped' => $skipped, 'changedIds' => $changedIds];
	}

	/**
	 * @return array{detections: array<int, array{file_id: int, vector: list<float>, cluster_id: ?int}>, skippedForeign: int}
	 */
	private function loadDetections(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'file_id', 'face_vector', 'cluster_id')
			->from('recognize_face_detections')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('id', 'ASC');
		$result = $qb->executeQuery();

		// Streamed row-by-row rather than fetchAll(): at real-library scale (tens of thousands
		// of rows) fetchAll() would hold the *entire* raw result set (including every legacy
		// 128-dim row's JSON text, discarded a moment later) in memory at once, on top of the
		// decoded vectors being built up alongside it - the combination is what pushed this past
		// PHP's memory_limit. Discarding each row's raw data as soon as it's decoded avoids
		// holding both forms of the whole dataset simultaneously.
		$detections = [];
		$skippedForeign = 0;
		while ($row = $result->fetch()) {
			$vector = json_decode($row['face_vector'], true);
			if (!is_array($vector) || count($vector) !== self::EXPECTED_DIMENSIONS) {
				$skippedForeign++;
				continue;
			}
			$detections[(int)$row['id']] = [
				'file_id' => (int)$row['file_id'],
				'vector' => $vector,
				'cluster_id' => $row['cluster_id'] !== null ? (int)$row['cluster_id'] : null,
			];
		}
		$result->closeCursor();

		return ['detections' => $detections, 'skippedForeign' => $skippedForeign];
	}

	/** @return list<array{id: int, distance: float}> sorted closest-first */
	private function findMatches(array $detections, int $id, float $maxDistance): array {
		$matches = [];
		foreach ($detections as $otherId => $other) {
			if ($otherId === $id) {
				continue;
			}
			$distance = $this->cosineDistance($detections[$id]['vector'], $other['vector']);
			if ($distance <= $maxDistance) {
				$matches[] = ['id' => $otherId, 'distance' => $distance];
			}
		}
		usort($matches, fn ($a, $b) => $a['distance'] <=> $b['distance']);
		return $matches;
	}

	private function cosineDistance(array $a, array $b): float {
		// Both vectors are already L2-normalized (facewright's /detect returns normed_embedding),
		// so cosine similarity is just the dot product.
		$dot = 0.0;
		foreach ($a as $i => $v) {
			$dot += $v * $b[$i];
		}
		return 1.0 - $dot;
	}

	private function createCluster(string $userId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('recognize_face_clusters')
			->values([
				'title' => $qb->createNamedParameter(''),
				'user_id' => $qb->createNamedParameter($userId),
			]);
		$qb->executeStatement();
		return $qb->getLastInsertId();
	}

	/** @param list<int> $ids */
	private function persistAssignments(array $detections, array $ids): void {
		foreach (array_unique($ids) as $id) {
			$clusterId = $detections[$id]['cluster_id'];
			if ($clusterId === null) {
				continue;
			}
			$qb = $this->db->getQueryBuilder();
			$qb->update('recognize_face_detections')
				->set('cluster_id', $qb->createNamedParameter($clusterId, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
			$qb->executeStatement();
		}
	}
}

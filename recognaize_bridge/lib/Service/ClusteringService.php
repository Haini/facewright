<?php

declare(strict_types=1);

namespace OCA\RecognaizeBridge\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;

/**
 * Prototype clustering, extracted from the recognaize:cluster command so both it and the admin
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
		$detections = $this->loadDetections($userId);
		$skippedForeign = $detections['skippedForeign'];
		$detections = $detections['detections'];

		$ids = array_keys($detections);
		$newClusters = 0;
		$joined = 0;
		$skipped = 0;

		foreach ($ids as $id) {
			if ($detections[$id]['cluster_id'] !== null) {
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
			foreach ($matches as $match) {
				if ($detections[$match['id']]['cluster_id'] === null) {
					$detections[$match['id']]['cluster_id'] = $clusterId;
				}
			}
		}

		$this->persistAssignments($detections);

		return ['newClusters' => $newClusters, 'joined' => $joined, 'skipped' => $skipped, 'skippedForeign' => $skippedForeign];
	}

	/**
	 * Runs clusterUser() across every seen user - the admin-page trigger's entry point, since
	 * that page isn't scoped to one user the way the CLI command is.
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

	/** @return array{detections: array<int, array{file_id: int, vector: list<float>, cluster_id: ?int}>, skippedForeign: int} */
	private function loadDetections(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'file_id', 'face_vector', 'cluster_id')
			->from('recognize_face_detections')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('id', 'ASC');
		$rows = $qb->executeQuery()->fetchAll();

		$detections = [];
		$skippedForeign = 0;
		foreach ($rows as $row) {
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
		// Both vectors are already L2-normalized (recognaize's /detect returns normed_embedding),
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

	private function persistAssignments(array $detections): void {
		foreach ($detections as $id => $d) {
			if ($d['cluster_id'] === null) {
				continue;
			}
			$qb = $this->db->getQueryBuilder();
			$qb->update('recognize_face_detections')
				->set('cluster_id', $qb->createNamedParameter($d['cluster_id'], IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
			$qb->executeStatement();
		}
	}
}

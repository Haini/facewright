<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Service;

use OCA\FacewrightBridge\BackgroundJob\ClusterScanJob;
use OCP\BackgroundJob\IJobList;
use OCP\IUserManager;

/**
 * Starts ClusterScanJob chains for whichever users actually have unclustered Facewright
 * detections, skipping anyone who already has a chain in flight. Extracted so the admin page's
 * manual "Run now" button, occ facewright:cluster-scan, and ClusteringMaintenanceJob's automatic
 * re-trigger (see that class for why it exists) all share one implementation instead of three
 * copies of the same "who needs a job, who already has one" logic - the pre-existing controller
 * and command each had their own copy of this before the maintenance job made a third copy
 * unavoidable without extracting it.
 *
 * Targeting only users with unclustered work (via ClusteringService::countUnclusteredByUser())
 * rather than every seen user is a deliberate improvement over the two previous copies: starting
 * a chain for a user with nothing unclustered just means ClusterScanJob immediately no-ops and
 * stops itself, which is harmless but wasteful, and would make the automatic maintenance job spam
 * the queue with no-op chains every cycle for every seen user on the instance.
 */
class ClusterTriggerService {
	public function __construct(
		private ClusteringService $clustering,
		private IJobList $jobList,
		private IUserManager $userManager,
	) {
	}

	/** @return int number of users a new chain was started for */
	public function startForUsersWithUnclustered(float $maxDistance, int $minFaces, int $chunkSize): int {
		$unclusteredByUser = $this->clustering->countUnclusteredByUser();
		if ($unclusteredByUser === []) {
			return 0;
		}

		$alreadyRunning = $this->usersWithPendingClusterScan();
		$started = 0;
		foreach (array_keys($unclusteredByUser) as $userId) {
			if (isset($alreadyRunning[$userId]) || !$this->userManager->userExists($userId)) {
				continue;
			}
			$this->jobList->add(ClusterScanJob::class, [
				'userId' => $userId,
				'maxDistance' => $maxDistance,
				'minFaces' => $minFaces,
				'chunkSize' => $chunkSize,
				'afterId' => 0,
				'passProgress' => false,
			]);
			$started++;
		}

		return $started;
	}

	/** @return array<string, true> user ids that already have a ClusterScanJob pending */
	private function usersWithPendingClusterScan(): array {
		$userIds = [];
		foreach ($this->jobList->getJobsIterator(ClusterScanJob::class, null, 0) as $job) {
			$argument = $job->getArgument();
			if (is_array($argument) && isset($argument['userId'])) {
				$userIds[$argument['userId']] = true;
			}
		}
		return $userIds;
	}
}

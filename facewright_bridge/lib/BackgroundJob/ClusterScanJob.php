<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\BackgroundJob;

use OCA\FacewrightBridge\Service\ClusteringService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Self-requeuing, chunked clustering for one user - mirrors BatchScanJob's relationship to
 * BatchTriggerService, added after ClusteringService::clusterUser() started running out of
 * memory/time for real accounts (one instance reached ~14,000 detections for a single user -
 * loading and all-pairs-matching that many in one synchronous request is exactly the same
 * "everything in one shot" problem detection batching already solved, just along a different
 * axis: here it's *which unclustered faces to attempt this round*, not *which files to enqueue*,
 * since correct matching still needs every one of the user's detections loaded as the candidate
 * pool regardless of chunk size - see ClusteringService::clusterUserChunk().
 *
 * Progresses through a user's unclustered faces in id order via the `afterId` cursor carried in
 * the job argument. Once a pass reaches the end of the ordered set, it starts a fresh pass from
 * the beginning instead of stopping outright - matching is order-dependent within a pass, so
 * clusters formed partway through can make an earlier-skipped face matchable on the next pass.
 * But only if the previous pass made *some* assignment: a pass with zero effect will always have
 * zero effect again unchanged (nothing about the data changed), so that - not "nothing left
 * unclustered", which may never be fully true - is the actual stopping condition.
 */
class ClusterScanJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private ClusteringService $clustering,
		private IJobList $jobList,
		private LoggerInterface $logger,
		private IAppConfig $appConfig,
	) {
		parent::__construct($time);
	}

	/**
	 * @param array{userId: string, maxDistance: float, minFaces: int, chunkSize: int,
	 *     afterId: int, passProgress: bool} $argument
	 */
	protected function run($argument): void {
		if ($this->appConfig->getValueString('facewright_bridge', 'processing_paused', 'false') === 'true') {
			$this->jobList->add(self::class, $argument);
			return;
		}

		$userId = $argument['userId'];
		$chunkSize = max(1, (int)($argument['chunkSize'] ?? 300));
		$afterId = (int)($argument['afterId'] ?? 0);
		$passProgress = (bool)($argument['passProgress'] ?? false);

		$result = $this->clustering->clusterUserChunk(
			$userId,
			(float)$argument['maxDistance'],
			(int)$argument['minFaces'],
			$afterId,
			$chunkSize,
		);
		$this->logger->info(
			"facewright: clustering chunk for {$userId} (after id {$afterId}) - "
			. "{$result['newClusters']} new, {$result['joined']} joined, {$result['skipped']} skipped, "
			. "{$result['written']} written, {$result['remainingUnclustered']} still unclustered."
		);

		// Progress is rows written, not matches found: a match the database refused (a human changed
		// the row meanwhile, or Recognize's row disagrees with ours) must not keep the chain alive.
		$passProgress = $passProgress || $result['written'] > 0;

		if ($result['attempted'] === 0) {
			// Reached the end of this pass through the ordered unclustered set.
			if ($result['remainingUnclustered'] === 0 || !$passProgress) {
				// A pass that assigned nothing proves everything still pending is unclusterable
				// at these thresholds, not merely unvisited - so retire those rows out of NULL.
				// That is what stops Recognize's MaintenanceJob re-arming ClusterFacesJob against
				// our 512-dim vectors; see ClusteringService::UNCLUSTERABLE.
				$marked = $passProgress
					? 0
					: $this->clustering->markUnclusterable($userId, $result['pendingUnassignedIds']);
				$this->logger->info('facewright: clustering for ' . $userId . ' stopping - ' . (
					$result['remainingUnclustered'] === 0 ? 'nothing left unclustered.' : 'a full pass made no further progress.'
				) . ($marked > 0 ? " Marked {$marked} detection(s) unclusterable." : ''));
				return;
			}
			$this->jobList->add(self::class, [...$argument, 'afterId' => 0, 'passProgress' => false]);
			return;
		}

		$this->jobList->add(self::class, [...$argument, 'afterId' => $result['lastId'], 'passProgress' => $passProgress]);
	}
}

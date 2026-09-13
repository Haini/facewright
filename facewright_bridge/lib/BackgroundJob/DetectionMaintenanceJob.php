<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\BackgroundJob;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Detection's counterpart to ClusteringMaintenanceJob - same problem, one stage earlier in the
 * pipeline. BatchScanJob is a self-terminating chain: it stops re-adding itself the moment a pass
 * enqueues nothing new (correct - otherwise it would spin forever once the backlog is empty), but
 * nothing then restarts it when new work appears afterward. FileCreatedListener is supposed to be
 * that trigger for new uploads, but nothing actually verifies it fired - none of its enqueue calls
 * are logged, and DetectQueuedJob's own early-return branches were silent until this same change
 * added logging to them. A real incident on this instance went undetected for 17 days this way: no
 * error anywhere, just an empty queue and a growing "not yet processed" count that nobody was
 * watching. This is the safety net for that - it doesn't try to explain why the backlog exists
 * (listener miss, a dropped job, or anything else), it just re-enumerates and re-enqueues on a
 * schedule the listener's reliability can no longer silently undermine.
 *
 * Deliberately does not pre-check whether there is real backlog before restarting the chain, unlike
 * ClusterTriggerService::startForUsersWithUnclustered() for the clustering case. There the check is
 * needed because a chain exists per user and skipping uninterested users avoids spamming the queue
 * with no-op chains; here there is exactly one instance-wide chain, and BatchScanJob already pays
 * for the same enumeration to answer that question itself (and stops on its own the moment it finds
 * nothing) - a second copy of that check here would just pay the cost twice for no benefit.
 * IJobList::add() dedupes on class plus argument, so restarting an already-running chain with the
 * same chunk size is a no-op, exactly as BatchScanJob's own self-requeue already relies on.
 *
 * Defaults to enabled, matching ClusteringMaintenanceJob's reasoning: this does not turn detection
 * on for anyone (automatic_detection_enabled still gates that), it only keeps an already-running
 * pipeline from silently stalling partway through.
 */
class DetectionMaintenanceJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private IJobList $jobList,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(60 * 60);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		if ($this->appConfig->getValueString('facewright_bridge', 'automatic_detection_enabled', 'false') !== 'true') {
			return;
		}
		if ($this->appConfig->getValueString('facewright_bridge', 'processing_paused', 'false') === 'true') {
			return;
		}

		$chunkSize = $this->appConfig->getValueInt('facewright_bridge', 'batch_size', 200);
		$this->jobList->add(BatchScanJob::class, ['chunkSize' => $chunkSize]);
		$this->logger->debug("facewright: automatic maintenance (re-)started the library scan chain (chunk size {$chunkSize}).");
	}
}

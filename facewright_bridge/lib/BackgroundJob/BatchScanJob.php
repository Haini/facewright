<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\BackgroundJob;

use OCA\FacewrightBridge\Service\BatchTriggerService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Self-requeuing wrapper around BatchTriggerService, for scanning an entire backlog (e.g. tens
 * of thousands of files) without the risk a single huge BatchTriggerService::run() call has: run
 * from a web request (the admin page's one-shot "Run now" button) or occ, one massive batch size
 * means enumerating and enqueuing everything synchronously in one go, which can blow past a PHP
 * request time limit, and even if it doesn't, dumps the whole backlog's worth of CPU-bound
 * detection work on cron all at once.
 *
 * Instead this only ever enqueues one modest chunk per run() (whatever BatchTriggerService
 * enqueues for $chunkSize), then re-adds itself so cron's own per-invocation time budget paces
 * how fast chunks get queued - a handful of chunks per cron.php run if there's budget, otherwise
 * naturally spread across many ticks. IJobList::add() already dedupes identical
 * (class, json-argument) pairs via its own has() check, so re-adding self with the same
 * chunkSize argument is cheap and safe even if somehow already queued. Stops re-adding once a
 * pass enqueues nothing new - either the backlog is fully processed, or everything remaining is
 * already pending (queued by this same scan, the automatic listener, or a manual "Run now").
 *
 * An optional userId scopes a chain to one user, which is what the personal settings page's "Scan
 * my files now" starts. Because IJobList::add() dedupes on class plus argument, that gives one
 * chain per user *and* one instance-wide chain for free, with no second job class. Rows queued
 * under the old argument shape ({chunkSize} alone) keep working untouched - an absent userId is
 * exactly what "scan everyone" means.
 *
 * @template-extends QueuedJob<array{chunkSize: int, userId?: string}>
 */
class BatchScanJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private BatchTriggerService $batchTrigger,
		private IJobList $jobList,
		private LoggerInterface $logger,
		private IAppConfig $appConfig,
	) {
		parent::__construct($time);
	}

	/** @param array{chunkSize: int, userId?: string} $argument */
	protected function run($argument): void {
		$chunkSize = max(1, (int)($argument['chunkSize'] ?? 200));
		$userId = isset($argument['userId']) ? (string)$argument['userId'] : null;

		// Re-added verbatim rather than rebuilt, so a per-user chain stays a per-user chain and the
		// dedup key doesn't change across a pause.
		$requeue = $userId === null ? ['chunkSize' => $chunkSize] : ['chunkSize' => $chunkSize, 'userId' => $userId];

		// Paused: don't enqueue more work, but keep the scan alive by re-adding self, same
		// pattern DetectQueuedJob uses - resuming later picks up exactly where this left off.
		if ($this->appConfig->getValueString('facewright_bridge', 'processing_paused', 'false') === 'true') {
			$this->jobList->add(self::class, $requeue);
			return;
		}

		$queued = $userId === null
			? $this->batchTrigger->run($chunkSize)
			: $this->batchTrigger->runForUser($userId, $chunkSize);
		$scope = $userId === null ? 'library scan' : "library scan for {$userId}";
		$this->logger->info("facewright: {$scope} enqueued {$queued} file(s) this cycle.");

		if ($queued > 0) {
			$this->jobList->add(self::class, $requeue);
		} else {
			$this->logger->info("facewright: {$scope} found nothing new to enqueue - stopping.");
		}
	}
}

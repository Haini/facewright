<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\BackgroundJob;

use OCA\FacewrightBridge\Service\DetectionService;
use OCA\FacewrightBridge\Service\PathFilterService;
use OCA\FacewrightBridge\Service\ProcessedFileService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Drains the queue FileCreatedListener (or the batch trigger) fills. Runs on Nextcloud's own
 * cron - confirmed genuinely running on this instance - not synchronously with the upload
 * request, since detection can take several seconds per file.
 *
 * @template-extends QueuedJob<array{userId: string, fileId: int}>
 */
class DetectQueuedJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private IRootFolder $rootFolder,
		private PathFilterService $pathFilter,
		private DetectionService $detectionService,
		private ProcessedFileService $processedFiles,
		private LoggerInterface $logger,
		private IAppConfig $appConfig,
		private IJobList $jobList,
	) {
		parent::__construct($time);
	}

	/** @param array{userId: string, fileId: int} $argument */
	protected function run($argument): void {
		// QueuedJobs are removed from the queue once run() returns, so a paused job has to
		// re-add itself to survive until the next tick - see BatchScanJob for the same pattern,
		// and PeopleSettingsController::setProcessingPaused for how this flag gets set.
		if ($this->appConfig->getValueString('facewright_bridge', 'processing_paused', 'false') === 'true') {
			$this->jobList->add(self::class, $argument);
			return;
		}

		$userId = $argument['userId'];
		$fileId = (int)$argument['fileId'];

		$userFolder = $this->rootFolder->getUserFolder($userId);
		$node = $userFolder->getFirstNodeById($fileId);
		if ($node === null) {
			$this->logger->debug("facewright: file {$fileId} for user {$userId} no longer exists, skipping.");
			return;
		}

		$path = $userFolder->getRelativePath($node->getPath());
		if ($path === null) {
			return;
		}

		// Checked here (not in the listener/enumeration step) since the queue can sit for one
		// or more cron cycles - this is the freshest possible read of both settings.
		if (!$this->pathFilter->isIncluded($path)) {
			return;
		}
		if (!in_array($node->getMimetype(), DetectionService::SUPPORTED_MIMETYPES, true)) {
			// Re-checked here as well as at both enqueue sites: the queue can outlive a change to
			// the supported list, and a file's mimetype can be corrected by a later rescan.
			return;
		}
		if ($node->getSize() === 0) {
			// Nothing to send, and worth catching on this side: an empty body makes OpenCV raise
			// rather than return None, which surfaces as a 500 and so reads as retryable. Marked
			// processed rather than merely skipped, because unlike an unsupported mimetype this
			// is a broken file rather than a capability we might gain later.
			$this->logger->warning("facewright: skipping zero-byte file {$path} (user {$userId}).");
			$this->processedFiles->markProcessed($userId, $fileId, ProcessedFileService::FACES_FAILED);
			return;
		}
		if ($this->processedFiles->isProcessed($userId, $fileId)) {
			return;
		}

		$result = $this->detectionService->detect($userId, $path, $fileId);
		if (!$result['success']) {
			$this->logger->warning("facewright: detection failed for {$path} (user {$userId}): {$result['error']}");
			if ($result['permanent']) {
				// Record it so the backlog scan stops re-enqueueing a file that cannot succeed.
				// Without this a handful of undecodable files are retried on every pass forever:
				// on this instance 5 such files produced 5,595 identical warnings. Transient
				// failures deliberately fall through unmarked so they are picked up again.
				$this->processedFiles->markProcessed($userId, $fileId, ProcessedFileService::FACES_FAILED);
			}
			return;
		}
		$this->logger->info("facewright: inserted {$result['inserted']} face detection(s) for {$path} (user {$userId}).");
	}
}

<?php

declare(strict_types=1);

namespace OCA\RecognaizeBridge\BackgroundJob;

use OCA\RecognaizeBridge\Service\DetectionService;
use OCA\RecognaizeBridge\Service\PathFilterService;
use OCA\RecognaizeBridge\Service\ProcessedFileService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\Files\IRootFolder;
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
	) {
		parent::__construct($time);
	}

	/** @param array{userId: string, fileId: int} $argument */
	protected function run($argument): void {
		$userId = $argument['userId'];
		$fileId = (int)$argument['fileId'];

		$userFolder = $this->rootFolder->getUserFolder($userId);
		$node = $userFolder->getFirstNodeById($fileId);
		if ($node === null) {
			$this->logger->debug("recognaize: file {$fileId} for user {$userId} no longer exists, skipping.");
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
		if ($this->processedFiles->isProcessed($userId, $fileId)) {
			return;
		}

		$result = $this->detectionService->detect($userId, $path, $fileId);
		if (!$result['success']) {
			$this->logger->warning("recognaize: detection failed for {$path} (user {$userId}): {$result['error']}");
			return;
		}
		$this->logger->info("recognaize: inserted {$result['inserted']} face detection(s) for {$path} (user {$userId}).");
	}
}

<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Service;

use OCA\FacewrightBridge\BackgroundJob\DetectQueuedJob;
use OCP\BackgroundJob\IJobList;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserManager;

/**
 * Enumerates existing files not yet processed and enqueues DetectQueuedJobs for them, up to a
 * configured batch size - the manual complement to FileCreatedListener's automatic path (which
 * only fires for *new* uploads going forward, not the existing backlog). Reuses the same job
 * class as the automatic path, so both funnel through one processing pipeline; actual
 * processing still happens via cron, not synchronously in the request that calls run().
 */
class BatchTriggerService {
	public function __construct(
		private IUserManager $userManager,
		private IRootFolder $rootFolder,
		private PathFilterService $pathFilter,
		private ProcessedFileService $processedFiles,
		private IJobList $jobList,
	) {
	}

	public function run(int $batchSize): int {
		$queued = 0;

		$this->userManager->callForSeenUsers(function (IUser $user) use (&$queued, $batchSize): ?bool {
			$userFolder = $this->rootFolder->getUserFolder($user->getUID());
			$searchRoots = $this->pathFilter->resolveSearchRoots($userFolder);

			foreach ($searchRoots as $folder) {
				// searchByMime('image') matches the whole `image/*` prefix, which includes
				// Nextcloud's `image/x-dcraw` catch-all for raw camera files (.RW2, .CR2, ...).
				// The ExApp decodes with cv2.imdecode() and cannot read those, so enumerating
				// them only produces work that is guaranteed to fail.
				foreach ($folder->searchByMime('image') as $node) {
					if (!in_array($node->getMimetype(), DetectionService::SUPPORTED_MIMETYPES, true)) {
						continue;
					}
					if ($this->processedFiles->isProcessed($user->getUID(), $node->getId())) {
						continue;
					}
					$this->jobList->add(DetectQueuedJob::class, ['userId' => $user->getUID(), 'fileId' => $node->getId()]);
					$queued++;
					if ($queued >= $batchSize) {
						return false; // stop callForSeenUsers early
					}
				}
			}
			return null;
		});

		return $queued;
	}
}

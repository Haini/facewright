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

	/**
	 * @return int distinct files enqueued - not enqueue attempts. A file two users can both see is
	 *             reached by both walks and enqueued twice, but the two calls carry the identical
	 *             argument and IJobList::add() collapses them into one job, so counting attempts
	 *             would overstate the work created and could stop a chunk early having queued less
	 *             than asked for.
	 */
	public function run(int $batchSize): int {
		$queued = 0;
		$seen = [];

		$this->userManager->callForSeenUsers(function (IUser $user) use (&$queued, &$seen, $batchSize): ?bool {
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
					$fileId = $node->getId();
					// Still a per-user question, and deliberately so: it is the cheap, indexed test
					// for "does this user still need rows for this file", and it needs no
					// mount-cache lookup during enumeration. The job itself recomputes the full set
					// of users who need rows.
					if ($this->processedFiles->isProcessed($user->getUID(), $fileId)) {
						continue;
					}
					// Keyed on the file alone, so one inference ends up serving every user who can
					// see it - see DetectQueuedJob's docblock.
					$this->jobList->add(DetectQueuedJob::class, ['fileId' => $fileId]);
					if (isset($seen[$fileId])) {
						continue;
					}
					$seen[$fileId] = true;
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

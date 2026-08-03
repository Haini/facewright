<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Service;

use OCA\FacewrightBridge\BackgroundJob\DetectQueuedJob;
use OCP\BackgroundJob\IJobList;
use OCP\Files\IRootFolder;
use OCP\IUserManager;

/**
 * Enumerates existing files not yet processed and enqueues DetectQueuedJobs for them, up to a
 * configured batch size - the manual complement to FileCreatedListener's automatic path (which
 * only fires for *new* uploads going forward, not the existing backlog). Reuses the same job
 * class as the automatic path, so both funnel through one processing pipeline; actual
 * processing still happens via cron, not synchronously in the request that calls run().
 *
 * Only users who opted in are walked. That is the whole point of per-user mode: opting out has to
 * stop work, not merely hide a settings page.
 */
class BatchTriggerService {
	public function __construct(
		private IUserManager $userManager,
		private IRootFolder $rootFolder,
		private PathFilterService $pathFilter,
		private UserSettingsService $userSettings,
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
		$seen = [];

		foreach ($this->userSettings->enabledUsers() as $userId) {
			if ($this->userManager->get($userId) === null) {
				continue; // opted in, then deleted - the preference row outlives the account
			}
			$this->collectForUser($userId, $batchSize, $seen);
			if (count($seen) >= $batchSize) {
				break;
			}
		}

		return count($seen);
	}

	/**
	 * One user's share of the same work, for the personal settings page's "Scan my files now" and
	 * for BatchScanJob's per-user chains. Same enumeration and same job argument as run() - the two
	 * entry points must not drift, or a user's own scan would cover a different file set than the
	 * instance-wide one.
	 *
	 * @return int distinct files enqueued for this user
	 */
	public function runForUser(string $userId, int $batchSize): int {
		if (!$this->userSettings->isEnabled($userId) || $this->userManager->get($userId) === null) {
			return 0;
		}
		$seen = [];
		$this->collectForUser($userId, $batchSize, $seen);
		return count($seen);
	}

	/**
	 * @param array<int, true> $seen file ids already counted, shared across users in an
	 *                               instance-wide run so a shared photo isn't counted twice
	 */
	private function collectForUser(string $userId, int $batchSize, array &$seen): void {
		$userFolder = $this->rootFolder->getUserFolder($userId);

		foreach ($this->pathFilter->resolveSearchRoots($userId, $userFolder) as $folder) {
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
				if ($this->processedFiles->isProcessed($userId, $fileId)) {
					continue;
				}
				// Keyed on the file alone, so one inference ends up serving every user who can
				// see it - see DetectQueuedJob's docblock.
				$this->jobList->add(DetectQueuedJob::class, ['fileId' => $fileId]);
				if (isset($seen[$fileId])) {
					continue;
				}
				$seen[$fileId] = true;
				if (count($seen) >= $batchSize) {
					return;
				}
			}
		}
	}
}

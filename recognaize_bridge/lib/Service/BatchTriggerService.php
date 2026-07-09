<?php

declare(strict_types=1);

namespace OCA\RecognaizeBridge\Service;

use OCA\RecognaizeBridge\BackgroundJob\DetectQueuedJob;
use OCP\BackgroundJob\IJobList;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
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
		private DetectionService $detectionService,
		private IJobList $jobList,
	) {
	}

	public function run(int $batchSize): int {
		$queued = 0;
		$includedPaths = $this->pathFilter->getIncludedPaths();

		$this->userManager->callForSeenUsers(function (IUser $user) use (&$queued, $batchSize, $includedPaths): ?bool {
			$userFolder = $this->rootFolder->getUserFolder($user->getUID());
			$searchRoots = $includedPaths === [] ? [$userFolder] : $this->resolveFolders($userFolder, $includedPaths);

			foreach ($searchRoots as $folder) {
				foreach ($folder->searchByMime('image') as $node) {
					if ($this->detectionService->hasExistingDetections($user->getUID(), $node->getId())) {
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

	/**
	 * @param list<string> $paths
	 * @return list<Folder>
	 */
	private function resolveFolders(Folder $userFolder, array $paths): array {
		$folders = [];
		foreach ($paths as $path) {
			try {
				$node = $userFolder->get($path);
			} catch (NotFoundException) {
				continue; // configured path doesn't exist for this user
			}
			if ($node instanceof Folder) {
				$folders[] = $node;
			}
		}
		return $folders;
	}
}

<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Service;

use OCP\Files\Node;
use Psr\Log\LoggerInterface;

/**
 * Removes detection rows and processed markers for files that are going away.
 *
 * Runs synchronously, inside BeforeNodeDeletedEvent, and that is the considered choice rather than
 * a shortcut. Deferring it to a job doesn't work: by the time the job runs the filecache rows are
 * gone, so there is no way left to find out which files were under a deleted folder. Enqueuing one
 * job per file *before* the delete is unbounded - a five-thousand-photo folder would mean five
 * thousand IJobList::add() calls, each with its own dedup SELECT, inside the delete request.
 *
 * What it costs instead: one indexed filecache page query per 1,000 files plus two DELETEs per
 * page. Deleting a folder full of already-detected photos is therefore slower than it was, in
 * proportion to how many of them Facewright had processed. Deleting a file that was never
 * processed costs one indexed SELECT that returns nothing.
 *
 * Note this drops rows for *every* user, which is what a file ceasing to exist means - unlike
 * AccessSyncService's prune, which is about one user losing sight of a file that still exists.
 */
class PruneService {
	private const PAGE_SIZE = 1000;

	/**
	 * Safety stop. Past this a single delete is doing something pathological (100k images under one
	 * node); the remainder becomes orphan rows, which occ facewright:backfill-access --prune cleans
	 * up, and that is a better outcome than an unbounded synchronous loop inside a delete request.
	 */
	private const MAX_PAGES = 100;

	public function __construct(
		private AccessSyncService $accessSync,
		private MountFileService $mountFiles,
		private LoggerInterface $logger,
	) {
	}

	public function pruneNode(Node $node): int {
		return $this->pruneTree($node->getId());
	}

	/**
	 * Handles files and folders identically: the node itself is forgotten, then anything beneath it
	 * is walked. For a file the subtree query matches nothing, so it costs one extra indexed lookup
	 * rather than needing the caller to know which kind of node it has.
	 */
	public function pruneTree(int $fileId): int {
		$deleted = $this->accessSync->forgetFile($fileId);

		$location = $this->mountFiles->locate($fileId);
		if ($location === null || $location['path'] === '') {
			return $deleted;
		}

		$after = 0;
		for ($page = 0; $page < self::MAX_PAGES; $page++) {
			$fileIds = $this->mountFiles->listImageFileIds($location['storage'], $location['path'], $after, self::PAGE_SIZE);
			if ($fileIds === []) {
				return $deleted;
			}
			foreach ($fileIds as $childId) {
				$deleted += $this->accessSync->forgetFile($childId);
			}
			$after = $fileIds[count($fileIds) - 1];
		}

		$this->logger->warning(
			'facewright: stopped pruning under file id ' . $fileId . ' after ' . (self::MAX_PAGES * self::PAGE_SIZE)
			. ' files; any remainder is orphaned until occ facewright:backfill-access --prune runs.'
		);
		return $deleted;
	}
}

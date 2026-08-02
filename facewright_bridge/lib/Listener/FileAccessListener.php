<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Listener;

use OCA\FacewrightBridge\BackgroundJob\AccessSyncJob;
use OCA\FacewrightBridge\BackgroundJob\FileAccessSyncJob;
use OCA\FacewrightBridge\Service\AccessService;
use OCA\FacewrightBridge\Service\PruneService;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Config\Event\UserMountAddedEvent;
use OCP\Files\Config\Event\UserMountRemovedEvent;
use OCP\Files\Config\ICachedMountInfo;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\Events\Node\BeforeNodeRenamedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\NodeRemovedFromCache;
use OCP\Files\Folder;
use Psr\Log\LoggerInterface;

/**
 * Keeps detection rows in step with who can see a file, after detection has already run.
 *
 * Mount events rather than share hooks: shares, group folders and external storage all appear in
 * oc_mounts, so UserMountAdded/Removed covers every way access can change without a single
 * share-specific branch. Recognize reached the same conclusion - as of v12 it has no share hooks at
 * all.
 *
 * Separate from FileCreatedListener on purpose. The create path works today; a mistake in this,
 * newer, path should not be able to take it down with it.
 *
 * Everything here either enqueues a bounded job or (for deletions, which cannot be deferred - see
 * PruneService) does a set-based DB walk. Nothing enumerates a share's contents in the request that
 * created it.
 *
 * @template-implements IEventListener<Event>
 */
class FileAccessListener implements IEventListener {
	/** @var array<string, true> "<uid>-<rootId>" mounts added earlier in this same request */
	private array $addedMounts = [];

	/** @var list<string>|null access set of a rename's source, captured before the move */
	private ?array $sourceUserIds = null;

	public function __construct(
		private AccessService $accessService,
		private PruneService $pruneService,
		private IJobList $jobList,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		try {
			$this->dispatch($event);
		} catch (\Throwable $e) {
			// A failure here must never break the filesystem operation that triggered it - the
			// worst acceptable outcome is stale face rows, not a failed upload or delete. Recognize
			// wraps its own listener the same way.
			$this->logger->error('facewright: error in access listener', ['exception' => $e]);
		}
	}

	private function dispatch(Event $event): void {
		if ($event instanceof UserMountAddedEvent) {
			$this->addedMounts[$this->mountKey($event->mountPoint)] = true;
			$this->enqueueMountSync($event->mountPoint);
			return;
		}

		if ($event instanceof UserMountRemovedEvent) {
			// The server fires Added then Removed for a share that is *modified* rather than
			// revoked, so a naive handler would treat every permission change as an unshare. For us
			// this is only an optimization: the job recomputes the access set from the database
			// minutes later, by which point the request has committed and the answer is right
			// either way. Recognize needs the same guard for correctness, because it snapshots the
			// access set at event time.
			if (isset($this->addedMounts[$this->mountKey($event->mountPoint)])) {
				return;
			}
			$this->enqueueMountSync($event->mountPoint);
			return;
		}

		if ($event instanceof BeforeNodeDeletedEvent) {
			// Before, not after: the filecache rows that say what was inside a folder are gone once
			// the delete completes.
			$this->pruneService->pruneNode($event->getNode());
			return;
		}

		if ($event instanceof NodeRemovedFromCache) {
			// External storage removals fire no node event. The cache entry is still readable at
			// this point, which is the only way to get an id out of a storage-and-path event.
			$entry = $event->getStorage()->getCache()->get($event->getPath());
			if ($entry !== false) {
				$this->pruneService->pruneTree($entry->getId());
			}
			return;
		}

		if ($event instanceof BeforeNodeRenamedEvent) {
			// Captured here because it cannot be computed afterwards - after the move the source
			// path no longer resolves, and with it the question of who could see it there.
			$this->sourceUserIds = $this->accessService->getUsersWithAccess($event->getSource()->getId(), true);
			return;
		}

		if ($event instanceof NodeRenamedEvent) {
			$target = $event->getTarget();
			$sourceUserIds = $this->sourceUserIds;
			$this->sourceUserIds = null;

			$targetUserIds = $this->accessService->getUsersWithAccess($target->getId(), true);
			// Most renames stay within one folder and change nothing about visibility; only a move
			// into or out of a shared folder is worth a job.
			if ($sourceUserIds !== null && $targetUserIds === $sourceUserIds) {
				return;
			}

			if ($target instanceof Folder) {
				// A folder move changes access for everything beneath it, so this needs the
				// subtree walk rather than a single-file reconcile.
				$storageId = $target->getMountPoint()->getNumericStorageId();
				if ($storageId !== null) {
					$this->jobList->add(AccessSyncJob::class, [
						'storageId' => $storageId,
						'rootId' => $target->getId(),
						'afterFileId' => 0,
						'prune' => true,
					]);
				}
				return;
			}
			$this->jobList->add(FileAccessSyncJob::class, ['fileId' => $target->getId()]);
		}
	}

	private function enqueueMountSync(ICachedMountInfo $mount): void {
		$this->jobList->add(AccessSyncJob::class, [
			'storageId' => $mount->getStorageId(),
			'rootId' => $mount->getRootId(),
			'afterFileId' => 0,
			'prune' => true,
		]);
	}

	private function mountKey(ICachedMountInfo $mount): string {
		return $mount->getUser()->getUID() . '-' . $mount->getRootId();
	}
}

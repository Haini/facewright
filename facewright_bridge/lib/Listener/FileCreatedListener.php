<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Listener;

use OCA\FacewrightBridge\BackgroundJob\DetectQueuedJob;
use OCA\FacewrightBridge\Service\DetectionService;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Cache\CacheEntryInsertedEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IAppConfig;

/**
 * Listens for new files and queues a DetectQueuedJob for images, gated by the
 * "automatic_detection_enabled" appconfig toggle (off by default - see CLAUDE.md on why this
 * got its own switch rather than relying only on included-paths).
 *
 * Listens to both NodeCreatedEvent and CacheEntryInsertedEvent, matching Recognize's own
 * FileListener: different upload paths (direct upload vs. e.g. external storage scans) fire
 * different events, neither alone covers everything.
 *
 * Renames, deletes and mount add/remove live in FileAccessListener, kept separate so a bug in the
 * access-reconciliation path cannot regress this one.
 *
 * @template-implements IEventListener<Event>
 */
class FileCreatedListener implements IEventListener {
	public function __construct(
		private IAppConfig $appConfig,
		private IRootFolder $rootFolder,
		private IJobList $jobList,
	) {
	}

	public function handle(Event $event): void {
		if ($this->appConfig->getValueString('facewright_bridge', 'automatic_detection_enabled', 'false') !== 'true') {
			return;
		}

		if ($event instanceof NodeCreatedEvent) {
			$this->handleNode($event->getNode());
			return;
		}
		if ($event instanceof CacheEntryInsertedEvent) {
			$node = $this->rootFolder->getFirstNodeById($event->getFileId());
			if ($node !== null) {
				$this->handleNode($node);
			}
		}
	}

	private function handleNode(Node $node): void {
		if (!in_array($node->getMimetype(), DetectionService::SUPPORTED_MIMETYPES, true)) {
			return;
		}
		// No owner lookup any more. The job resolves every user with access itself, which is both
		// more complete (a file's storage owner is not necessarily someone who can see it, and is
		// never the only one who can) and cheaper here - this runs inside the upload request.
		$this->jobList->add(DetectQueuedJob::class, ['fileId' => $node->getId()]);
	}
}

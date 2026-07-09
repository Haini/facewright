<?php

declare(strict_types=1);

namespace OCA\RecognaizeBridge\Listener;

use OCA\RecognaizeBridge\BackgroundJob\DetectQueuedJob;
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
 * Deliberately not handled this phase (unlike Recognize's own listener): renames, deletes,
 * mount add/remove - matches "Multi-user + robustness" being a separate, later phase.
 *
 * @template-implements IEventListener<Event>
 */
class FileCreatedListener implements IEventListener {
	private const IMAGE_MIMETYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];

	public function __construct(
		private IAppConfig $appConfig,
		private IRootFolder $rootFolder,
		private IJobList $jobList,
	) {
	}

	public function handle(Event $event): void {
		if ($this->appConfig->getValueString('recognaize_bridge', 'automatic_detection_enabled', 'false') !== 'true') {
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
		if (!in_array($node->getMimetype(), self::IMAGE_MIMETYPES, true)) {
			return;
		}
		$owner = $node->getOwner();
		if ($owner === null) {
			return;
		}
		$this->jobList->add(DetectQueuedJob::class, ['userId' => $owner->getUID(), 'fileId' => $node->getId()]);
	}
}

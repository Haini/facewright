<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Listener;

use OCA\FacewrightBridge\BackgroundJob\BatchScanJob;
use OCA\FacewrightBridge\BackgroundJob\DetectQueuedJob;
use OCA\FacewrightBridge\Service\DetectionService;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Cache\CacheEntryInsertedEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Folder;
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
 * Also listens to NodeRenamedEvent, because a move is how most photos actually *enter* an included
 * path on this instance: the phone auto-uploads into SofortUpload/ (not included, so the create-time
 * job correctly skips it), and the user later sorts them into Bilder/. Neither create event fires
 * again for a move, so without this the only thing that ever noticed was the hourly
 * DetectionMaintenanceJob sweep. Enqueuing on every rename is cheap and safe: DetectQueuedJob
 * re-evaluates included paths and already-processed state at run time, so an in-place rename of a
 * file that was done long ago is a no-op job, not a re-detection.
 *
 * Access reconciliation for renames, deletes and mount add/remove lives in FileAccessListener, kept
 * separate so a bug in that path cannot regress this one.
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
			return;
		}
		if ($event instanceof NodeRenamedEvent) {
			$target = $event->getTarget();
			if ($target instanceof Folder) {
				// A folder move can bring any number of files into an included path, and enumerating
				// them here would put a subtree walk inside the MOVE request. The scan chain already
				// does exactly that walk on cron, and IJobList::add() dedupes on class+argument, so
				// using the same argument as DetectionMaintenanceJob makes this a no-op when a chain
				// is already pending - it just pulls the next sweep forward from "within the hour"
				// to "next cron tick".
				$chunkSize = $this->appConfig->getValueInt('facewright_bridge', 'batch_size', 200);
				$this->jobList->add(BatchScanJob::class, ['chunkSize' => $chunkSize]);
				return;
			}
			$this->handleNode($target);
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

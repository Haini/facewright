<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\BackgroundJob;

use OCA\FacewrightBridge\Service\AccessService;
use OCA\FacewrightBridge\Service\AccessSyncService;
use OCA\FacewrightBridge\Service\DetectionService;
use OCA\FacewrightBridge\Service\PathFilterService;
use OCA\FacewrightBridge\Service\ProcessedFileService;
use OCA\FacewrightBridge\Service\UserSettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Drains the queue FileCreatedListener (or the batch trigger) fills. Runs on Nextcloud's own
 * cron - confirmed genuinely running on this instance - not synchronously with the upload
 * request, since detection can take several seconds per file.
 *
 * The argument is keyed on the file alone, not on (user, file). IJobList::add() dedupes on class
 * plus argument, so {fileId} collapses to a single job no matter how many users' folder walks
 * reached the same photo - which is the point: one inference feeds every user with access. The old
 * {userId, fileId} shape produced one job, and therefore one inference, per user.
 *
 * Jobs queued under the old shape keep working untouched: fileId is present in both, and userId
 * degrades to a hint for AccessService::pickFetchUser(), which ignores it if that user no longer
 * has access. Nothing in oc_jobs needs rewriting. The fetch user has to be recomputed at run time
 * anyway - a job can sit in the queue for cron cycles, and the share that made a given user viable
 * can be revoked in the meantime.
 *
 * @template-extends QueuedJob<array{fileId: int, attempt?: int, userId?: string}>
 */
class DetectQueuedJob extends QueuedJob {
	/**
	 * How many transient failures a single file gets before it is written off. Without a cap, a
	 * file that always fails transiently is retried on every backlog pass forever - the failure
	 * mode that once produced 5,595 identical warnings from 5 files. Fan-out makes this more
	 * likely, not less: the fetch user can now be someone whose share was revoked between enqueue
	 * and run, and the ExApp reports the resulting DAV 403 as a 502, which is classified retryable.
	 */
	private const MAX_ATTEMPTS = 5;

	public function __construct(
		ITimeFactory $time,
		private IRootFolder $rootFolder,
		private PathFilterService $pathFilter,
		private UserSettingsService $userSettings,
		private DetectionService $detectionService,
		private ProcessedFileService $processedFiles,
		private AccessService $accessService,
		private AccessSyncService $accessSync,
		private LoggerInterface $logger,
		private IAppConfig $appConfig,
		private IJobList $jobList,
	) {
		parent::__construct($time);
	}

	/** @param array{fileId: int, attempt?: int, userId?: string} $argument */
	protected function run($argument): void {
		// QueuedJobs are removed from the queue once run() returns, so a paused job has to
		// re-add itself to survive until the next tick - see BatchScanJob for the same pattern,
		// and PeopleSettingsController::setProcessingPaused for how this flag gets set.
		if ($this->appConfig->getValueString('facewright_bridge', 'processing_paused', 'false') === 'true') {
			$this->jobList->add(self::class, $argument);
			return;
		}

		$fileId = (int)($argument['fileId'] ?? 0);
		if ($fileId === 0) {
			return;
		}
		$attempt = (int)($argument['attempt'] ?? 0);

		// One mount-cache query answers both "who can see this" and "where is it in each of their
		// trees", so no per-user filesystem has to be set up. That replaces the old
		// getUserFolder()/getFirstNodeById()/getRelativePath() trio, which cost a filesystem setup
		// per job.
		$access = $this->accessService->getAccessPaths($fileId);
		if ($access === []) {
			$this->logger->debug("facewright: file {$fileId} is no longer visible to any user, skipping.");
			return;
		}

		// Checked here (not in the listener/enumeration step) since the queue can sit for one or
		// more cron cycles - this is the freshest possible read of every setting involved. The
		// allowlist is per user now, so the question is no longer "is the fetch user's path
		// included" but "does anyone who opted in want this file", evaluated against each user's
		// own path. getAccessPaths() already returned all of them, so this costs no extra query.
		$interested = array_filter(
			$access,
			fn (string $path, string $userId): bool => $this->userSettings->isEnabled($userId)
				&& $this->pathFilter->isIncluded($userId, $path),
			ARRAY_FILTER_USE_BOTH,
		);
		if ($interested === []) {
			return;
		}

		// Restricted to that set: the ExApp downloads the file as the fetch user, so picking one
		// who excluded it would ask for a path outside their own allowlist and defeat the gate.
		$fetch = $this->accessService->pickFetchUser($fileId, $argument['userId'] ?? null, array_keys($interested));
		if ($fetch === null) {
			return;
		}

		$node = $this->rootFolder->getFirstNodeById($fileId);
		if ($node === null) {
			return;
		}
		if (!in_array($node->getMimetype(), DetectionService::SUPPORTED_MIMETYPES, true)) {
			// Re-checked here as well as at both enqueue sites: the queue can outlive a change to
			// the supported list, and a file's mimetype can be corrected by a later rescan.
			return;
		}
		if ($node->getSize() === 0) {
			// Nothing to send, and worth catching on this side: an empty body makes OpenCV raise
			// rather than return None, which surfaces as a 500 and so reads as retryable. Marked
			// processed rather than merely skipped, because unlike an unsupported mimetype this
			// is a broken file rather than a capability we might gain later.
			$this->logger->warning("facewright: skipping zero-byte file {$fetch['path']} (fileId {$fileId}).");
			$this->processedFiles->markManyProcessed(array_keys($access), $fileId, ProcessedFileService::FACES_FAILED);
			return;
		}

		// Replaces the old single isProcessed() check. Under fan-out "has this file been processed"
		// is no longer a property of the file: one long since done for its owner still needs rows
		// for someone it was shared with yesterday.
		$pending = array_values(array_diff(array_keys($access), $this->processedFiles->getProcessedUsers($fileId)));
		if ($pending === []) {
			return;
		}

		// If this file has already been through detection for anyone, reuse that result rather than
		// paying for inference again - whether that result was rows to copy or a marker saying it
		// had no faces. This is what makes a share arriving through the detection queue as cheap as
		// one arriving through a mount event.
		$existing = $this->accessSync->copyExisting($fileId, $pending);
		if ($existing['handled']) {
			$this->logger->info(
				"facewright: reused existing detection for file {$fileId} across " . count($pending)
				. " user(s) ({$existing['copied']} row(s) copied)."
			);
			return;
		}

		$result = $this->detectionService->detect($fetch['userId'], $fetch['path'], $fileId, $pending);
		if (!$result['success']) {
			$this->logger->warning("facewright: detection failed for {$fetch['path']} (fileId {$fileId}): {$result['error']}");
			if ($result['permanent']) {
				// Record it so the backlog scan stops re-enqueueing a file that cannot succeed.
				// Without this a handful of undecodable files are retried on every pass forever:
				// on this instance 5 such files produced 5,595 identical warnings.
				$this->processedFiles->markManyProcessed($pending, $fileId, ProcessedFileService::FACES_FAILED);
				return;
			}
			// Transient failures deliberately stay unmarked so they are picked up again - but only
			// a bounded number of times. A four-hour DNS outage on this host once failed every
			// detection it touched; marking those would have written the whole window off, while
			// retrying without a cap is the forever-loop above wearing a different hat.
			if ($attempt + 1 >= self::MAX_ATTEMPTS) {
				$this->logger->error(
					'facewright: giving up on file ' . $fileId . ' after ' . self::MAX_ATTEMPTS
					. " transient failures: {$result['error']}"
				);
				$this->processedFiles->markManyProcessed($pending, $fileId, ProcessedFileService::FACES_FAILED);
				return;
			}
			// A distinct argument, so this does not dedupe against a freshly enqueued job for the
			// same file - a retry should be its own queue entry, and a duplicate would no-op at the
			// $pending check above anyway.
			$this->jobList->add(self::class, ['fileId' => $fileId, 'attempt' => $attempt + 1]);
			return;
		}

		$this->logger->info(
			"facewright: {$result['faces']} face(s) in {$fetch['path']} -> {$result['inserted']} row(s) across {$result['users']} user(s)."
		);
	}
}

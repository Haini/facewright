<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\BackgroundJob;

use OCA\FacewrightBridge\Service\AccessSyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Reconciles one mount's files against who can currently see them, a page at a time, self-requeuing
 * on a file-id cursor - the same shape as BatchScanJob, for the same reason: a newly shared folder
 * can hold tens of thousands of photos and none of that work belongs in the request that created
 * the share.
 *
 * Deliberately no queue table, unlike Recognize's oc_recognize_fs_actions. Our unit of work is
 * (storageId, rootId) - two integers, nowhere near IJobList's 32,000-character argument limit - and
 * IJobList::add() already dedupes on class plus argument, which hands us the "one pending action
 * per mount" property Recognize needs a mapper to get. We also recompute the access set when the
 * job runs rather than snapshotting it at event time, which is strictly more correct if several
 * share changes land in quick succession.
 *
 * @template-extends QueuedJob<array{storageId: int, rootId: int, afterFileId?: int, prune?: bool}>
 */
class AccessSyncJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private AccessSyncService $accessSync,
		private IJobList $jobList,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	/** @param array{storageId: int, rootId: int, afterFileId?: int, prune?: bool} $argument */
	protected function run($argument): void {
		if ($this->appConfig->getValueString('facewright_bridge', 'processing_paused', 'false') === 'true') {
			$this->jobList->add(self::class, $argument);
			return;
		}

		$storageId = (int)($argument['storageId'] ?? 0);
		$rootId = (int)($argument['rootId'] ?? 0);
		$afterFileId = (int)($argument['afterFileId'] ?? 0);
		$prune = (bool)($argument['prune'] ?? true);
		if ($storageId === 0 || $rootId === 0) {
			return;
		}

		// Read from config rather than carried in the argument, so a chain that turns out to be too
		// heavy can be re-tuned without draining the queue first. (BatchScanJob carries its chunk
		// size because there the argument is otherwise constant and carrying it is what makes its
		// self-dedup work; here the cursor already varies.)
		$chunkSize = max(1, $this->appConfig->getValueInt('facewright_bridge', 'access_sync_chunk_size', 500));

		$result = $this->accessSync->reconcileMount($storageId, $rootId, $afterFileId, $chunkSize, false, $prune);

		if ($result['copied'] > 0 || $result['pruned'] > 0) {
			$this->logger->info(
				"facewright: access sync (storage {$storageId}, root {$rootId}) examined {$result['examined']} file(s), "
				. "copied {$result['copied']} row(s), pruned {$result['pruned']}."
			);
		}

		// Two stop conditions, and the second one matters as much as the first: if the cursor
		// failed to advance, re-adding would ask the identical question forever.
		if ($result['examined'] < $chunkSize || $result['lastFileId'] === null || $result['lastFileId'] <= $afterFileId) {
			return;
		}

		$this->jobList->add(self::class, [
			'storageId' => $storageId,
			'rootId' => $rootId,
			'afterFileId' => $result['lastFileId'],
			'prune' => $prune,
		]);
	}
}

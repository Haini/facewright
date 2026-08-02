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
 * Single-file counterpart to AccessSyncJob, for events that name one node rather than a mount - a
 * move into or out of a shared folder, which changes who can see that one file without any mount
 * being added or removed.
 *
 * @template-extends QueuedJob<array{fileId: int}>
 */
class FileAccessSyncJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private AccessSyncService $accessSync,
		private IJobList $jobList,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	/** @param array{fileId: int} $argument */
	protected function run($argument): void {
		if ($this->appConfig->getValueString('facewright_bridge', 'processing_paused', 'false') === 'true') {
			$this->jobList->add(self::class, $argument);
			return;
		}

		$fileId = (int)($argument['fileId'] ?? 0);
		if ($fileId === 0) {
			return;
		}

		$result = $this->accessSync->reconcileFile($fileId);
		if ($result['copied'] > 0 || $result['pruned'] > 0) {
			$this->logger->info(
				"facewright: access sync for file {$fileId} copied {$result['copied']} row(s), pruned {$result['pruned']}."
			);
		}
	}
}

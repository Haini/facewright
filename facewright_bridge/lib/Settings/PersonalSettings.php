<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Settings;

use OCA\FacewrightBridge\BackgroundJob\BatchScanJob;
use OCA\FacewrightBridge\Service\ClusteringService;
use OCA\FacewrightBridge\Service\ClusterTriggerService;
use OCA\FacewrightBridge\Service\ProcessedFileService;
use OCA\FacewrightBridge\Service\UserSettingsService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\Settings\ISettings;
use OCP\Util;

/**
 * Each user's own Facewright settings: whether face recognition runs for them at all, which of
 * their folders it looks at, their own backlog scan, and their own clustering thresholds.
 *
 * Everything shown is scoped to the session user. Instance-wide state appears only where it
 * explains why nothing is happening (paused, or automatic detection off) - read-only, since those
 * are the admin's to change.
 */
class PersonalSettings implements ISettings {
	public function __construct(
		private ?string $userId,
		private UserSettingsService $userSettings,
		private ProcessedFileService $processedFiles,
		private ClusteringService $clustering,
		private ClusterTriggerService $clusterTrigger,
		private IAppConfig $appConfig,
		private ITimeFactory $timeFactory,
		private IJobList $jobList,
	) {
	}

	public function getForm(): TemplateResponse {
		$userId = $this->userId ?? '';
		$enabled = $userId !== '' && $this->userSettings->isEnabled($userId);

		// Deliberately the single-user count, not countCandidates(): that one walks every opted-in
		// user's library, which would make one person opening their own settings page pay for an
		// instance-wide filesystem enumeration.
		$candidates = $userId === '' ? 0 : $this->processedFiles->countCandidatesForUser($userId);
		$processed = $userId === '' ? 0 : $this->processedFiles->countProcessedFilesForUser($userId);

		Util::addScript('facewright_bridge', 'personal');

		return new TemplateResponse('facewright_bridge', 'personal', [
			'enabled' => $enabled,
			'paths' => $userId === '' ? '' : implode("\n", $this->userSettings->getIncludedPaths($userId)),
			'clusterMaxDistance' => $userId === '' ? 0.5 : $this->userSettings->getClusterMaxDistance($userId),
			'clusterMinFaces' => $userId === '' ? 3 : $this->userSettings->getClusterMinFaces($userId),
			'chunkSize' => $this->appConfig->getValueInt('facewright_bridge', 'batch_size', 50),
			'clusterChunkSize' => $this->appConfig->getValueInt('facewright_bridge', 'cluster_chunk_size', 300),
			'processedCount' => $processed,
			'unprocessedCount' => max(0, $candidates - $processed),
			'processedLastHour' => $userId === ''
				? 0
				: $this->processedFiles->countProcessedSinceForUser($userId, $this->timeFactory->getTime() - 3600),
			'unclusteredCount' => $userId === '' ? 0 : ($this->clustering->countUnclusteredByUser()[$userId] ?? 0),
			'scanActive' => $userId !== '' && $this->hasScanChain($userId),
			'clusteringActive' => $userId !== '' && isset($this->clusterTrigger->usersWithPendingClusterScan()[$userId]),
			'processingPaused' => $this->appConfig->getValueString('facewright_bridge', 'processing_paused', 'false') === 'true',
			'automaticDetectionEnabled' => $this->appConfig->getValueString('facewright_bridge', 'automatic_detection_enabled', 'false') === 'true',
		], '');
	}

	public function getSection(): string {
		return 'facewright_bridge';
	}

	public function getPriority(): int {
		return 10;
	}

	/**
	 * Whether a scan chain is alive for this user. BatchScanJob's argument carries an optional
	 * userId, so this has to inspect arguments rather than count by class the way the admin page
	 * does - the same technique ClusterTriggerService uses for its in-flight guard.
	 */
	private function hasScanChain(string $userId): bool {
		foreach ($this->jobList->getJobsIterator(BatchScanJob::class, null, 0) as $job) {
			$argument = $job->getArgument();
			if (is_array($argument) && ($argument['userId'] ?? null) === $userId) {
				return true;
			}
		}
		return false;
	}
}

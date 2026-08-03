<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Settings;

use OCA\FacewrightBridge\BackgroundJob\BatchScanJob;
use OCA\FacewrightBridge\BackgroundJob\ClusterScanJob;
use OCA\FacewrightBridge\BackgroundJob\DetectQueuedJob;
use OCA\FacewrightBridge\Service\ClusteringService;
use OCA\FacewrightBridge\Service\ProcessedFileService;
use OCA\FacewrightBridge\Service\UserSettingsService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Settings\ISettings;
use OCP\Util;

class AdminSettings implements ISettings {
	public function __construct(
		private IAppConfig $appConfig,
		private UserSettingsService $userSettings,
		private ProcessedFileService $processedFiles,
		private ClusteringService $clustering,
		private ITimeFactory $timeFactory,
		private IJobList $jobList,
		private IUserManager $userManager,
	) {
	}

	public function getForm(): TemplateResponse {
		$enabled = $this->appConfig->getValueString('recognize', 'faces.enabled', 'false') === 'true';
		$automaticDetectionEnabled = $this->appConfig->getValueString('facewright_bridge', 'automatic_detection_enabled', 'false') === 'true';
		$automaticClusteringEnabled = $this->appConfig->getValueString('facewright_bridge', 'automatic_clustering_enabled', 'true') === 'true';
		$processingPaused = $this->appConfig->getValueString('facewright_bridge', 'processing_paused', 'false') === 'true';
		$batchSize = $this->appConfig->getValueInt('facewright_bridge', 'batch_size', 50);
		$clusterChunkSize = $this->appConfig->getValueInt('facewright_bridge', 'cluster_chunk_size', 300);

		// Files, not rows. Under fan-out one file yields a marker per user with access, so the row
		// count runs ahead of the candidate count and "candidates minus processed" would clamp to
		// zero and stop being a progress indicator. The row count is still worth showing - it is
		// what explains the tables' growth - but as its own line, not as half of a subtraction.
		$processedFileCount = $this->processedFiles->countProcessedFiles();
		$processedRecordCount = $this->processedFiles->countProcessed();
		$candidates = $this->processedFiles->countCandidates();
		$unclusteredByUser = $this->clustering->countUnclusteredByUser();

		Util::addScript('facewright_bridge', 'admin');
		return new TemplateResponse('facewright_bridge', 'admin', [
			'enabled' => $enabled,
			'automaticDetectionEnabled' => $automaticDetectionEnabled,
			'automaticClusteringEnabled' => $automaticClusteringEnabled,
			'processingPaused' => $processingPaused,
			'batchSize' => $batchSize,
			'clusterMaxDistance' => $this->userSettings->defaultClusterMaxDistance(),
			'clusterMinFaces' => $this->userSettings->defaultClusterMinFaces(),
			'clusterChunkSize' => $clusterChunkSize,
			'processedLastHour' => $this->processedFiles->countProcessedSince($this->timeFactory->getTime() - 3600),
			'processedCount' => $processedFileCount,
			'processedRecordCount' => $processedRecordCount,
			'unprocessedCount' => max(0, $candidates['total'] - $processedFileCount),
			'unclusteredCount' => array_sum($unclusteredByUser),
			'queuedDetections' => $this->countJobs(DetectQueuedJob::class),
			'scanActive' => $this->countJobs(BatchScanJob::class) > 0,
			'clusteringActive' => $this->countJobs(ClusterScanJob::class) > 0,
			'users' => $this->userRows($candidates['perUser'], $unclusteredByUser),
		], '');
	}

	public function getSection(): string {
		return 'facewright_bridge';
	}

	public function getPriority(): int {
		return 10;
	}

	/**
	 * Read-only, one row per seen user - enough to answer "why is this person getting nothing"
	 * without being able to change their settings, which are theirs. Deliberately no new AJAX
	 * endpoint or route: it is read-once-per-page-load data, and every new routes.php entry on this
	 * instance costs a container restart (see CLAUDE.md).
	 *
	 * The candidate counts are handed in rather than recomputed, because they come from the single
	 * countCandidates() walk the aggregate stats already paid for.
	 *
	 * @param array<string, int> $candidatesPerUser
	 * @param array<string, int> $unclusteredByUser
	 *
	 * @return list<array{userId: string, displayName: string, enabled: bool, paths: list<string>, processed: int, candidates: int, unclustered: int}>
	 */
	private function userRows(array $candidatesPerUser, array $unclusteredByUser): array {
		/** @var array<string, string> $displayNames */
		$displayNames = [];
		$this->userManager->callForSeenUsers(function (IUser $user) use (&$displayNames): void {
			$displayNames[$user->getUID()] = $user->getDisplayName();
		});

		// A user can hold detections or a preference row without being "seen" any more (or without
		// ever having been counted as such), and leaving them off the table would hide exactly the
		// accounts worth looking at.
		foreach (array_keys($unclusteredByUser) as $userId) {
			$displayNames[$userId] ??= $userId;
		}
		foreach ($this->userSettings->enabledUsers() as $userId) {
			$displayNames[$userId] ??= $userId;
		}
		ksort($displayNames);

		$settings = $this->userSettings->overview(array_keys($displayNames));

		$rows = [];
		foreach ($displayNames as $userId => $displayName) {
			$rows[] = [
				'userId' => $userId,
				'displayName' => $displayName,
				'enabled' => $settings[$userId]['enabled'] ?? false,
				'paths' => $settings[$userId]['paths'] ?? [],
				'processed' => $this->processedFiles->countProcessedFilesForUser($userId),
				'candidates' => $candidatesPerUser[$userId] ?? 0,
				'unclustered' => $unclusteredByUser[$userId] ?? 0,
			];
		}
		return $rows;
	}

	/** @param class-string $class */
	private function countJobs(string $class): int {
		foreach ($this->jobList->countByClass() as $row) {
			if ($row['class'] === $class) {
				return $row['count'];
			}
		}
		return 0;
	}
}

<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Settings;

use OCA\FacewrightBridge\BackgroundJob\BatchScanJob;
use OCA\FacewrightBridge\BackgroundJob\ClusterScanJob;
use OCA\FacewrightBridge\BackgroundJob\DetectQueuedJob;
use OCA\FacewrightBridge\Service\ClusteringService;
use OCA\FacewrightBridge\Service\PathFilterService;
use OCA\FacewrightBridge\Service\ProcessedFileService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\Settings\ISettings;
use OCP\Util;

class AdminSettings implements ISettings {
	public function __construct(
		private IAppConfig $appConfig,
		private PathFilterService $pathFilter,
		private ProcessedFileService $processedFiles,
		private ClusteringService $clustering,
		private ITimeFactory $timeFactory,
		private IJobList $jobList,
	) {
	}

	public function getForm(): TemplateResponse {
		$enabled = $this->appConfig->getValueString('recognize', 'faces.enabled', 'false') === 'true';
		$paths = implode("\n", $this->pathFilter->getIncludedPaths());
		$automaticDetectionEnabled = $this->appConfig->getValueString('facewright_bridge', 'automatic_detection_enabled', 'false') === 'true';
		$automaticClusteringEnabled = $this->appConfig->getValueString('facewright_bridge', 'automatic_clustering_enabled', 'true') === 'true';
		$processingPaused = $this->appConfig->getValueString('facewright_bridge', 'processing_paused', 'false') === 'true';
		$batchSize = $this->appConfig->getValueInt('facewright_bridge', 'batch_size', 50);
		$clusterMaxDistance = $this->appConfig->getValueFloat('facewright_bridge', 'cluster_max_distance', 0.5);
		$clusterMinFaces = $this->appConfig->getValueInt('facewright_bridge', 'cluster_min_faces', 3);
		$clusterChunkSize = $this->appConfig->getValueInt('facewright_bridge', 'cluster_chunk_size', 300);

		// Files, not rows. Under fan-out one file yields a marker per user with access, so the row
		// count runs ahead of the candidate count and "candidates minus processed" would clamp to
		// zero and stop being a progress indicator. The row count is still worth showing - it is
		// what explains the tables' growth - but as its own line, not as half of a subtraction.
		$processedFileCount = $this->processedFiles->countProcessedFiles();
		$processedRecordCount = $this->processedFiles->countProcessed();
		$candidateCount = $this->processedFiles->countCandidates();
		$unclusteredCount = array_sum($this->clustering->countUnclusteredByUser());
		Util::addScript('facewright_bridge', 'admin');
		return new TemplateResponse('facewright_bridge', 'admin', [
			'enabled' => $enabled,
			'paths' => $paths,
			'automaticDetectionEnabled' => $automaticDetectionEnabled,
			'automaticClusteringEnabled' => $automaticClusteringEnabled,
			'processingPaused' => $processingPaused,
			'batchSize' => $batchSize,
			'clusterMaxDistance' => $clusterMaxDistance,
			'clusterMinFaces' => $clusterMinFaces,
			'clusterChunkSize' => $clusterChunkSize,
			'processedLastHour' => $this->processedFiles->countProcessedSince($this->timeFactory->getTime() - 3600),
			'processedCount' => $processedFileCount,
			'processedRecordCount' => $processedRecordCount,
			'unprocessedCount' => max(0, $candidateCount - $processedFileCount),
			'unclusteredCount' => $unclusteredCount,
			'queuedDetections' => $this->countJobs(DetectQueuedJob::class),
			'scanActive' => $this->countJobs(BatchScanJob::class) > 0,
			'clusteringActive' => $this->countJobs(ClusterScanJob::class) > 0,
		], '');
	}

	public function getSection(): string {
		return 'facewright_bridge';
	}

	public function getPriority(): int {
		return 10;
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

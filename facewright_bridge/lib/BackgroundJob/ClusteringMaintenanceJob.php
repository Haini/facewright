<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\BackgroundJob;

use OCA\FacewrightBridge\Service\ClusterTriggerService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Facewright's own counterpart to Recognize's own MaintenanceJob (see CLAUDE.md's "clustering
 * trigger" and "missing pictures" investigations) - but scoped correctly: Recognize's version
 * treats every user with any cluster_id IS NULL detection as its concern, including our 512-dim
 * rows it can't actually cluster (it crashes on them), whereas this only ever looks at
 * Facewright's own data via ClusteringService::countUnclusteredByUser().
 *
 * Exists because detection runs continuously (FileCreatedListener + BatchScanJob), but
 * ClusterScanJob is a self-terminating chain that stops once a pass makes no further progress -
 * nothing restarts it when new detections land afterward. That silently left thousands of
 * newly-detected faces unclustered (and therefore invisible on Memories' People page, which only
 * ever shows clustered faces - see RecognizeBackend::getPhotos()'s INNER JOIN) until an admin
 * manually clicked "Run now" again. This runs periodically and does exactly that, automatically,
 * for any user who has accumulated new unclustered work since the last chain finished.
 *
 * Defaults to enabled, unlike automatic detection (automatic_detection_enabled defaults off) -
 * that toggle was a real behavioral jump from "nothing happens automatically" to "everything gets
 * scanned". This isn't a new capability, just keeping an already-running pipeline (detection ->
 * clustering) from silently stalling partway through, so there's less reason to make it opt-in.
 */
class ClusteringMaintenanceJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private ClusterTriggerService $clusterTrigger,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(60 * 60);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		if ($this->appConfig->getValueString('facewright_bridge', 'automatic_clustering_enabled', 'true') !== 'true') {
			return;
		}
		if ($this->appConfig->getValueString('facewright_bridge', 'processing_paused', 'false') === 'true') {
			return;
		}

		$maxDistance = $this->appConfig->getValueFloat('facewright_bridge', 'cluster_max_distance', 0.5);
		$minFaces = $this->appConfig->getValueInt('facewright_bridge', 'cluster_min_faces', 3);
		$chunkSize = $this->appConfig->getValueInt('facewright_bridge', 'cluster_chunk_size', 300);

		$started = $this->clusterTrigger->startForUsersWithUnclustered($maxDistance, $minFaces, $chunkSize);
		if ($started > 0) {
			$this->logger->info("facewright: automatically (re-)started clustering for {$started} user(s) with unclustered faces.");
		}
	}
}

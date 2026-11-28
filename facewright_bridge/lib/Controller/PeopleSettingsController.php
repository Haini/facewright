<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Controller;

use OCA\FacewrightBridge\BackgroundJob\BatchScanJob;
use OCA\FacewrightBridge\Service\BatchTriggerService;
use OCA\FacewrightBridge\Service\ClusterTriggerService;
use OCA\FacewrightBridge\Service\PeopleService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IRequest;

/**
 * Backs the Facewright admin settings page: automatic detection, pausing, the batch and scan
 * triggers, clustering and the merge form. (The name is from when it only handled a toggle;
 * renaming the class would change every route, see CLAUDE.md on routes.php changes needing a
 * container restart.)
 *
 * Everything here is instance-wide. Per-user settings (whether face recognition runs for someone
 * at all, which of their folders it looks at, their clustering thresholds) live on
 * PersonalSettingsController, and are deliberately not editable from here.
 *
 * Routes registered in appinfo/routes.php (mirrors Recognize's own convention).
 * No #[NoAdminRequired] - Controller actions require admin by default.
 */
class PeopleSettingsController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IAppConfig $appConfig,
		private BatchTriggerService $batchTrigger,
		private PeopleService $people,
		private ClusterTriggerService $clusterTrigger,
		private IJobList $jobList,
	) {
		parent::__construct($appName, $request);
	}

	public function setAutomaticDetectionEnabled(bool $enabled): JSONResponse {
		$this->appConfig->setValueString('facewright_bridge', 'automatic_detection_enabled', $enabled ? 'true' : 'false');
		return new JSONResponse(['enabled' => $enabled]);
	}

	/**
	 * Pauses facewright's own background jobs (DetectQueuedJob, BatchScanJob) - they check this
	 * flag themselves and re-queue without doing work while it's set, see those classes. Only
	 * affects facewright's own jobs, not Nextcloud's cron or any other app's background jobs.
	 */
	public function setProcessingPaused(bool $paused): JSONResponse {
		$this->appConfig->setValueString('facewright_bridge', 'processing_paused', $paused ? 'true' : 'false');
		return new JSONResponse(['paused' => $paused]);
	}

	public function runBatch(int $batchSize): JSONResponse {
		$batchSize = max(1, $batchSize);
		$this->appConfig->setValueInt('facewright_bridge', 'batch_size', $batchSize);
		$queued = $this->batchTrigger->run($batchSize);
		return new JSONResponse(['queued' => $queued]);
	}

	/**
	 * Starts (or, if already running, harmlessly no-ops against - see BatchScanJob) a
	 * self-requeuing scan of the entire backlog, in chunks of $chunkSize, rather than trying to
	 * enqueue everything in this one request the way a huge runBatch() batchSize would.
	 */
	public function runScan(int $chunkSize): JSONResponse {
		$chunkSize = max(1, $chunkSize);
		$this->appConfig->setValueInt('facewright_bridge', 'batch_size', $chunkSize);
		$this->jobList->add(BatchScanJob::class, ['chunkSize' => $chunkSize]);
		return new JSONResponse(['started' => true]);
	}

	/**
	 * Manual counterpart to ClusteringMaintenanceJob's automatic re-trigger - both go through
	 * ClusterTriggerService so "who needs a chain started" is defined exactly once. Only starts
	 * chains for users who actually have unclustered Facewright detections (see
	 * ClusteringService::countUnclusteredByUser()), skipping anyone who already has one in
	 * flight.
	 *
	 * $maxDistance/$minFaces are saved as the instance *defaults* - what a user who never touches
	 * their own settings page inherits. They are not applied to the chains started here: users who
	 * did set their own would otherwise have them silently ignored by an admin clicking this button.
	 */
	public function runClustering(float $maxDistance, int $minFaces, int $chunkSize = 300): JSONResponse {
		$maxDistance = max(0.0, $maxDistance);
		$minFaces = max(1, $minFaces);
		$chunkSize = max(1, $chunkSize);
		$this->appConfig->setValueFloat('facewright_bridge', 'cluster_max_distance', $maxDistance);
		$this->appConfig->setValueInt('facewright_bridge', 'cluster_min_faces', $minFaces);
		$this->appConfig->setValueInt('facewright_bridge', 'cluster_chunk_size', $chunkSize);

		$started = $this->clusterTrigger->startForUsersWithUnclustered($chunkSize);

		return new JSONResponse(['started' => $started]);
	}

	/**
	 * Gates ClusteringMaintenanceJob - see that class for why it defaults to enabled, unlike
	 * automatic_detection_enabled.
	 */
	public function setAutomaticClusteringEnabled(bool $enabled): JSONResponse {
		$this->appConfig->setValueString('facewright_bridge', 'automatic_clustering_enabled', $enabled ? 'true' : 'false');
		return new JSONResponse(['enabled' => $enabled]);
	}

	/**
	 * See PeopleService::mergeClusters() - does what Recognize's own rename-based DAV UI has no
	 * path to at all (renaming a cluster to an existing name is treated as an error there, not a
	 * merge). $keep/$merge each accept either a numeric cluster ID or a person's name. Admin-only
	 * and cross-user ($userId is a request parameter, unlike every personal-settings action) -
	 * the one place in this app an admin can act on another user's clusters.
	 */
	public function mergeClusters(string $userId, string $keep, string $merge): JSONResponse {
		try {
			$result = $this->people->mergeClusters($userId, $keep, $merge);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], 400);
		}
		return new JSONResponse(['moved' => $result['moved']]);
	}
}

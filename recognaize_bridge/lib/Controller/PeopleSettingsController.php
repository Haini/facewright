<?php

declare(strict_types=1);

namespace OCA\RecognaizeBridge\Controller;

use OCA\RecognaizeBridge\Service\BatchTriggerService;
use OCA\RecognaizeBridge\Service\ClusteringService;
use OCA\RecognaizeBridge\Service\PathFilterService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;

/**
 * Backs the recognAIze admin settings page (kept the name from when it only handled the
 * People-page toggle; now also handles included-paths, automatic-detection, and the batch
 * trigger - not worth the risk of renaming the class/route once routing was working, see
 * CLAUDE.md's "stale registration" gotcha).
 *
 * setEnabled: toggles Memories' People page visibility. Memories checks Recognize's own
 * `faces.enabled` appconfig key directly (Util::recognizeIsEnabled()), but Recognize's own
 * admin toggle for that key (SettingsService::setSetting) also re-schedules Recognize's own
 * real face classifier crawl - which recognAIze exists to replace, not race. So this writes
 * the same appconfig key directly, bypassing Recognize's own service and that side effect.
 *
 * Routes registered in appinfo/routes.php (mirrors Recognize's own convention).
 * No #[NoAdminRequired] - Controller actions require admin by default.
 */
class PeopleSettingsController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IAppConfig $appConfig,
		private PathFilterService $pathFilter,
		private BatchTriggerService $batchTrigger,
		private ClusteringService $clustering,
	) {
		parent::__construct($appName, $request);
	}

	public function setEnabled(bool $enabled): JSONResponse {
		$this->appConfig->setValueString('recognize', 'faces.enabled', $enabled ? 'true' : 'false');
		return new JSONResponse(['enabled' => $enabled]);
	}

	/** @param string $paths newline-separated list of paths */
	public function setIncludedPaths(string $paths): JSONResponse {
		$this->pathFilter->setIncludedPaths(preg_split('/\r\n|\r|\n/', $paths) ?: []);
		return new JSONResponse(['paths' => $this->pathFilter->getIncludedPaths()]);
	}

	public function setAutomaticDetectionEnabled(bool $enabled): JSONResponse {
		$this->appConfig->setValueString('recognaize_bridge', 'automatic_detection_enabled', $enabled ? 'true' : 'false');
		return new JSONResponse(['enabled' => $enabled]);
	}

	public function runBatch(int $batchSize): JSONResponse {
		$batchSize = max(1, $batchSize);
		$this->appConfig->setValueInt('recognaize_bridge', 'batch_size', $batchSize);
		$queued = $this->batchTrigger->run($batchSize);
		return new JSONResponse(['queued' => $queued]);
	}

	/**
	 * Runs synchronously (unlike detection, this is pure local DB/CPU work against
	 * already-stored embeddings - no ExApp round-trip - so it's fast enough not to need a
	 * background job at this project's current data scale, see CLAUDE.md).
	 */
	public function runClustering(float $maxDistance, int $minFaces): JSONResponse {
		$maxDistance = max(0.0, $maxDistance);
		$minFaces = max(1, $minFaces);
		$this->appConfig->setValueFloat('recognaize_bridge', 'cluster_max_distance', $maxDistance);
		$this->appConfig->setValueInt('recognaize_bridge', 'cluster_min_faces', $minFaces);
		$result = $this->clustering->runForAllUsers($maxDistance, $minFaces);
		return new JSONResponse($result);
	}
}

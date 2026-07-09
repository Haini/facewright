<?php

declare(strict_types=1);

namespace OCA\RecognaizeBridge\Controller;

use OCA\RecognaizeBridge\Service\PathFilterService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;

/**
 * Backs the recognAIze admin settings page (kept the name from when it only handled the
 * People-page toggle; now also handles the included-paths allowlist - not worth the risk of
 * renaming the class/route once routing was working, see CLAUDE.md's "stale registration"
 * gotcha).
 *
 * setEnabled: toggles Memories' People page visibility. Memories checks Recognize's own
 * `faces.enabled` appconfig key directly (Util::recognizeIsEnabled()), but Recognize's own
 * admin toggle for that key (SettingsService::setSetting) also re-schedules Recognize's own
 * real face classifier crawl - which recognAIze exists to replace, not race. So this writes
 * the same appconfig key directly, bypassing Recognize's own service and that side effect.
 *
 * Route registered in appinfo/routes.php (mirrors Recognize's own convention).
 * No #[NoAdminRequired] - Controller actions require admin by default.
 */
class PeopleSettingsController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IAppConfig $appConfig,
		private PathFilterService $pathFilter,
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
}

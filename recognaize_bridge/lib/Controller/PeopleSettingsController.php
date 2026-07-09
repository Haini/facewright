<?php

declare(strict_types=1);

namespace OCA\RecognaizeBridge\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;

/**
 * Toggles Memories' People page visibility. Memories checks Recognize's own
 * `faces.enabled` appconfig key directly (Util::recognizeIsEnabled()), but Recognize's own
 * admin toggle for that key (SettingsService::setSetting) also re-schedules Recognize's own
 * real face classifier crawl - which recognAIze exists to replace, not race. So this writes
 * the same appconfig key directly, bypassing Recognize's own service and that side effect.
 * Route registered in appinfo/routes.php (mirrors Recognize's own convention).
 * No #[NoAdminRequired] - Controller actions require admin by default.
 */
class PeopleSettingsController extends Controller {
	public function __construct(string $appName, IRequest $request, private IAppConfig $appConfig) {
		parent::__construct($appName, $request);
	}

	public function setEnabled(bool $enabled): JSONResponse {
		$this->appConfig->setValueString('recognize', 'faces.enabled', $enabled ? 'true' : 'false');
		return new JSONResponse(['enabled' => $enabled]);
	}
}

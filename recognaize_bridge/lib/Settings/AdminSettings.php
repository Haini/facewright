<?php

declare(strict_types=1);

namespace OCA\RecognaizeBridge\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\IAppConfig;
use OCP\Settings\ISettings;
use OCP\Util;

/**
 * Registered under Recognize's own 'recognize' settings section (not a section we own) so
 * this toggle shows up alongside Recognize's existing settings form, since it's really about
 * the same underlying appconfig key - see PeopleSettingsController.
 */
class AdminSettings implements ISettings {
	public function __construct(private IAppConfig $appConfig) {
	}

	public function getForm(): TemplateResponse {
		$enabled = $this->appConfig->getValueString('recognize', 'faces.enabled', 'false') === 'true';
		Util::addScript('recognaize_bridge', 'admin');
		return new TemplateResponse('recognaize_bridge', 'admin', ['enabled' => $enabled], '');
	}

	public function getSection(): string {
		return 'recognize';
	}

	public function getPriority(): int {
		return 10;
	}
}

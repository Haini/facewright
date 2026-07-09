<?php

declare(strict_types=1);

namespace OCA\RecognaizeBridge\Settings;

use OCA\RecognaizeBridge\Service\PathFilterService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IAppConfig;
use OCP\Settings\ISettings;
use OCP\Util;

class AdminSettings implements ISettings {
	public function __construct(
		private IAppConfig $appConfig,
		private PathFilterService $pathFilter,
	) {
	}

	public function getForm(): TemplateResponse {
		$enabled = $this->appConfig->getValueString('recognize', 'faces.enabled', 'false') === 'true';
		$paths = implode("\n", $this->pathFilter->getIncludedPaths());
		$automaticDetectionEnabled = $this->appConfig->getValueString('recognaize_bridge', 'automatic_detection_enabled', 'false') === 'true';
		$batchSize = $this->appConfig->getValueInt('recognaize_bridge', 'batch_size', 50);
		Util::addScript('recognaize_bridge', 'admin');
		return new TemplateResponse('recognaize_bridge', 'admin', [
			'enabled' => $enabled,
			'paths' => $paths,
			'automaticDetectionEnabled' => $automaticDetectionEnabled,
			'batchSize' => $batchSize,
		], '');
	}

	public function getSection(): string {
		return 'recognaize_bridge';
	}

	public function getPriority(): int {
		return 10;
	}
}

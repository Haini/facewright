<?php

declare(strict_types=1);

namespace OCA\RecognaizeBridge\Settings;

use OCA\RecognaizeBridge\Service\PathFilterService;
use OCA\RecognaizeBridge\Service\ProcessedFileService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\Settings\ISettings;
use OCP\Util;

class AdminSettings implements ISettings {
	public function __construct(
		private IAppConfig $appConfig,
		private PathFilterService $pathFilter,
		private ProcessedFileService $processedFiles,
		private ITimeFactory $timeFactory,
	) {
	}

	public function getForm(): TemplateResponse {
		$enabled = $this->appConfig->getValueString('recognize', 'faces.enabled', 'false') === 'true';
		$paths = implode("\n", $this->pathFilter->getIncludedPaths());
		$automaticDetectionEnabled = $this->appConfig->getValueString('recognaize_bridge', 'automatic_detection_enabled', 'false') === 'true';
		$batchSize = $this->appConfig->getValueInt('recognaize_bridge', 'batch_size', 50);

		$processedCount = $this->processedFiles->countProcessed();
		$candidateCount = $this->processedFiles->countCandidates();
		Util::addScript('recognaize_bridge', 'admin');
		return new TemplateResponse('recognaize_bridge', 'admin', [
			'enabled' => $enabled,
			'paths' => $paths,
			'automaticDetectionEnabled' => $automaticDetectionEnabled,
			'batchSize' => $batchSize,
			'processedLastHour' => $this->processedFiles->countProcessedSince($this->timeFactory->getTime() - 3600),
			'processedCount' => $processedCount,
			'unprocessedCount' => max(0, $candidateCount - $processedCount),
		], '');
	}

	public function getSection(): string {
		return 'recognaize_bridge';
	}

	public function getPriority(): int {
		return 10;
	}
}

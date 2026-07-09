<?php

declare(strict_types=1);

namespace OCA\RecognaizeBridge\Settings;

use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class AdminSection implements IIconSection {
	public function __construct(private IURLGenerator $urlGenerator) {
	}

	public function getID(): string {
		return 'recognaize_bridge';
	}

	public function getName(): string {
		return 'recognAIze';
	}

	public function getPriority(): int {
		return 75;
	}

	public function getIcon(): string {
		return $this->urlGenerator->imagePath('recognaize_bridge', 'app.svg');
	}
}

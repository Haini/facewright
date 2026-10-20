<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Settings;

use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/**
 * The Personal-settings counterpart to AdminSection. Sharing its section id is deliberate and
 * fine - admin and personal sections live in separate registries, decided by which info.xml
 * element the class is listed under, not by the id (custom_apps/facerecognition does the same).
 */
class PersonalSection implements IIconSection {
	public function __construct(private IURLGenerator $urlGenerator) {
	}

	public function getID(): string {
		return 'facewright_bridge';
	}

	public function getName(): string {
		return 'Facewright';
	}

	public function getPriority(): int {
		return 75;
	}

	public function getIcon(): string {
		return $this->urlGenerator->imagePath('facewright_bridge', 'app-dark.svg');
	}
}

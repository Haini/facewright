<?php

declare(strict_types=1);

namespace OCA\RecognaizeBridge\AppInfo;

use OCP\AppFramework\App;

class Application extends App {
	public const APP_ID = 'recognaize_bridge';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}
}

<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\AppInfo;

use OCA\FacewrightBridge\Listener\FileCreatedListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Cache\CacheEntryInsertedEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'facewright_bridge';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		// Both events matter - see FileCreatedListener's docblock for why.
		$context->registerEventListener(NodeCreatedEvent::class, FileCreatedListener::class);
		$context->registerEventListener(CacheEntryInsertedEvent::class, FileCreatedListener::class);
	}

	public function boot(IBootContext $context): void {
	}
}

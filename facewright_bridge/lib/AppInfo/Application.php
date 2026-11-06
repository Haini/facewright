<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\AppInfo;

use OCA\DAV\Events\SabrePluginAddEvent;
use OCA\FacewrightBridge\Listener\FileAccessListener;
use OCA\FacewrightBridge\Listener\FileCreatedListener;
use OCA\FacewrightBridge\Listener\SabrePluginListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Cache\CacheEntryInsertedEvent;
use OCP\Files\Config\Event\UserMountAddedEvent;
use OCP\Files\Config\Event\UserMountRemovedEvent;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\Events\Node\BeforeNodeRenamedEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\NodeRemovedFromCache;

class Application extends App implements IBootstrap {
	public const APP_ID = 'facewright_bridge';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		// Both events matter - see FileCreatedListener's docblock for why.
		$context->registerEventListener(NodeCreatedEvent::class, FileCreatedListener::class);
		$context->registerEventListener(CacheEntryInsertedEvent::class, FileCreatedListener::class);
		$context->registerEventListener(NodeRenamedEvent::class, FileCreatedListener::class);

		// Access changes after detection has already run - see FileAccessListener. Mount events
		// rather than share hooks, because every kind of access (share, group folder, external
		// storage) registers a mount and none of them registers a share.
		$context->registerEventListener(UserMountAddedEvent::class, FileAccessListener::class);
		$context->registerEventListener(UserMountRemovedEvent::class, FileAccessListener::class);
		$context->registerEventListener(BeforeNodeDeletedEvent::class, FileAccessListener::class);
		$context->registerEventListener(NodeRemovedFromCache::class, FileAccessListener::class);
		$context->registerEventListener(BeforeNodeRenamedEvent::class, FileAccessListener::class);
		$context->registerEventListener(NodeRenamedEvent::class, FileAccessListener::class);

		// Refuses writes to Recognize's DAV tree - see RecognizeWriteGuardPlugin.
		$context->registerEventListener(SabrePluginAddEvent::class, SabrePluginListener::class);
	}

	public function boot(IBootContext $context): void {
	}
}

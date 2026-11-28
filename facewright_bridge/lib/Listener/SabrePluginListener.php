<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Listener;

use OCA\DAV\Events\SabrePluginAddEvent;
use OCA\FacewrightBridge\Dav\PeopleWritePlugin;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Adds PeopleWritePlugin to the DAV server while it is being set up.
 *
 * Registered as an event listener rather than through info.xml: plugins loaded from info.xml are
 * added after the beforeMethod:* event has already been emitted for some requests, which is the
 * same reason Recognize adds its own plugin this way.
 *
 * @template-implements IEventListener<SabrePluginAddEvent>
 */
class SabrePluginListener implements IEventListener {
	public function __construct(
		private PeopleWritePlugin $router,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof SabrePluginAddEvent) {
			return;
		}
		$event->getServer()->addPlugin($this->router);
	}
}

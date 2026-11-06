<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Dav;

use OCP\IAppConfig;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

/**
 * Refuses every request that would change people or faces through Recognize's WebDAV tree
 * (`recognize/{user}/faces/...`), which is how Memories and Photos rename, delete, create and move.
 *
 * Those requests are answered by Recognize's own code, which writes straight into
 * oc_recognize_face_detections / _clusters. This app keeps people and assignments in tables of its
 * own and treats Recognize's as a copy (docs/own-tables.md), so an edit made through Recognize
 * would either be overwritten by the next sync or contradict the real data. Refusing it up front
 * is the honest alternative: the People page is the one place people are edited.
 *
 * Reads (PROPFIND, GET, OPTIONS...) are untouched, so Memories keeps listing people and photos.
 * The path test is the only coupling to Recognize: if Recognize or Memories ever move the tree,
 * this stops matching and the guard silently does nothing.
 *
 * Disabled by the appconfig switch `block_recognize_writes` = 'false' (the rollback).
 */
class RecognizeWriteGuardPlugin extends ServerPlugin {
	private const PATH_PREFIX = 'recognize/';

	/** Every method that can change something under the tree. */
	private const REFUSED_METHODS = ['MOVE', 'DELETE', 'MKCOL', 'COPY', 'PUT', 'PROPPATCH', 'LOCK'];

	public function __construct(
		private IAppConfig $appConfig,
	) {
	}

	public function initialize(Server $server): void {
		// Priority 20: after Sabre's authentication (10), so an unauthenticated request is still
		// answered as unauthenticated rather than learning what this guard refuses.
		$server->on('beforeMethod:*', [$this, 'guard'], 20);
	}

	/** @throws Forbidden */
	public function guard(RequestInterface $request, ResponseInterface $response): void {
		if (!str_starts_with($request->getPath(), self::PATH_PREFIX)) {
			return;
		}
		if (!in_array(strtoupper($request->getMethod()), self::REFUSED_METHODS, true)) {
			return;
		}
		if ($this->appConfig->getValueString('facewright_bridge', 'block_recognize_writes', 'true') !== 'true') {
			return;
		}
		throw new Forbidden('People are managed on the Facewright People page.');
	}

	public function getPluginName(): string {
		return 'facewright-recognize-write-guard';
	}

	public function getPluginInfo(): array {
		return [
			'name' => $this->getPluginName(),
			'description' => 'Refuses writes to the recognize/ DAV tree; people are edited on the Facewright People page.',
		];
	}
}

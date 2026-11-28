<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Dav;

use OCA\FacewrightBridge\Service\DeadlockRetry;
use OCA\FacewrightBridge\Service\DuplicateTitleException;
use OCA\FacewrightBridge\Service\FaceState;
use OCA\FacewrightBridge\Service\PeopleService;
use OCP\IUserSession;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

/**
 * Applies the writes Memories sends to this app's WebDAV tree (`facewright_bridge/{user}/faces/...`, the read side is
 * Dav\Tree\RootCollection, which the fork of Memories uses when its provider setting names this app,
 * docs/memories-fork.md) by running them through PeopleService.
 *
 * A request stays inside the tree: a `Destination` under any other root is refused, so nothing moves out of it.
 *
 * The five requests Memories sends, and what they become:
 *
 *   MKCOL   faces/{name}                          create a person
 *   MOVE    faces/{name}      -> faces/{new}      rename a person
 *   DELETE  faces/{name}                          delete a person (its faces become unassigned)
 *   DELETE  faces/{name}/{id}-{file}              take a face out of its person (stays unassigned)
 *   MOVE    faces/{name}/{id}-{file}
 *           or unassigned-faces/{id}-{file}
 *             -> faces/{other}/{id}-{file}        move a face into a person
 *
 * A merge in Memories is a run of face moves, so it needs no case of its own.
 *
 * Anything else that would change the tree is refused with 403. Reads are never touched. The coupling to Memories is
 * therefore the path shapes above, the Destination header and the status codes; if a release changes them, requests
 * stop matching and are refused, not misapplied.
 *
 * Everything is scoped to the logged-in user: the user id in the path must be theirs, and every PeopleService call
 * takes it from the session, never from the request.
 *
 * There is no switch to turn this off.
 */
class PeopleWritePlugin extends ServerPlugin {
	/** The tree this class answers writes for. */
	private const ROOTS = ['facewright_bridge'];

	/** Methods this class turns into PeopleService calls. */
	private const ROUTED_METHODS = ['MKCOL', 'MOVE', 'DELETE'];

	/** Every other method that can change something under the tree. */
	private const REFUSED_METHODS = ['COPY', 'PUT', 'PROPPATCH', 'LOCK'];

	private ?Server $server = null;

	public function __construct(
		private IUserSession $userSession,
		private PeopleService $people,
	) {
	}

	public function initialize(Server $server): void {
		$this->server = $server;
		// Priority 20: after Sabre's authentication (10), so an unauthenticated request is still
		// answered as unauthenticated rather than learning what is refused here.
		$server->on('beforeMethod:*', [$this, 'guard'], 20);
		// Before CorePlugin's own handlers (priority 100), which would try to apply the request to the node tree.
		foreach (self::ROUTED_METHODS as $method) {
			$server->on('method:' . $method, [$this, 'route'], 90);
		}
	}

	/** @throws Forbidden */
	public function guard(RequestInterface $request, ResponseInterface $response): void {
		if (!$this->applies($request)) {
			return;
		}
		$method = strtoupper($request->getMethod());
		if (in_array($method, self::REFUSED_METHODS, true)) {
			throw new Forbidden('People are managed on the Facewright People page.');
		}
		if (in_array($method, self::ROUTED_METHODS, true) && $this->parse($request) === null) {
			throw new Forbidden('This change to people is not supported through this interface.');
		}
	}

	/**
	 * @return ?bool false once the request has been answered (stops Sabre's other handlers), null
	 *     to let them run
	 * @throws Forbidden
	 * @throws NotFound
	 */
	public function route(RequestInterface $request, ResponseInterface $response): ?bool {
		if (!$this->applies($request)) {
			return null;
		}
		$op = $this->parse($request);
		if ($op === null) {
			throw new Forbidden('This change to people is not supported through this interface.');
		}
		$userId = $this->userSession->getUser()?->getUID();
		if ($userId === null || $op['user'] !== $userId) {
			throw new Forbidden('Not allowed to change another user\'s people.');
		}

		// Memories sends one MOVE per face, in parallel, so InnoDB can pick a victim among them. Every operation here is
		// one transaction started from this outermost caller, so running it again is safe.
		$status = DeadlockRetry::run(fn () => match ($op['kind']) {
			'create' => $this->create($userId, $op['name']),
			'rename' => $this->rename($userId, $op['name'], $op['newName']),
			'delete-person' => $this->deletePerson($userId, $op['name']),
			'delete-face' => $this->deleteFace($userId, $op['name'], $op['faceId']),
			'move-face' => $this->moveFace($userId, $op['name'], $op['faceId'], $op['target']),
		});

		$response->setHeader('Content-Length', '0');
		$response->setStatus($status);
		return false;
	}

	private function applies(RequestInterface $request): bool {
		foreach (self::ROOTS as $root) {
			if (str_starts_with($request->getPath(), $root . '/')) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Reads a request into one of the five operations, or null for any other shape. Only the shape
	 * is decided here; whether the people and faces exist is decided by the PeopleService calls.
	 *
	 * @return ?array<string, mixed>
	 */
	private function parse(RequestInterface $request): ?array {
		$source = explode('/', trim($request->getPath(), '/'));
		if (count($source) < 4 || !in_array($source[0], self::ROOTS, true) || $source[1] === '') {
			return null;
		}
		$user = $source[1];
		$count = count($source);

		switch (strtoupper($request->getMethod())) {
			case 'MKCOL':
				if ($count === 4 && $source[2] === 'faces') {
					return ['kind' => 'create', 'user' => $user, 'name' => $source[3]];
				}
				return null;

			case 'DELETE':
				if ($count === 4 && $source[2] === 'faces') {
					return ['kind' => 'delete-person', 'user' => $user, 'name' => $source[3]];
				}
				if ($count === 5 && $source[2] === 'faces' && $this->faceId($source[4]) !== null) {
					return ['kind' => 'delete-face', 'user' => $user, 'name' => $source[3], 'faceId' => $this->faceId($source[4])];
				}
				return null;

			case 'MOVE':
				$destination = $this->destination($request);
				// The destination stays in the tree the request came in on: nothing moves between the two roots.
				if ($destination === null || count($destination) < 4 || $destination[0] !== $source[0] || $destination[1] !== $user || $destination[2] !== 'faces') {
					return null;
				}
				if ($count === 4 && $source[2] === 'faces' && count($destination) === 4) {
					return ['kind' => 'rename', 'user' => $user, 'name' => $source[3], 'newName' => $destination[3]];
				}
				// A face: out of a person (5 segments) or out of the unassigned list (4).
				$faceSegment = $count === 5 && $source[2] === 'faces' ? $source[4]
					: ($count === 4 && $source[2] === 'unassigned-faces' ? $source[3] : null);
				if ($faceSegment !== null && count($destination) === 5 && $destination[4] === $faceSegment && $this->faceId($faceSegment) !== null) {
					return [
						'kind' => 'move-face',
						'user' => $user,
						'name' => $count === 5 ? $source[3] : null, // null: from the unassigned list
						'faceId' => $this->faceId($faceSegment),
						'target' => $destination[3],
					];
				}
				return null;
		}
		return null;
	}

	/** @return ?list<string> the Destination header as path segments below the DAV root */
	private function destination(RequestInterface $request): ?array {
		$header = $request->getHeader('Destination');
		if ($header === null || $header === '' || $this->server === null) {
			return null;
		}
		try {
			return explode('/', $this->server->calculateUri($header));
		} catch (Forbidden) {
			return null;
		}
	}

	/** The detection id in a "{id}-{file name}" segment. */
	private function faceId(string $segment): ?int {
		return preg_match('/^(\d+)-./', $segment, $m) === 1 ? (int)$m[1] : null;
	}

	private function create(string $userId, string $name): int {
		try {
			$this->people->createCluster($userId, $name);
		} catch (DuplicateTitleException) {
			throw new Forbidden('Not allowed to create duplicate names');
		} catch (\InvalidArgumentException) {
			// Empty or purely numeric: an untitled person is shown under its id.
			throw new Forbidden('Not allowed to use numbers as names');
		}
		return 201;
	}

	private function rename(string $userId, string $name, string $newName): int {
		$id = $this->person($userId, $name);
		try {
			$this->people->renameCluster($userId, $id, $newName);
		} catch (DuplicateTitleException) {
			throw new Forbidden('The target node already exists and cannot be overwritten');
		} catch (\InvalidArgumentException $e) {
			throw new Forbidden($e->getMessage());
		}
		return 201;
	}

	private function deletePerson(string $userId, string $name): int {
		$this->people->deleteCluster($userId, $this->person($userId, $name));
		return 204;
	}

	private function deleteFace(string $userId, string $name, int $faceId): int {
		$this->requireFaceIn($userId, $faceId, $this->person($userId, $name));
		try {
			$this->people->detachFace($userId, $faceId);
		} catch (\InvalidArgumentException $e) {
			throw new NotFound($e->getMessage());
		}
		return 204;
	}

	private function moveFace(string $userId, ?string $name, int $faceId, string $target): int {
		$targetId = $this->person($userId, $target);
		if ($name === null) {
			// From the unassigned list: the face has to be there.
			$this->requireFaceIn($userId, $faceId, FaceState::SENTINEL_UNASSIGNED);
		} else {
			$this->requireFaceIn($userId, $faceId, $this->person($userId, $name));
		}
		try {
			$this->people->moveFace($userId, $faceId, $targetId);
		} catch (\InvalidArgumentException $e) {
			throw new NotFound($e->getMessage());
		}
		return 201;
	}

	/** @throws NotFound */
	private function person(string $userId, string $nameOrId): int {
		try {
			return $this->people->resolveCluster($userId, $nameOrId);
		} catch (\InvalidArgumentException $e) {
			throw new NotFound($e->getMessage());
		}
	}

	/** @throws NotFound unless the face exists and sits where the path says it does */
	private function requireFaceIn(string $userId, int $faceId, int $clusterId): void {
		try {
			$actual = $this->people->faceAssignment($userId, $faceId);
		} catch (\InvalidArgumentException $e) {
			throw new NotFound($e->getMessage());
		}
		if ($actual !== $clusterId) {
			throw new NotFound("Face {$faceId} is not in that person.");
		}
	}

	public function getPluginName(): string {
		return 'facewright-people-write';
	}

	public function getPluginInfo(): array {
		return [
			'name' => $this->getPluginName(),
			'description' => 'Runs the writes Memories sends to the facewright_bridge/ DAV tree through the people service.',
		];
	}
}

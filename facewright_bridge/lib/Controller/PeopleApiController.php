<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Controller;

use OCA\FacewrightBridge\Service\FaceState;
use OCA\FacewrightBridge\Service\DuplicateTitleException;
use OCA\FacewrightBridge\Service\PeopleService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * Session-scoped OCS API over PeopleService - the "API of our own" Stage 0 of
 * docs/archive/independence-implementation.md exists to create. Every action is #[NoAdminRequired] and
 * scoped to the session user ($userId, injected by the framework from the auth token) - there is
 * no parameter anywhere that names a different user, so there is no way to reach anyone else's
 * clusters through this controller. The admin's cross-user merge stays on
 * PeopleSettingsController, which does take a userId parameter and is admin-only.
 *
 * Routes: appinfo/routes.php's 'ocs' key. Resulting URLs are under
 * /ocs/v2.php/apps/facewright_bridge/api/v1/... - callers send the OCS-APIRequest header and a
 * valid request token/basic auth, same as any other OCS endpoint.
 */
class PeopleApiController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private ?string $userId,
		private PeopleService $people,
	) {
		parent::__construct($appName, $request);
	}

	/** None of these actions is #[PublicPage], so the framework has refused a request without a user before it gets here. */
	private function uid(): string {
		return $this->userId ?? throw new \LogicException('No user session on a non-public route');
	}

	/** Paged listings clamp to this; the People page asks for far fewer. */
	private const MAX_PAGE = 200;

	/** One request may name at most this many faces/people. */
	private const MAX_IDS = 5000;

	#[NoAdminRequired]
	public function listClusters(int $limit = 60, int $offset = 0, int $minCount = 0, string $search = ''): DataResponse {
		[$limit, $offset] = $this->page($limit, $offset);
		return new DataResponse([
			'items' => $this->people->listClusters($this->uid(), $limit, $offset, max(0, $minCount), $search, true),
			// Only for the first page: the grouped count costs as much as the page itself (100-200 ms on live), and the
			// page needs it once. Later pages answer null and the page keeps the first page's number.
			'total' => $offset === 0 ? $this->people->countClusters($this->uid(), max(0, $minCount), $search) : null,
		]);
	}

	/** One person: what the person page needs for its header. */
	#[NoAdminRequired]
	public function getCluster(int $id): DataResponse {
		try {
			$person = $this->people->getCluster($this->uid(), $id);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}
		return new DataResponse($person + ['count' => $this->people->countFaces($this->uid(), $id)]);
	}

	#[NoAdminRequired]
	public function listFaces(int $id, int $limit = 120, int $offset = 0): DataResponse {
		try {
			$this->people->getCluster($this->uid(), $id);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}
		[$limit, $offset] = $this->page($limit, $offset);
		return new DataResponse([
			'items' => $this->people->listFaces($this->uid(), $id, $limit, $offset),
			'total' => $offset === 0 ? $this->people->countFaces($this->uid(), $id) : null, // as in listClusters()
		]);
	}

	#[NoAdminRequired]
	public function renameCluster(int $id, string $title): DataResponse {
		try {
			$this->people->renameCluster($this->uid(), $id, $title);
		} catch (DuplicateTitleException $e) {
			return new DataResponse(['message' => $e->getMessage(), 'code' => 'title_exists', 'existingId' => $e->existingId], Http::STATUS_BAD_REQUEST);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse($this->people->getCluster($this->uid(), $id));
	}

	#[NoAdminRequired]
	public function listUnassignedFaces(int $limit = 120, int $offset = 0): DataResponse {
		[$limit, $offset] = $this->page($limit, $offset);
		return new DataResponse([
			'items' => $this->people->listUnassignedFaces($this->uid(), $limit, $offset),
			'total' => $offset === 0 ? $this->people->countFaces($this->uid(), FaceState::SENTINEL_UNASSIGNED) : null,
		]);
	}

	#[NoAdminRequired]
	public function listIgnoredFaces(int $limit = 120, int $offset = 0): DataResponse {
		[$limit, $offset] = $this->page($limit, $offset);
		return new DataResponse([
			'items' => $this->people->listIgnoredFaces($this->uid(), $limit, $offset),
			'total' => $offset === 0 ? $this->people->countFaces($this->uid(), FaceState::SENTINEL_IGNORED) : null,
		]);
	}

	/**
	 * Merges several people into $keep (whose name survives). With $dryRun, only reports how many
	 * faces would move.
	 *
	 * @param list<int> $merge
	 */
	#[NoAdminRequired]
	public function mergeMany(int $keep, array $merge, bool $dryRun = false): DataResponse {
		try {
			return new DataResponse($this->people->mergeMany($this->uid(), $keep, $this->ids($merge), $dryRun));
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/** @param list<int> $ids */
	#[NoAdminRequired]
	public function deleteMany(array $ids): DataResponse {
		try {
			return new DataResponse(['unassigned' => $this->people->deleteMany($this->uid(), $this->ids($ids))]);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Moves faces into an existing person ($clusterId) or a new one ($newTitle) - exactly one.
	 *
	 * @param list<int> $ids
	 */
	#[NoAdminRequired]
	public function moveFaces(array $ids, ?int $clusterId = null, ?string $newTitle = null): DataResponse {
		if (($clusterId === null) === ($newTitle === null)) {
			return new DataResponse(['message' => 'Give either clusterId or newTitle.'], Http::STATUS_BAD_REQUEST);
		}
		try {
			$ids = $this->ids($ids);
			if ($newTitle !== null) {
				return new DataResponse($this->people->moveFacesToNewPerson($this->uid(), $ids, $newTitle));
			}
			return new DataResponse(['clusterId' => $clusterId, 'moved' => $this->people->moveFaces($this->uid(), $ids, $clusterId)]);
		} catch (DuplicateTitleException $e) {
			return new DataResponse(['message' => $e->getMessage(), 'code' => 'title_exists', 'existingId' => $e->existingId], Http::STATUS_BAD_REQUEST);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/** @param list<int> $ids */
	#[NoAdminRequired]
	public function detachFaces(array $ids): DataResponse {
		return $this->facesAction($ids, fn (array $ids): int => $this->people->detachFaces($this->uid(), $ids));
	}

	/** @param list<int> $ids */
	#[NoAdminRequired]
	public function ignoreFaces(array $ids): DataResponse {
		return $this->facesAction($ids, fn (array $ids): int => $this->people->ignoreFaces($this->uid(), $ids));
	}

	/** @param list<int> $ids */
	#[NoAdminRequired]
	public function unignoreFaces(array $ids): DataResponse {
		return $this->facesAction($ids, fn (array $ids): int => $this->people->unignoreFaces($this->uid(), $ids));
	}

	/** @param callable(list<int>): int $action */
	private function facesAction(array $ids, callable $action): DataResponse {
		try {
			return new DataResponse(['count' => $action($this->ids($ids))]);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/** @return array{0: int, 1: int} */
	private function page(int $limit, int $offset): array {
		return [max(1, min(self::MAX_PAGE, $limit)), max(0, $offset)];
	}

	/**
	 * @param array<mixed> $ids
	 * @return list<int>
	 * @throws \InvalidArgumentException if there are too many
	 */
	private function ids(array $ids): array {
		if (count($ids) > self::MAX_IDS) {
			throw new \InvalidArgumentException('Too many items in one request (maximum ' . self::MAX_IDS . ').');
		}
		return array_values(array_map('intval', $ids));
	}

	#[NoAdminRequired]
	public function facesForFile(int $fileId): DataResponse {
		return new DataResponse($this->people->facesForFile($this->uid(), $fileId));
	}
}

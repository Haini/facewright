<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Controller;

use OCA\FacewrightBridge\Service\PeopleService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * Session-scoped OCS API over PeopleService - the "API of our own" Stage 0 of
 * docs/independence-implementation.md exists to create. Every action is #[NoAdminRequired] and
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

	#[NoAdminRequired]
	public function listClusters(): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['message' => 'No user session'], Http::STATUS_UNAUTHORIZED);
		}
		return new DataResponse($this->people->listClusters($this->userId));
	}

	#[NoAdminRequired]
	public function createCluster(string $title): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['message' => 'No user session'], Http::STATUS_UNAUTHORIZED);
		}
		try {
			$id = $this->people->createCluster($this->userId, $title);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse($this->people->getCluster($this->userId, $id));
	}

	#[NoAdminRequired]
	public function listFaces(int $id): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['message' => 'No user session'], Http::STATUS_UNAUTHORIZED);
		}
		try {
			$this->people->getCluster($this->userId, $id);
			return new DataResponse($this->people->listFaces($this->userId, $id));
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	public function renameCluster(int $id, string $title): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['message' => 'No user session'], Http::STATUS_UNAUTHORIZED);
		}
		try {
			$this->people->renameCluster($this->userId, $id, $title);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse($this->people->getCluster($this->userId, $id));
	}

	#[NoAdminRequired]
	public function deleteCluster(int $id): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['message' => 'No user session'], Http::STATUS_UNAUTHORIZED);
		}
		try {
			$unassigned = $this->people->deleteCluster($this->userId, $id);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}
		return new DataResponse(['unassigned' => $unassigned]);
	}

	#[NoAdminRequired]
	public function mergeClusters(int $id, string $merge, bool $dryRun = false): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['message' => 'No user session'], Http::STATUS_UNAUTHORIZED);
		}
		try {
			$result = $this->people->mergeClusters($this->userId, (string)$id, $merge, $dryRun);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse($result);
	}

	#[NoAdminRequired]
	public function listUnassignedFaces(): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['message' => 'No user session'], Http::STATUS_UNAUTHORIZED);
		}
		return new DataResponse($this->people->listUnassignedFaces($this->userId));
	}

	#[NoAdminRequired]
	public function moveFace(int $id, int $clusterId): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['message' => 'No user session'], Http::STATUS_UNAUTHORIZED);
		}
		try {
			$this->people->moveFace($this->userId, $id, $clusterId);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse([]);
	}

	#[NoAdminRequired]
	public function detachFace(int $id): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['message' => 'No user session'], Http::STATUS_UNAUTHORIZED);
		}
		try {
			$this->people->detachFace($this->userId, $id);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse([]);
	}

	#[NoAdminRequired]
	public function facesForFile(int $fileId): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['message' => 'No user session'], Http::STATUS_UNAUTHORIZED);
		}
		return new DataResponse($this->people->facesForFile($this->userId, $fileId));
	}
}

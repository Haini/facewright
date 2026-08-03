<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Controller;

use OCA\FacewrightBridge\BackgroundJob\BatchScanJob;
use OCA\FacewrightBridge\Service\ClusteringService;
use OCA\FacewrightBridge\Service\ClusterTriggerService;
use OCA\FacewrightBridge\Service\UserSettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;

/**
 * Backs the Facewright *personal* settings page - the per-user counterpart to
 * PeopleSettingsController, deliberately a separate class: that one's entire contract is
 * "admin-only, no attributes anywhere", and mixing NoAdminRequired actions into it would make that
 * contract something you have to read method-by-method.
 *
 * Every action here carries #[NoAdminRequired] (Controller actions require admin otherwise) and
 * operates on the injected $userId - the session's user - and never on a user id taken from the
 * request body. That is the authorization boundary for the whole per-user feature: there is no
 * parameter anyone could supply to reach someone else's settings or data.
 *
 * Routes are registered in appinfo/routes.php under /personal/*.
 */
class PersonalSettingsController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private ?string $userId,
		private UserSettingsService $userSettings,
		private ClusterTriggerService $clusterTrigger,
		private ClusteringService $clustering,
		private IJobList $jobList,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function setEnabled(bool $enabled): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse(['error' => 'No user session'], 401);
		}
		$this->userSettings->setEnabled($this->userId, $enabled);
		return new JSONResponse(['enabled' => $enabled]);
	}

	/** @param string $paths newline-separated list of paths, relative to this user's files root */
	#[NoAdminRequired]
	public function setIncludedPaths(string $paths): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse(['error' => 'No user session'], 401);
		}
		$this->userSettings->setIncludedPaths($this->userId, preg_split('/\r\n|\r|\n/', $paths) ?: []);
		return new JSONResponse(['paths' => $this->userSettings->getIncludedPaths($this->userId)]);
	}

	/**
	 * Starts a scan chain scoped to this user. Enqueues one BatchScanJob and returns - enumerating
	 * a whole library synchronously in the request is exactly the failure mode BatchScanJob exists
	 * to avoid. IJobList::add() dedupes on class plus argument, so clicking twice with the same
	 * chunk size cannot start two chains.
	 */
	#[NoAdminRequired]
	public function runScan(int $chunkSize = 50): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse(['error' => 'No user session'], 401);
		}
		if (!$this->userSettings->isEnabled($this->userId)) {
			return new JSONResponse(['error' => 'Face recognition is turned off for this account'], 400);
		}
		$this->jobList->add(BatchScanJob::class, [
			'chunkSize' => max(1, $chunkSize),
			'userId' => $this->userId,
		]);
		return new JSONResponse(['started' => true]);
	}

	/**
	 * Saves this user's clustering thresholds and starts a chain for them.
	 *
	 * The clearUnclusterableMarks() call is the non-obvious part and is not optional. A face that
	 * survives a full pass unassigned is marked UNCLUSTERABLE (-2) and never reconsidered, so
	 * without this the *second* use of these controls would appear to do nothing at all: the new
	 * thresholds would be stored, a chain would start, and it would re-confirm the old verdict on a
	 * pool that no longer contains the faces in question. Only done when the values actually moved,
	 * so merely clicking "Run now" again doesn't rescan a settled backlog.
	 */
	#[NoAdminRequired]
	public function runClustering(float $maxDistance, int $minFaces, int $chunkSize = 300): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse(['error' => 'No user session'], 401);
		}
		$maxDistance = max(0.0, $maxDistance);
		$minFaces = max(1, $minFaces);
		$chunkSize = max(1, $chunkSize);

		$reopened = 0;
		if ($this->userSettings->setClusterParams($this->userId, $maxDistance, $minFaces)) {
			$reopened = $this->clustering->clearUnclusterableMarks($this->userId);
		}

		$started = $this->clusterTrigger->startForUser($this->userId, $chunkSize);

		return new JSONResponse(['started' => $started, 'reopened' => $reopened]);
	}
}

<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Controller;

use OCA\FacewrightBridge\Service\SuggestionService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * "Possibly the same person", per person - the OCS side of SuggestionService. Same rules as
 * PeopleApiController: every action is #[NoAdminRequired] and scoped to the session user, and no
 * parameter names a different user. Kept separate so that controller stays about people and faces.
 */
class SuggestionApiController extends OCSController {
	private const MAX_LIMIT = 50;

	public function __construct(
		string $appName,
		IRequest $request,
		private ?string $userId,
		private SuggestionService $suggestions,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function forPerson(int $id, int $limit = 10): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['message' => 'No user session'], Http::STATUS_UNAUTHORIZED);
		}
		try {
			return new DataResponse(['items' => $this->suggestions->forPerson($this->userId, $id, max(1, min(self::MAX_LIMIT, $limit)))]);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}
	}

	/** Says the two people are not the same, so neither is suggested for the other again. */
	#[NoAdminRequired]
	public function dismiss(int $a, int $b): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['message' => 'No user session'], Http::STATUS_UNAUTHORIZED);
		}
		try {
			$this->suggestions->dismiss($this->userId, $a, $b);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse([]);
	}
}

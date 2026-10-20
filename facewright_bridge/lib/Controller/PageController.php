<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Controller;

use OCA\FacewrightBridge\Service\UserSettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Util;

/**
 * The People page: a page shell only. Everything it shows comes from PeopleApiController,
 * SuggestionApiController and FacePreviewController as the session user - this class decides
 * nothing about whose data is visible, and passes the template only the user's own settings.
 *
 * #[NoCSRFRequired] because it's the GET that opens the page from the navigation.
 */
class PageController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private ?string $userId,
		private UserSettingsService $userSettings,
		private IURLGenerator $urlGenerator,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse {
		$userId = $this->userId ?? '';

		Util::addScript($this->appName, 'people');
		Util::addStyle($this->appName, 'people');

		return new TemplateResponse($this->appName, 'main', [
			'enabled' => $userId !== '' && $this->userSettings->isEnabled($userId),
			'minFaces' => $userId === '' ? 3 : $this->userSettings->getClusterMinFaces($userId),
			'settingsUrl' => $this->urlGenerator->linkToRoute('settings.PersonalSettings.index', ['section' => $this->appName]),
		]);
	}
}

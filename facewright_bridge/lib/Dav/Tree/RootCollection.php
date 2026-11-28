<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Dav\Tree;

use OCA\FacewrightBridge\Service\PeopleService;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAVACL\AbstractPrincipalCollection;
use Sabre\DAVACL\PrincipalBackend\BackendInterface;

/**
 * `/remote.php/dav/facewright_bridge/`: the read side of what the fork of Memories talks to when its provider setting
 * is this app (docs/memories-fork.md). Same shape as Recognize's `recognize/` tree (`{user}/faces/{person}/{id}-{file}`
 * and `{user}/unassigned-faces/{id}-{file}`), so the fork only swaps the root's name. The collection's name has to be
 * the app id: the fork builds the path from the provider setting.
 *
 * Reads only. The writes Memories sends are applied by PeopleWritePlugin, which accepts this root too.
 * Registered through `info.xml` (`<sabre><collections>`), which needs the app to declare the `dav` type.
 */
final class RootCollection extends AbstractPrincipalCollection {
	public function __construct(
		BackendInterface $principalBackend,
		private readonly IUserSession $userSession,
		private readonly PeopleService $people,
		private readonly IRootFolder $rootFolder,
	) {
		parent::__construct($principalBackend, 'principals/users');
	}

	/**
	 * @param array{uri: string} $principalInfo
	 * @throws Forbidden for anyone but the logged-in user
	 */
	public function getChildForPrincipal(array $principalInfo): UserHome {
		[, $name] = \Sabre\Uri\split($principalInfo['uri']);
		$user = $this->userSession->getUser();
		if ($user === null || $name !== $user->getUID()) {
			throw new Forbidden();
		}
		return new UserHome($user->getUID(), $this->people, $this->rootFolder);
	}

	public function getName(): string {
		return 'facewright_bridge';
	}
}

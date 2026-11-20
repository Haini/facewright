<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Dav\Tree;

use OCA\FacewrightBridge\Service\PeopleService;
use OCP\Files\IRootFolder;
use Sabre\DAV\Exception\NotFound;

/**
 * `facewright_bridge/{user}`: the two folders Memories uses. Built only for the logged-in user (RootCollection), and
 * every read below takes the user id from here, never from a request path.
 */
final class UserHome extends ReadOnlyCollection {
	public function __construct(
		private readonly string $userId,
		private readonly PeopleService $people,
		private readonly IRootFolder $rootFolder,
	) {
	}

	public function getName(): string {
		return $this->userId;
	}

	/** @return list<FacesHome|UnassignedFacesHome> */
	public function getChildren(): array {
		return [$this->getChild('faces'), $this->getChild('unassigned-faces')];
	}

	public function getChild($name): FacesHome|UnassignedFacesHome {
		return match ($name) {
			'faces' => new FacesHome($this->userId, $this->people, $this->rootFolder->getUserFolder($this->userId)),
			'unassigned-faces' => new UnassignedFacesHome($this->userId, $this->people, $this->rootFolder->getUserFolder($this->userId)),
			default => throw new NotFound('No such folder'),
		};
	}
}

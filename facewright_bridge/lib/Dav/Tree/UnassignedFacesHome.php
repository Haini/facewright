<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Dav\Tree;

use OCA\FacewrightBridge\Service\FaceState;
use OCA\FacewrightBridge\Service\PeopleService;
use OCP\Files\Folder;
use Sabre\DAV\Exception\MethodNotAllowed;
use Sabre\DAV\Exception\NotFound;

/**
 * `unassigned-faces`: where Memories' "move to person" takes a face from. A single face can be addressed
 * (`unassigned-faces/{faceId}-{file name}`), which is what the move needs; the list cannot be asked for. Memories
 * never lists it, and it can hold a library's worth of faces, so a listing is answered 405 instead of being built.
 */
final class UnassignedFacesHome extends ReadOnlyCollection {
	public function __construct(
		private readonly string $userId,
		private readonly PeopleService $people,
		private readonly Folder $userFolder,
	) {
	}

	public function getName(): string {
		return 'unassigned-faces';
	}

	public function getChildren(): never {
		throw new MethodNotAllowed('Listing the unassigned faces is not supported');
	}

	public function getChild($name): FaceFile {
		$id = FaceFile::idOf($name);
		if ($id === null) {
			throw new NotFound('No such face');
		}
		try {
			$face = $this->people->getFace($this->userId, $id);
		} catch (\InvalidArgumentException) {
			throw new NotFound('No such face');
		}
		if ($face['state'] !== FaceState::UNASSIGNED) {
			throw new NotFound('That face is not unassigned');
		}
		return FaceFile::open($this->userFolder, $id, $face['file_id']) ?? throw new NotFound('The photo is not available to this user');
	}
}

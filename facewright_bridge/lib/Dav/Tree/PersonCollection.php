<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Dav\Tree;

use OCA\FacewrightBridge\Service\FaceState;
use OCA\FacewrightBridge\Service\PeopleService;
use OCP\Files\Folder;
use Sabre\DAV\Exception\NotFound;

/**
 * `faces/{person}`: the faces assigned to one person, as `{faceId}-{file name}`. Memories lists it when merging two
 * people and then moves each child into the other person.
 */
final class PersonCollection extends ReadOnlyCollection {
	public function __construct(
		private readonly string $userId,
		private readonly int $clusterId,
		private readonly string $name,
		private readonly PeopleService $people,
		private readonly Folder $userFolder,
	) {
	}

	public function getName(): string {
		return $this->name;
	}

	/** @return list<FaceFile> only faces whose file the user can reach */
	public function getChildren(): array {
		$children = [];
		foreach ($this->people->listFaces($this->userId, $this->clusterId) as $face) {
			$file = FaceFile::open($this->userFolder, $face['id'], $face['file_id']);
			if ($file !== null) {
				$children[] = $file;
			}
		}
		return $children;
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
		// The path names the person: a face that sits anywhere else is not here.
		if ($face['state'] !== FaceState::ASSIGNED || $face['cluster_id'] !== $this->clusterId) {
			throw new NotFound('That face is not in this person');
		}
		return FaceFile::open($this->userFolder, $id, $face['file_id']) ?? throw new NotFound('The photo is not available to this user');
	}
}

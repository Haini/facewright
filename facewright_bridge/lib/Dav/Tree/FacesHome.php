<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Dav\Tree;

use OCA\FacewrightBridge\Service\PeopleService;
use OCP\Files\Folder;
use Sabre\DAV\Exception\NotFound;

/**
 * `faces`: the people that have at least one face, each named by its title or, untitled, by its id (which is how
 * Memories addresses an untitled person). A child is looked up by the same rule the router uses for a path:
 * `PeopleService::resolveCluster()`, a numeric name is an id and anything else an exact title.
 */
final class FacesHome extends ReadOnlyCollection {
	public function __construct(
		private readonly string $userId,
		private readonly PeopleService $people,
		private readonly Folder $userFolder,
	) {
	}

	public function getName(): string {
		return 'faces';
	}

	/** @return list<PersonCollection> */
	public function getChildren(): array {
		$children = [];
		foreach ($this->people->listClusters($this->userId, null, 0, 1) as $person) {
			$children[] = $this->person($person['id'], $person['title']);
		}
		return $children;
	}

	public function getChild($name): PersonCollection {
		try {
			$id = $this->people->resolveCluster($this->userId, (string)$name);
			$person = $this->people->getCluster($this->userId, $id);
		} catch (\InvalidArgumentException $e) {
			throw new NotFound($e->getMessage());
		}
		return $this->person($person['id'], $person['title']);
	}

	private function person(int $id, string $title): PersonCollection {
		return new PersonCollection($this->userId, $id, $title !== '' ? $title : (string)$id, $this->people, $this->userFolder);
	}
}

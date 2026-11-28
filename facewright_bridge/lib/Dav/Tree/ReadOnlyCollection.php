<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Dav\Tree;

use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\ICollection;

/**
 * What every collection of the tree shares: nothing can be created, renamed or deleted through the nodes.
 *
 * The writes Memories sends are answered by PeopleWritePlugin before Sabre reaches a node (it takes the
 * request on `method:*` at priority 90, ahead of CorePlugin), and the nodes do not implement IMoveTarget. If the router
 * ever failed to answer one, Sabre would reach these methods and be refused instead of changing something.
 */
abstract class ReadOnlyCollection implements ICollection {
	public function createFile($name, $data = null): never {
		throw new Forbidden('Not allowed to create files in this folder');
	}

	public function createDirectory($name): never {
		throw new Forbidden('People are managed on the Facewright People page.');
	}

	public function setName($name): never {
		throw new Forbidden('Permission denied to rename this folder');
	}

	public function delete(): never {
		throw new Forbidden('Permission denied to delete this folder');
	}

	public function getLastModified(): ?int {
		return null;
	}

	public function childExists($name): bool {
		try {
			$this->getChild($name);
			return true;
		} catch (NotFound) {
			return false;
		}
	}
}

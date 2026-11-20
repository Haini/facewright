<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Dav\Tree;

use OCP\Files\File;
use OCP\Files\Folder;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\IFile;

/**
 * One face as a file: named `{faceId}-{file name}`, the way Recognize names them and the way Memories builds the paths
 * it moves and removes (`faceid` is `facewright_user_faces.id`, which is the detection id Memories knows). Its content
 * is the user's own photo, opened through their file tree, so what it reveals is what they can already read.
 */
final class FaceFile implements IFile {
	public function __construct(
		private readonly int $faceId,
		private readonly File $file,
	) {
	}

	/**
	 * The face's file as the user sees it, or null when they cannot reach it (or it is a folder). A face whose file
	 * the user lost access to is left out of the tree, as Recognize does.
	 */
	public static function open(Folder $userFolder, int $faceId, int $fileId): ?self {
		$node = $userFolder->getFirstNodeById($fileId);
		return $node instanceof File ? new self($faceId, $node) : null;
	}

	/** The face id in a `{id}-{name}` segment: the digits before the first `-`, as Recognize reads it. */
	public static function idOf(string $segment): ?int {
		return preg_match('/^(\d+)-/', $segment, $m) === 1 ? (int)$m[1] : null;
	}

	public function getName(): string {
		return $this->faceId . '-' . $this->file->getName();
	}

	public function get() {
		return $this->file->fopen('r');
	}

	public function getContentType(): string {
		return $this->file->getMimeType();
	}

	public function getETag(): string {
		return $this->file->getEtag();
	}

	public function getSize(): int|float {
		return $this->file->getSize();
	}

	public function getLastModified(): int {
		return $this->file->getMTime();
	}

	public function put($data): never {
		throw new Forbidden('Can\'t write to photos through the faces tree');
	}

	public function delete(): never {
		throw new Forbidden('Faces are removed from a person on the Facewright People page.');
	}

	public function setName($name): never {
		throw new Forbidden('Cannot rename photos through the faces tree');
	}
}

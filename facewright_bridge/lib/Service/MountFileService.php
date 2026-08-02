<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IMimeTypeLoader;
use OCP\IDBConnection;

/**
 * Lists the image files inside a mount, straight from filecache.
 *
 * Both callers need this without a per-user filesystem being set up: AccessSyncService reconciles a
 * mount whose owner may not be the acting user, and PruneService runs inside a
 * BeforeNodeDeletedEvent where walking Nodes would mean instantiating the whole subtree. Recognize
 * solves the same problem with StorageService::getFilesInMount(), but reaches into the private
 * CacheQueryBuilder to do it; this is the same query against the public QueryBuilder.
 *
 * The queries are keyed on (storage, mimetype) and paged on fileid, so they ride the core
 * fs_storage_mimetype index and can be resumed by cursor rather than by OFFSET.
 */
class MountFileService {
	/** @var list<int>|null */
	private ?array $mimetypeIds = null;

	public function __construct(
		private IDBConnection $db,
		private IMimeTypeLoader $mimeTypeLoader,
	) {
	}

	/**
	 * Storage id and internal path of a file or folder, or null if it isn't in filecache. This is
	 * how a mount root id becomes a path prefix to scan under.
	 *
	 * @return array{storage: int, path: string}|null
	 */
	public function locate(int $fileId): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('storage', 'path')
			->from('filecache')
			->where($qb->expr()->eq('fileid', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		if ($row === false) {
			return null;
		}
		return ['storage' => (int)$row['storage'], 'path' => (string)$row['path']];
	}

	/**
	 * Image file ids under $rootPath on $storageId, in ascending id order, starting after
	 * $afterFileId. Filtered to the mimetypes detection can actually decode, so this enumerates the
	 * same set BatchTriggerService would - raw camera files (image/x-dcraw) are not candidates and
	 * never have rows to reconcile or prune.
	 *
	 * @return list<int>
	 */
	public function listImageFileIds(int $storageId, string $rootPath, int $afterFileId, int $limit): array {
		if ($rootPath === '') {
			// The storage root, which on a home storage means files/, files_trashbin/ and
			// files_versions/ all at once - never what a caller means, and it would create rows for
			// trashed files. Callers pass a real subtree root instead: BackfillAccess resolves a
			// home mount to the user's files folder rather than its mount root for exactly this
			// reason.
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('fileid')
			->from('filecache')
			->where($qb->expr()->eq('storage', $qb->createNamedParameter($storageId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('mimetype', $qb->createNamedParameter($this->imageMimetypeIds(), IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->gt('fileid', $qb->createNamedParameter($afterFileId, IQueryBuilder::PARAM_INT)))
			->orderBy('fileid', 'ASC')
			->setMaxResults($limit)
			// The root itself can't be an image (it's a folder), so only the subtree matters.
			->andWhere($qb->expr()->like('path', $qb->createNamedParameter(
				$this->db->escapeLikeParameter($rootPath) . '/%'
			)));

		return array_map('intval', array_column($qb->executeQuery()->fetchAll(), 'fileid'));
	}

	/** @return list<int> */
	private function imageMimetypeIds(): array {
		if ($this->mimetypeIds !== null) {
			return $this->mimetypeIds;
		}
		$ids = [];
		foreach (DetectionService::SUPPORTED_MIMETYPES as $mimetype) {
			if ($this->mimeTypeLoader->exists($mimetype)) {
				$ids[] = $this->mimeTypeLoader->getId($mimetype);
			}
		}
		// -1 keeps the IN() clause valid (and matching nothing) on an instance where none of these
		// mimetypes has ever been seen; an empty PARAM_INT_ARRAY renders as IN () and is a syntax
		// error on MySQL.
		return $this->mimetypeIds = $ids === [] ? [-1] : $ids;
	}
}

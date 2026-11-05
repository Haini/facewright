<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Service;

use OC\Files\Search\SearchComparison;
use OC\Files\Search\SearchOrder;
use OC\Files\Search\SearchQuery;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\Search\ISearchComparison;
use OCP\Files\Search\ISearchOrder;

/**
 * Matches a file path against one user's own allowlist of paths considered for face detection.
 * Recognize's own approach here is a per-folder ".noimage"-style exclusion marker (see
 * IgnoreService in its source) - deliberately not what this is: an explicit include list, with
 * everything else ignored.
 *
 * The list used to be a single instance-wide appconfig row an admin edited on everyone's behalf.
 * It is now per user (see UserSettingsService), which is what lets a user add their own folders
 * without an admin editing a shared textarea. Storage lives entirely in UserSettingsService; this
 * class is only the matching logic and the resolution to Folder nodes.
 *
 * Paths are relative to the user's own files root and matched as exact matches or folder prefixes.
 * An empty list means no restriction - everything that user can see is included.
 *
 * Note what this does *not* decide: a shared file has a different path for each user, and the
 * allowlist governs which photos are worth running inference on, not who may see the resulting
 * faces. Detection rows still fan out to every user with access. See AccessService.
 */
class PathFilterService {
	/** Rows per page in images(). Bounds peak memory to roughly this many Node objects. */
	private const IMAGE_PAGE_SIZE = 2500;

	public function __construct(private UserSettingsService $userSettings) {
	}

	/**
	 * Every `image/*` file under $folder, fetched a page at a time. Use this instead of
	 * `$folder->searchByMime('image')`: that one builds a Node for every image in the folder in a
	 * single array before returning. On a ~54k-photo library that peaked at ~880 MB per call and
	 * set off core's "Cron job used more than 300 MiB" warning on every BatchScanJob tick.
	 *
	 * Pages are ordered by fileid and LIMIT/OFFSET is applied in SQL (QuerySearchHelper), so only
	 * one page is in memory at a time. A file created or deleted mid-walk can shift a page
	 * boundary and be missed or seen twice. Both are harmless here: duplicates are deduped by the
	 * caller's seen-set and by IJobList::add(), and anything missed is picked up on the next pass
	 * or by FileCreatedListener.
	 *
	 * Uses core's OC\Files\Search classes because OCP only ships the interfaces. Memories and Maps
	 * depend on these classes the same way.
	 *
	 * Only an empty page ends the walk. A short page does not: LIMIT is applied in SQL, then core
	 * drops rows a storage wrapper rejects, so a page can come back short with more rows after it.
	 *
	 * @return \Generator<int, Node>
	 */
	public function images(Folder $folder): \Generator {
		$operator = new SearchComparison(ISearchComparison::COMPARE_LIKE, 'mimetype', 'image/%');
		$order = [new SearchOrder(ISearchOrder::DIRECTION_ASCENDING, 'fileid')];

		for ($offset = 0; ; $offset += self::IMAGE_PAGE_SIZE) {
			$page = $folder->search(new SearchQuery($operator, self::IMAGE_PAGE_SIZE, $offset, $order));
			if ($page === []) {
				return;
			}
			// Not `yield from`: that keeps each page's own keys 0..n, which collide across pages.
			foreach ($page as $node) {
				yield $node;
			}
		}
	}

	public function isIncluded(string $userId, string $path): bool {
		$included = $this->userSettings->getIncludedPaths($userId);
		if ($included === []) {
			return true;
		}
		$path = trim($path, '/');
		foreach ($included as $prefix) {
			if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Resolves one user's included paths to actual Folder nodes - shared by BatchTriggerService
	 * (enqueueing the backlog) and ProcessedFileService::countCandidates() (the stats denominator),
	 * so both enumerate exactly the same set of files. Falls back to the whole user folder when
	 * that user has configured no paths, matching isIncluded()'s "empty list = no restriction".
	 *
	 * @return list<Folder>
	 */
	public function resolveSearchRoots(string $userId, Folder $userFolder): array {
		$paths = $this->userSettings->getIncludedPaths($userId);
		if ($paths === []) {
			return [$userFolder];
		}

		$folders = [];
		foreach ($paths as $path) {
			try {
				$node = $userFolder->get($path);
			} catch (NotFoundException) {
				continue; // configured path doesn't exist for this user
			}
			if ($node instanceof Folder) {
				$folders[] = $node;
			}
		}
		return $folders;
	}
}

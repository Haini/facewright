<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Service;

use OCP\Files\Config\ICachedMountFileInfo;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\IRootFolder;
use OCP\IUserManager;

/**
 * Answers "who can see this file, and where does it sit in their tree" - the primitive the whole
 * multi-user fan-out is built on.
 *
 * IUserMountCache::getMountsForFileId() *is* the access model: shares, group folders and external
 * storage all register into oc_mounts, so none of them need special-casing here. Recognize does the
 * same thing (ClusteringFaceClassifier::getUsersWithFileAccess) and, notably, has no share hooks at
 * all as of v12 - mounts cover it.
 *
 * Where this deliberately differs from Recognize: ICachedMountFileInfo::getPath() already returns
 * mountPoint . internalPath (e.g. "/Bob/files/PhotosFromConsti/x.jpg"), so a single mount-cache
 * call yields both the user set *and* every user's files-root-relative path. Recognize re-resolves
 * a Node per user instead. At 65k files that difference is the bulk of the fan-out's cost.
 *
 * Note on the included-paths interaction: PathFilterService's allowlist is matched against a
 * user-relative path, and a shared file has a different path for each user. By design the filter is
 * evaluated against the *fetch* user's path only (see pickFetchUser) - so with
 * included_paths = ["Bilder"], a photo Consti owns at Bilder/x.jpg and shares to Bob at
 * PhotosFromConsti/x.jpg is processed and fans out to both. The allowlist decides which photos are
 * worth inference, not who may see the faces.
 */
class AccessService {
	/**
	 * Mount paths that are a real user files root. Everything else - trashbin, versions, appdata,
	 * group-folder internals - must never receive detection rows, since nothing reads faces there
	 * and the "path" would be meaningless to PathFilterService.
	 *
	 * The trailing segment is optional because getUsersWithAccessToRoot() asks this question about
	 * a *mount root*, and a home mount's root path is exactly "/<uid>/files" with nothing after it.
	 */
	private const FILES_ROOT_PATTERN = '#^/([^/]+)/files(?:/(.*))?$#';

	/**
	 * Memoized per file, because a single job or backfill chunk asks the same question two or three
	 * times (fetch user, target users, prune check). Capped because a 65k-file backfill would
	 * otherwise accumulate 65k path arrays in one long-lived process.
	 */
	private const MEMO_CAP = 2000;

	/** @var array<int, array<string, string>> fileId => (userId => user-relative path) */
	private array $memo = [];

	public function __construct(
		private IUserMountCache $userMountCache,
		private IUserManager $userManager,
		private IRootFolder $rootFolder,
	) {
	}

	/**
	 * @param bool $fresh drop the mount cache first. Only worth passing from an event listener,
	 *                    where the current request just mutated oc_mounts and the in-process cache
	 *                    is therefore stale (this is why Recognize's FileListener calls clear()).
	 *                    Background jobs and occ run in a cold process, so paying for it there only
	 *                    discards useful memoization.
	 *
	 * @return array<string, string> userId => path relative to that user's files root, no leading
	 *                               slash - the same convention PathFilterService::isIncluded()
	 *                               expects. Sorted by user id so callers get a stable order.
	 */
	public function getAccessPaths(int $fileId, bool $fresh = false): array {
		if ($fresh) {
			$this->userMountCache->clear();
			$this->memo = [];
		} elseif (isset($this->memo[$fileId])) {
			return $this->memo[$fileId];
		}

		$paths = [];
		foreach ($this->userMountCache->getMountsForFileId($fileId) as $mount) {
			if (!$mount instanceof ICachedMountFileInfo) {
				continue;
			}
			if (preg_match(self::FILES_ROOT_PATTERN, $mount->getPath(), $matches) !== 1) {
				continue;
			}
			$userId = $mount->getUser()->getUID();
			if ($matches[1] !== $userId) {
				// The mount point has to be the user's own root. Anything else is a shape we don't
				// understand, and guessing a path would put rows under a wrong user.
				continue;
			}
			if (!$this->userManager->userExists($userId)) {
				continue;
			}
			// First mount wins. A user can hold several mounts of the same storage (same folder
			// shared twice, or a re-share alongside a group folder); any of the paths is a valid
			// way to reach the file, and ksort below keeps the choice stable across runs.
			$paths[$userId] ??= $matches[2] ?? '';
		}
		ksort($paths);

		if (count($this->memo) >= self::MEMO_CAP) {
			$this->memo = [];
		}
		return $this->memo[$fileId] = $paths;
	}

	/** @return list<string> user ids, sorted ascending */
	public function getUsersWithAccess(int $fileId, bool $fresh = false): array {
		return array_keys($this->getAccessPaths($fileId, $fresh));
	}

	/**
	 * Access set for a mount root rather than a single file - what a share/unshare event knows.
	 * Only valid for deciding who to *copy* to: see AccessSyncService::reconcileMount() on why it
	 * must not be used as the keep-list for deletes.
	 *
	 * @return list<string>
	 */
	public function getUsersWithAccessToRoot(int $rootId, bool $fresh = false): array {
		return $this->getUsersWithAccess($rootId, $fresh);
	}

	/** Single-user check, using getMountsForFileId's own user filter - one narrowed query. */
	public function hasAccess(int $fileId, string $userId): bool {
		return $this->userMountCache->getMountsForFileId($fileId, $userId) !== [];
	}

	/**
	 * Picks the one user whose credentials the ExApp downloads the file as. The path returned is
	 * relative to *that* user's files root, which is what /detect expects.
	 *
	 * Owner-first is deliberate and load-bearing rather than cosmetic:
	 *  - The owner's mount is their home storage, so no share permission can revoke their read. A
	 *    sharee's access can vanish between a job being queued and it running, which surfaces as a
	 *    DAV 403 the ExApp reports as a 502, i.e. as retryable forever.
	 *  - The fetch user decides which path PathFilterService sees, so a choice that flapped between
	 *    runs would make a file's inclusion flap with it. Owner-first plus the ksort in
	 *    getAccessPaths() makes the fallback deterministic too.
	 *
	 * @param string|null $preferred a hint (e.g. the user named on an older queued job). Ignored
	 *                               silently if that user no longer has access.
	 *
	 * @return array{userId: string, path: string}|null null when nobody can see the file
	 */
	public function pickFetchUser(int $fileId, ?string $preferred = null): ?array {
		$paths = $this->getAccessPaths($fileId);
		if ($paths === []) {
			return null;
		}

		foreach ([$preferred, $this->ownerOf($fileId)] as $candidate) {
			if ($candidate !== null && isset($paths[$candidate])) {
				return ['userId' => $candidate, 'path' => $paths[$candidate]];
			}
		}

		$userId = array_key_first($paths);
		return ['userId' => $userId, 'path' => $paths[$userId]];
	}

	/** Null is a normal answer here - the owner may have been deleted, or the node unresolvable. */
	private function ownerOf(int $fileId): ?string {
		return $this->rootFolder->getFirstNodeById($fileId)?->getOwner()?->getUID();
	}
}

<?php

declare(strict_types=1);

namespace OCA\RecognaizeBridge\Service;

use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use OCP\IAppConfig;

/**
 * Admin-configured allowlist of file paths considered for face detection - checked by
 * DetectFaces, managed via the admin settings page (SettingsController). Recognize's own
 * approach here is a per-folder ".noimage"-style exclusion marker (see IgnoreService in its
 * source) - deliberately not what this is: the user asked for an explicit include list instead,
 * with everything else ignored, so this is a fresh allowlist, not a port of that mechanism.
 *
 * Paths are relative to each user's own files root (same convention as DetectFaces' path
 * argument) and matched as exact matches or folder prefixes. An empty list means no
 * restriction - everything is included, matching the behavior before this setting existed.
 */
class PathFilterService {
	private const APP_ID = 'recognaize_bridge';
	private const CONFIG_KEY = 'included_paths';

	public function __construct(private IAppConfig $appConfig) {
	}

	/** @return list<string> */
	public function getIncludedPaths(): array {
		return $this->appConfig->getValueArray(self::APP_ID, self::CONFIG_KEY, []);
	}

	/** @param string[] $paths */
	public function setIncludedPaths(array $paths): void {
		$normalized = [];
		foreach ($paths as $path) {
			$path = trim($path);
			$path = trim($path, '/');
			if ($path !== '') {
				$normalized[] = $path;
			}
		}
		$this->appConfig->setValueArray(self::APP_ID, self::CONFIG_KEY, array_values(array_unique($normalized)));
	}

	public function isIncluded(string $path): bool {
		$included = $this->getIncludedPaths();
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
	 * Resolves the configured included paths to actual Folder nodes for one user - shared by
	 * BatchTriggerService (enqueueing the backlog) and StatsService (counting candidates), so
	 * both enumerate exactly the same set of files. Falls back to the whole user folder when no
	 * paths are configured (matching isIncluded()'s "empty list = no restriction" behavior).
	 *
	 * @return list<Folder>
	 */
	public function resolveSearchRoots(Folder $userFolder): array {
		$paths = $this->getIncludedPaths();
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

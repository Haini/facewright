<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Service;

use OCP\Config\IUserConfig;
use OCP\Config\ValueType;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserManager;

/**
 * Every per-user setting Facewright has, read and written in exactly one place - the same role
 * PathFilterService used to play for the single instance-wide allowlist.
 *
 * Face recognition is opt-in per user: a user who has never opened the personal settings page is
 * off, contributes no candidates, and costs nothing. The migration that introduced this
 * (Version000004000) explicitly enables the users that existed at the time and copies the old
 * global included_paths into each of them, so switching to per-user mode changed nobody's
 * behaviour on an existing instance.
 *
 * Storage is OCP\Config\IUserConfig, the per-user twin of the IAppConfig this app already uses,
 * including setValueArray/getValueArray. OCP\IConfig::setUserValue and friends are deprecated as
 * of NC 33 and deliberately not used.
 */
class UserSettingsService {
	public const APP_ID = 'facewright_bridge';

	public const KEY_ENABLED = 'enabled';
	public const KEY_INCLUDED_PATHS = 'included_paths';
	public const KEY_CLUSTER_MAX_DISTANCE = 'cluster_max_distance';
	public const KEY_CLUSTER_MIN_FACES = 'cluster_min_faces';

	/**
	 * The instance-wide allowlist per-user mode replaced. Read only by seedExistingUsers(), which
	 * copies it into each existing user's own settings; nothing else looks at it any more. The row
	 * is deliberately left in place after the migration so a rollback loses nothing.
	 */
	private const LEGACY_GLOBAL_PATHS_KEY = 'included_paths';

	public function __construct(
		private IUserConfig $userConfig,
		private IAppConfig $appConfig,
		private IUserManager $userManager,
	) {
	}

	public function isEnabled(string $userId): bool {
		return $this->userConfig->getValueString($userId, self::APP_ID, self::KEY_ENABLED, 'false') === 'true';
	}

	/**
	 * Stored as a string rather than via setValueBool() because bools cannot carry flags
	 * (setValueBool has no $flags parameter) and FLAG_INDEXED is what makes enabledUsers() a real
	 * indexed lookup instead of a scan over configvalue - UserConfig::searchUsersByTypedValue()
	 * logs a notice and falls back when the key isn't flagged.
	 */
	public function setEnabled(string $userId, bool $enabled): void {
		$this->userConfig->setValueString(
			$userId,
			self::APP_ID,
			self::KEY_ENABLED,
			$enabled ? 'true' : 'false',
			false,
			IUserConfig::FLAG_INDEXED,
		);
	}

	/**
	 * The users detection actually works for. Everything that used to enumerate the instance with
	 * IUserManager::callForSeenUsers() goes through this instead - that is what makes opting out
	 * genuinely stop work rather than merely hide a page.
	 *
	 * @return list<string>
	 */
	public function enabledUsers(): array {
		$userIds = [];
		foreach ($this->userConfig->searchUsersByValueString(self::APP_ID, self::KEY_ENABLED, 'true') as $userId) {
			$userIds[] = $userId;
		}
		sort($userIds);
		return $userIds;
	}

	/** @return list<string> paths relative to this user's files root; empty means no restriction */
	public function getIncludedPaths(string $userId): array {
		return array_values($this->userConfig->getValueArray($userId, self::APP_ID, self::KEY_INCLUDED_PATHS, []));
	}

	/** @param string[] $paths */
	public function setIncludedPaths(string $userId, array $paths): void {
		$this->userConfig->setValueArray($userId, self::APP_ID, self::KEY_INCLUDED_PATHS, self::normalizePaths($paths));
	}

	/**
	 * Trims whitespace and surrounding slashes and drops empties/duplicates - unchanged from when
	 * this lived on PathFilterService, since it defines what the matching in isIncluded() expects.
	 *
	 * @param string[] $paths
	 * @return list<string>
	 */
	public static function normalizePaths(array $paths): array {
		$normalized = [];
		foreach ($paths as $path) {
			$path = trim(trim((string)$path), '/');
			if ($path !== '') {
				$normalized[] = $path;
			}
		}
		return array_values(array_unique($normalized));
	}

	/**
	 * Per-user clustering thresholds, falling back to the instance defaults still configured on the
	 * admin page. Only the *paths* list was removed from appconfig when per-user mode landed; these
	 * two stay there as the starting point for users who never touch them.
	 */
	public function getClusterMaxDistance(string $userId): float {
		return $this->userConfig->getValueFloat(
			$userId,
			self::APP_ID,
			self::KEY_CLUSTER_MAX_DISTANCE,
			$this->defaultClusterMaxDistance(),
		);
	}

	public function getClusterMinFaces(string $userId): int {
		return $this->userConfig->getValueInt(
			$userId,
			self::APP_ID,
			self::KEY_CLUSTER_MIN_FACES,
			$this->defaultClusterMinFaces(),
		);
	}

	public function defaultClusterMaxDistance(): float {
		return $this->appConfig->getValueFloat(self::APP_ID, self::KEY_CLUSTER_MAX_DISTANCE, 0.5);
	}

	public function defaultClusterMinFaces(): int {
		return $this->appConfig->getValueInt(self::APP_ID, self::KEY_CLUSTER_MIN_FACES, 3);
	}

	/**
	 * Whether the stored thresholds actually moved is load-bearing, not cosmetic: a face that
	 * survived a full clustering pass with no assignment is marked UNCLUSTERABLE (-2) and is never
	 * reconsidered, so re-running with *different* thresholds would silently apply the old verdict.
	 * PersonalSettingsController clears those marks when - and only when - this returns true.
	 *
	 * @return bool true if either value changed
	 */
	public function setClusterParams(string $userId, float $maxDistance, int $minFaces): bool {
		$changed = abs($this->getClusterMaxDistance($userId) - $maxDistance) > 1e-9
			|| $this->getClusterMinFaces($userId) !== $minFaces;

		$this->userConfig->setValueFloat($userId, self::APP_ID, self::KEY_CLUSTER_MAX_DISTANCE, $maxDistance);
		$this->userConfig->setValueInt($userId, self::APP_ID, self::KEY_CLUSTER_MIN_FACES, $minFaces);

		return $changed;
	}

	/** True once the user has been through the migration or saved anything themselves. */
	public function hasSettings(string $userId): bool {
		return $this->userConfig->hasKey($userId, self::APP_ID, self::KEY_ENABLED);
	}

	/**
	 * Moves the old instance-wide allowlist into every existing user's own settings, and switches
	 * those users on.
	 *
	 * Without this, introducing per-user mode would silently turn face recognition *off* for
	 * everyone on an existing instance, since a user with no stored preference is off by design -
	 * which is the right default for accounts created from here on, and the wrong one for accounts
	 * that were already being scanned yesterday.
	 *
	 * Idempotent: a user who already has an `enabled` value is left completely alone, so this can be
	 * run from the migration and again by hand without a second run undoing someone's later choice.
	 * Only seen users are touched - an account that has never logged in has no files to scan.
	 *
	 * @return array{paths: list<string>, seeded: list<string>, skipped: list<string>}
	 */
	public function seedExistingUsers(bool $dryRun = false): array {
		$paths = self::normalizePaths($this->appConfig->getValueArray(self::APP_ID, self::LEGACY_GLOBAL_PATHS_KEY, []));

		$seeded = [];
		$skipped = [];
		$this->userManager->callForSeenUsers(function (IUser $user) use ($paths, $dryRun, &$seeded, &$skipped): void {
			$userId = $user->getUID();
			if ($this->hasSettings($userId)) {
				$skipped[] = $userId;
				return;
			}
			if (!$dryRun) {
				$this->setIncludedPaths($userId, $paths);
				$this->setEnabled($userId, true);
			}
			$seeded[] = $userId;
		});

		return ['paths' => $paths, 'seeded' => $seeded, 'skipped' => $skipped];
	}

	/**
	 * Enabled flag and path list for many users in two queries rather than two per user - the admin
	 * page's read-only overview renders a row per seen user, and an N+1 there would be paid on
	 * every page load.
	 *
	 * @param list<string> $userIds
	 * @return array<string, array{enabled: bool, paths: list<string>}>
	 */
	public function overview(array $userIds): array {
		if ($userIds === []) {
			return [];
		}

		$enabled = $this->userConfig->getValuesByUsers(self::APP_ID, self::KEY_ENABLED, ValueType::STRING, $userIds);
		$paths = $this->userConfig->getValuesByUsers(self::APP_ID, self::KEY_INCLUDED_PATHS, ValueType::ARRAY, $userIds);

		$overview = [];
		foreach ($userIds as $userId) {
			$overview[$userId] = [
				'enabled' => ($enabled[$userId] ?? 'false') === 'true',
				'paths' => array_values((array)($paths[$userId] ?? [])),
			];
		}
		return $overview;
	}
}

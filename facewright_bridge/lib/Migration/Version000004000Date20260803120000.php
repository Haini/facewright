<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Migration;

use Closure;
use OCA\FacewrightBridge\Service\UserSettingsService;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Per-user mode: moves the old instance-wide included_paths into each existing user's own settings
 * and switches those users on.
 *
 * No schema change - facewright_processed is untouched and everything new lives in oc_preferences
 * via IUserConfig. The version bump in info.xml is what makes `occ upgrade` run this at all (and,
 * separately, what busts the per-app JS cache for the new personal page and the changed admin one).
 *
 * Data-only, and deliberately so: without it, per-user mode would silently switch face recognition
 * *off* for everyone on an existing instance, because a user with no stored preference is off by
 * design. That default is right for accounts created from here on and wrong for accounts that were
 * being scanned yesterday.
 *
 * The work lives in UserSettingsService::seedExistingUsers(), which the new
 * `occ facewright:init-user-settings [--dry-run]` calls too - so the same code can be inspected
 * before the upgrade and re-run afterwards. It is idempotent (users who already have settings are
 * skipped), and it does not delete the old appconfig row, so rolling back loses nothing.
 */
class Version000004000Date20260803120000 extends SimpleMigrationStep {
	public function __construct(private UserSettingsService $userSettings) {
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$result = $this->userSettings->seedExistingUsers();

		$paths = $result['paths'] === [] ? 'all files' : implode(', ', $result['paths']);
		$output->info(sprintf(
			'facewright: enabled face recognition for %d user(s) with folders: %s (%d already had their own settings)',
			count($result['seeded']),
			$paths,
			count($result['skipped']),
		));
	}
}

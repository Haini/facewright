<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Command;

use OCA\FacewrightBridge\Service\UserSettingsService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Manual counterpart to Version000004000's data step: gives every seen user who has no Facewright
 * settings of their own the old instance-wide included paths, and switches them on.
 *
 * Exists separately from the migration so the move can be inspected with --dry-run *before* running
 * occ upgrade, and re-run afterwards for users who appeared later (an account restored from backup,
 * or one that had never logged in when the upgrade ran). Idempotent either way - a user who already
 * has settings is skipped, never overwritten.
 */
class InitUserSettings extends Command {
	public function __construct(private UserSettingsService $userSettings) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('facewright:init-user-settings')
			->setDescription('Give users without their own Facewright settings the previous instance-wide included paths, and enable them')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change without writing anything');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$dryRun = (bool)$input->getOption('dry-run');
		$result = $this->userSettings->seedExistingUsers($dryRun);

		$paths = $result['paths'] === [] ? '(none - all files)' : implode(', ', $result['paths']);
		$output->writeln('Included paths to apply: ' . $paths);

		$verb = $dryRun ? 'Would enable' : 'Enabled';
		$output->writeln($verb . ' ' . count($result['seeded']) . ' user(s): ' . (implode(', ', $result['seeded']) ?: '-'));
		$output->writeln('Left alone (own settings already present): ' . count($result['skipped']) . ' user(s): ' . (implode(', ', $result['skipped']) ?: '-'));

		if ($dryRun) {
			$output->writeln('<comment>Dry run - nothing was written.</comment>');
		}
		return 0;
	}
}

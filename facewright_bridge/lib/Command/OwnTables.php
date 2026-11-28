<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Command;

use OCA\FacewrightBridge\Service\FaceStore;
use OCA\FacewrightBridge\Service\OwnTablesCheckService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Checks and repairs the tables we own (docs/own-tables.md).
 *
 *   --verify    read-only: check ours internally and the copy Memories reads against them (via the projection's
 *               reconcile), exit 1 on any mismatch
 *   --recount   rebuild each person's stored size and cover (facewright_cluster_stats) from our tables
 *
 * --verify failing after a period of normal use means a writer was missed, and the fix is the writer.
 */
class OwnTables extends Command {
	public function __construct(
		private OwnTablesCheckService $check,
		private FaceStore $faceStore,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('facewright:own-tables')
			->setDescription('Check our people and face tables, and rebuild the stored sizes (docs/own-tables.md)')
			->addOption('verify', null, InputOption::VALUE_NONE, 'Read-only: check our tables and that the copy Memories reads matches them, exit 1 on a mismatch')
			->addOption('recount', null, InputOption::VALUE_NONE, 'Rebuild every person\'s stored size and cover from our tables (the repair for --verify\'s people_stats checks)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$modes = array_filter([
			'verify' => $input->getOption('verify'),
			'recount' => $input->getOption('recount'),
		]);
		if (count($modes) !== 1) {
			$output->writeln('<error>Pass exactly one of --verify, --recount.</error>');
			return 1;
		}

		return match (array_key_first($modes)) {
			'verify' => $this->verify($output),
			'recount' => $this->recount($output),
		};
	}

	private function recount(OutputInterface $output): int {
		if (!$this->usable($output)) {
			return 1;
		}
		$output->writeln(sprintf('Stored the size and cover of %d people.', $this->faceStore->rebuildPeopleStats()));
		return 0;
	}

	private function verify(OutputInterface $output): int {
		if (!$this->usable($output)) {
			return 1;
		}
		$results = $this->check->verify();
		$failed = [];
		foreach ($results as $name => $entry) {
			[$value, $kind] = $entry;
			$flag = '';
			if ($kind === 'zero') {
				$flag = $value === 0 ? ' ok' : ' <error>must be 0</error>';
			} elseif ($kind === 'equal') {
				$flag = $value === $results[$entry[2]][0] ? ' ok' : ' <error>must equal ' . $entry[2] . '</error>';
			}
			if (str_contains($flag, 'error')) {
				$failed[] = $name;
			}
			$output->writeln(sprintf('%-36s %10d%s', $name, $value, $flag));
		}
		if ($failed !== []) {
			$output->writeln('');
			$output->writeln('<error>Verification failed: ' . implode(', ', $failed) . '.</error>');
			return 1;
		}
		return 0;
	}

	private function usable(OutputInterface $output): bool {
		if (!$this->check->isSupportedPlatform()) {
			$output->writeln('<error>This needs MySQL or MariaDB.</error>');
			return false;
		}
		if (!$this->check->tablesExist()) {
			$output->writeln('<error>The new tables do not exist yet: run occ upgrade first.</error>');
			return false;
		}
		return true;
	}
}

<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Command;

use OCA\FacewrightBridge\Service\OwnTablesMigrationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Moves people and face assignments into tables we own (docs/own-tables.md).
 *
 *   --precheck  read-only: count what the backfill would trip over
 *   --migrate   copy people and assignments into facewright_clusters / facewright_user_faces,
 *               resumable, and verify afterwards
 *   --verify    read-only: compare the copies with Recognize's tables, exit 1 on any mismatch
 *
 * The copies are kept in step by every writer from then on (FaceStore); --verify failing after a
 * period of normal use means a writer was missed, and the fix is the writer, not another backfill.
 */
class OwnTables extends Command {
	public function __construct(
		private OwnTablesMigrationService $migration,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('facewright:own-tables')
			->setDescription('Move people and face assignments into tables owned by Facewright (docs/own-tables.md)')
			->addOption('precheck', null, InputOption::VALUE_NONE, 'Read-only: count what the backfill would trip over')
			->addOption('migrate', null, InputOption::VALUE_NONE, 'Copy people and assignments into the new tables (processing must be paused)')
			->addOption('verify', null, InputOption::VALUE_NONE, 'Read-only: compare the new tables with Recognize\'s, exit 1 on a mismatch')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'With --migrate: report what would be copied, write nothing')
			->addOption('chunk', null, InputOption::VALUE_REQUIRED, 'With --migrate: id range per step', '5000')
			->addOption('reset-cursor', null, InputOption::VALUE_NONE, 'With --migrate: start again from the first row');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$modes = array_filter([
			'precheck' => $input->getOption('precheck'),
			'migrate' => $input->getOption('migrate'),
			'verify' => $input->getOption('verify'),
		]);
		if (count($modes) !== 1) {
			$output->writeln('<error>Pass exactly one of --precheck, --migrate, --verify.</error>');
			return 1;
		}

		return match (array_key_first($modes)) {
			'precheck' => $this->precheck($output),
			'migrate' => $this->migrate($input, $output),
			'verify' => $this->verify($output),
		};
	}

	private function precheck(OutputInterface $output): int {
		$blocked = $this->blockers($output, true);
		if ($blocked !== []) {
			$output->writeln('');
			$output->writeln('<error>Do not start the backfill: ' . implode(', ', $blocked) . '.</error>');
			return 1;
		}
		return 0;
	}

	/** @return list<string> the must-be-zero counts that are not zero */
	private function blockers(OutputInterface $output, bool $print): array {
		$blocked = [];
		foreach ($this->migration->precheck() as $name => $count) {
			$mustBeZero = in_array($name, OwnTablesMigrationService::MUST_BE_ZERO, true);
			if ($print) {
				$flag = $mustBeZero ? ($count === 0 ? ' ok' : ' <error>must be 0</error>') : '';
				$output->writeln(sprintf('%-36s %10d%s', $name, $count, $flag));
			}
			if ($mustBeZero && $count !== 0) {
				$blocked[] = $name;
			}
		}
		return $blocked;
	}

	private function migrate(InputInterface $input, OutputInterface $output): int {
		$dryRun = (bool)$input->getOption('dry-run');
		$chunk = max(1, (int)$input->getOption('chunk'));

		if (!$this->usable($output)) {
			return 1;
		}
		if (!$dryRun && !$this->migration->isPaused()) {
			$output->writeln('<error>Pause processing first (admin page, "Pause processing"): a row changing between a chunk\'s read and its write would be copied stale.</error>');
			return 1;
		}
		$blocked = $this->blockers($output, false);
		if ($blocked !== []) {
			$output->writeln('<error>Not starting: ' . implode(', ', $blocked) . ' must be 0 (occ facewright:own-tables --precheck).</error>');
			return 1;
		}

		if ($input->getOption('reset-cursor')) {
			$this->migration->resetCursor();
			$output->writeln('Cursor reset: the next run starts from the first row.');
		}

		$people = $this->migration->migrateClusters($chunk, $dryRun);
		$output->writeln(sprintf('people: %d in Recognize\'s table, %d in ours%s', $people['source'], $people['inStore'], $dryRun ? ' (dry run)' : ''));

		$after = $this->migration->getCursor();
		$max = $this->migration->maxDetectionId();
		$total = 0;
		while ($after < $max) {
			$step = $this->migration->migrateFaceRange($after, $chunk, $dryRun);
			$total += $step['source'];
			$after = $step['upper'];
			$output->writeln(sprintf('faces up to id %d: %d linked row(s) in range%s', min($after, $max), $step['source'], $dryRun ? ' (dry run)' : ''));
			if (!$dryRun) {
				$this->migration->setCursor($after);
			}
		}
		$output->writeln(sprintf('Total: %d face row(s)%s.', $total, $dryRun ? ' would be copied (dry run, nothing written)' : ' copied'));

		if ($dryRun) {
			return 0;
		}
		$this->migration->resetCursor();
		$output->writeln('');
		return $this->verify($output);
	}

	private function verify(OutputInterface $output): int {
		if (!$this->usable($output)) {
			return 1;
		}
		$results = $this->migration->verify();
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
		if (!$this->migration->isSupportedPlatform()) {
			$output->writeln('<error>This needs MySQL or MariaDB.</error>');
			return false;
		}
		if (!$this->migration->tablesExist()) {
			$output->writeln('<error>The new tables do not exist yet: run occ upgrade first.</error>');
			return false;
		}
		return true;
	}
}

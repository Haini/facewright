<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Command;

use OCA\FacewrightBridge\Service\OwnTablesMigrationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Moves people and face assignments into tables we own (docs/own-tables.md). Stage A.3 adds only
 * --precheck; the backfill, verification and counter seeding arrive with their own stages.
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
			->addOption('precheck', null, InputOption::VALUE_NONE, 'Read-only: count what the backfill would trip over');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!$input->getOption('precheck')) {
			$output->writeln('<error>Nothing to do yet: pass --precheck.</error>');
			return 1;
		}

		$counts = $this->migration->precheck();
		$blocked = [];
		foreach ($counts as $name => $count) {
			$mustBeZero = in_array($name, OwnTablesMigrationService::MUST_BE_ZERO, true);
			$flag = $mustBeZero ? ($count === 0 ? ' ok' : ' <error>must be 0</error>') : '';
			$output->writeln(sprintf('%-36s %10d%s', $name, $count, $flag));
			if ($mustBeZero && $count !== 0) {
				$blocked[] = $name;
			}
		}

		if ($blocked !== []) {
			$output->writeln('');
			$output->writeln('<error>Do not start the backfill: ' . implode(', ', $blocked) . '.</error>');
			return 1;
		}
		return 0;
	}
}

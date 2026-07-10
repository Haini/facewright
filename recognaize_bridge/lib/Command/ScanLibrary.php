<?php

declare(strict_types=1);

namespace OCA\RecognaizeBridge\Command;

use OCA\RecognaizeBridge\BackgroundJob\BatchScanJob;
use OCP\BackgroundJob\IJobList;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI entry point for BatchScanJob - see that class for why this is chunked and self-requeuing
 * rather than one huge occ recognaize:detect-everything call. Only starts the background scan;
 * the actual work happens on cron, same as the admin page's "Scan entire library" button (both
 * just enqueue the same job).
 */
class ScanLibrary extends Command {
	public function __construct(
		private IJobList $jobList,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('recognaize:scan')
			->setDescription('Scan the entire backlog for faces, in the background, without overloading the server')
			->addOption('chunk-size', null, InputOption::VALUE_REQUIRED, 'Files to enqueue per cron cycle', '200');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$chunkSize = max(1, (int)$input->getOption('chunk-size'));
		$this->jobList->add(BatchScanJob::class, ['chunkSize' => $chunkSize]);
		$output->writeln("Started a background scan (chunk size {$chunkSize}). It will enqueue detection jobs in chunks across upcoming cron cycles until the whole backlog is covered - check the admin page stats for progress.");
		return 0;
	}
}

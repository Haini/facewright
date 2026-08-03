<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Command;

use OCA\FacewrightBridge\Service\ClusterTriggerService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI entry point for ClusterScanJob - see that class for why clustering needs chunking (and why
 * it's chunked differently than detection). Only starts the background scan, one chain per user
 * with unclustered work (via ClusterTriggerService, shared with the admin page's "Run now" button
 * and ClusteringMaintenanceJob's automatic re-trigger); the actual work happens on cron. occ
 * facewright:cluster (singular, no "-scan") remains the synchronous, single-user, small-scale
 * command - unchanged, still useful for quick manual runs.
 */
class ClusterScan extends Command {
	public function __construct(
		private ClusterTriggerService $clusterTrigger,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('facewright:cluster-scan')
			->setDescription('Cluster every user\'s unclustered faces in the background, in chunks, without overloading the server')
			->addOption('chunk-size', null, InputOption::VALUE_REQUIRED, 'Unclustered faces to attempt per cron cycle', '300');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$chunkSize = max(1, (int)$input->getOption('chunk-size'));

		// --max-distance/--min-faces are gone on purpose: those are per-user settings now, edited on
		// each user's own settings page, and ClusterTriggerService reads them per user. A flag here
		// could only apply one pair to everyone, silently overriding what each user chose.
		$started = $this->clusterTrigger->startForUsersWithUnclustered($chunkSize);

		$output->writeln("Started clustering for {$started} user(s) (chunk size {$chunkSize}). Runs in the background across upcoming cron cycles - check the admin page for live status.");
		return 0;
	}
}

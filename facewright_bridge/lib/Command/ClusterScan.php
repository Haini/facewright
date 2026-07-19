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
			->addOption('chunk-size', null, InputOption::VALUE_REQUIRED, 'Unclustered faces to attempt per cron cycle', '300')
			->addOption('max-distance', null, InputOption::VALUE_REQUIRED, 'Max cosine distance to count as a match', '0.5')
			->addOption('min-faces', null, InputOption::VALUE_REQUIRED, 'Min matches (incl. self) to form/join a cluster', '3');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$chunkSize = max(1, (int)$input->getOption('chunk-size'));
		$maxDistance = (float)$input->getOption('max-distance');
		$minFaces = max(1, (int)$input->getOption('min-faces'));

		$started = $this->clusterTrigger->startForUsersWithUnclustered($maxDistance, $minFaces, $chunkSize);

		$output->writeln("Started clustering for {$started} user(s) (chunk size {$chunkSize}). Runs in the background across upcoming cron cycles - check the admin page for live status.");
		return 0;
	}
}

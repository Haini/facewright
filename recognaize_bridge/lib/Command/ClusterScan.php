<?php

declare(strict_types=1);

namespace OCA\RecognaizeBridge\Command;

use OCA\RecognaizeBridge\BackgroundJob\ClusterScanJob;
use OCP\BackgroundJob\IJobList;
use OCP\IUser;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI entry point for ClusterScanJob - see that class for why clustering needs chunking (and why
 * it's chunked differently than detection). Only starts the background scan, one chain per seen
 * user; the actual work happens on cron, same as the admin page's clustering "Run now" button
 * (both just enqueue ClusterScanJob). occ recognaize:cluster (singular, no "-scan") remains the
 * synchronous, single-user, small-scale command - unchanged, still useful for quick manual runs.
 */
class ClusterScan extends Command {
	public function __construct(
		private IJobList $jobList,
		private IUserManager $userManager,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('recognaize:cluster-scan')
			->setDescription('Cluster every seen user\'s faces in the background, in chunks, without overloading the server')
			->addOption('chunk-size', null, InputOption::VALUE_REQUIRED, 'Unclustered faces to attempt per cron cycle', '300')
			->addOption('max-distance', null, InputOption::VALUE_REQUIRED, 'Max cosine distance to count as a match', '0.5')
			->addOption('min-faces', null, InputOption::VALUE_REQUIRED, 'Min matches (incl. self) to form/join a cluster', '3');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$chunkSize = max(1, (int)$input->getOption('chunk-size'));
		$maxDistance = (float)$input->getOption('max-distance');
		$minFaces = max(1, (int)$input->getOption('min-faces'));

		$alreadyRunning = [];
		foreach ($this->jobList->getJobsIterator(ClusterScanJob::class, null, 0) as $job) {
			$argument = $job->getArgument();
			if (is_array($argument) && isset($argument['userId'])) {
				$alreadyRunning[$argument['userId']] = true;
			}
		}

		$started = 0;
		$this->userManager->callForSeenUsers(function (IUser $user) use (&$started, $maxDistance, $minFaces, $chunkSize, $alreadyRunning): void {
			if (isset($alreadyRunning[$user->getUID()])) {
				return;
			}
			$this->jobList->add(ClusterScanJob::class, [
				'userId' => $user->getUID(),
				'maxDistance' => $maxDistance,
				'minFaces' => $minFaces,
				'chunkSize' => $chunkSize,
				'afterId' => 0,
				'passProgress' => false,
			]);
			$started++;
		});

		$output->writeln("Started clustering for {$started} user(s) (chunk size {$chunkSize}). Runs in the background across upcoming cron cycles - check the admin page for live status.");
		return 0;
	}
}

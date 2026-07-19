<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Command;

use OCA\FacewrightBridge\Service\ClusterMergeService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Manual trigger for ClusterMergeService - see that class for why this exists (Recognize's own
 * rename-based DAV UI has no merge concept at all, it just refuses a duplicate name).
 */
class MergeClusters extends Command {
	public function __construct(
		private ClusterMergeService $merge,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('facewright:merge-clusters')
			->setDescription('Merge two face clusters belonging to the same user into one, for when the same person ended up as two separate clusters')
			->addArgument('user', InputArgument::REQUIRED, 'Nextcloud user id who owns both clusters')
			->addArgument('keep', InputArgument::REQUIRED, 'Cluster to keep (its title, if any, survives) - numeric ID or name')
			->addArgument('merge', InputArgument::REQUIRED, 'Cluster to merge in and delete - numeric ID or name');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$userId = $input->getArgument('user');
		$keep = $input->getArgument('keep');
		$merge = $input->getArgument('merge');

		try {
			$moved = $this->merge->merge($userId, $keep, $merge);
		} catch (\InvalidArgumentException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}

		$output->writeln("Moved {$moved} face(s) into \"{$keep}\", and deleted \"{$merge}\".");
		return 0;
	}
}

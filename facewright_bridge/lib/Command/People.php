<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Command;

use OCA\FacewrightBridge\Service\PeopleService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Inspection and light maintenance for PeopleService, without a browser - how every later step
 * of docs/independence-implementation.md's Stage 0/2 work is verified. Lists a user's clusters
 * by default; --faces/--unassigned narrow to one cluster's or the unassigned pool's member faces;
 * --delete-empty runs PeopleService::deleteEmptyClusters().
 */
class People extends Command {
	public function __construct(
		private PeopleService $people,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('facewright:people')
			->setDescription("Inspect or lightly maintain a user's Facewright people (clusters) and faces")
			->addArgument('user', InputArgument::REQUIRED, 'Nextcloud user id')
			->addOption('faces', null, InputOption::VALUE_REQUIRED, 'List faces in this cluster (numeric id or title)')
			->addOption('unassigned', null, InputOption::VALUE_NONE, 'List faces at cluster_id = -1 (unassigned)')
			->addOption('ignored', null, InputOption::VALUE_NONE, 'List faces at cluster_id = -3 (marked "not a face")')
			->addOption('delete-empty', null, InputOption::VALUE_NONE, 'Delete this user\'s clusters with no remaining members')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'With --delete-empty, report the count without deleting');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$userId = $input->getArgument('user');

		if ($input->getOption('delete-empty')) {
			return $this->deleteEmpty($output, $userId, $input->getOption('dry-run'));
		}

		if ($input->getOption('unassigned')) {
			$this->printFaces($output, $this->people->listUnassignedFaces($userId));
			return 0;
		}

		if ($input->getOption('ignored')) {
			$this->printFaces($output, $this->people->listIgnoredFaces($userId));
			return 0;
		}

		$facesOf = $input->getOption('faces');
		if ($facesOf !== null) {
			try {
				$clusterId = $this->people->resolveCluster($userId, $facesOf);
			} catch (\InvalidArgumentException $e) {
				$output->writeln('<error>' . $e->getMessage() . '</error>');
				return 1;
			}
			$this->printFaces($output, $this->people->listFaces($userId, $clusterId));
			return 0;
		}

		foreach ($this->people->listClusters($userId) as $cluster) {
			$title = $cluster['title'] !== '' ? $cluster['title'] : '(untitled)';
			$output->writeln("{$cluster['id']}\t{$title}\t{$cluster['count']} face(s)");
		}
		return 0;
	}

	/** @param list<array{id: int, file_id: int, x: float, y: float, width: float, height: float}> $faces */
	private function printFaces(OutputInterface $output, array $faces): void {
		foreach ($faces as $face) {
			$output->writeln(sprintf(
				"detection %d\tfile %d\tbox [%.4f, %.4f, %.4f, %.4f]",
				$face['id'],
				$face['file_id'],
				$face['x'],
				$face['y'],
				$face['width'],
				$face['height'],
			));
		}
	}

	private function deleteEmpty(OutputInterface $output, string $userId, bool $dryRun): int {
		if ($dryRun) {
			$empty = array_filter($this->people->listClusters($userId), fn (array $c): bool => $c['count'] === 0);
			$output->writeln('Dry run - would delete ' . count($empty) . ' empty person(s).');
			return 0;
		}
		$deleted = $this->people->deleteEmptyClusters($userId);
		$output->writeln("Deleted {$deleted} empty person(s).");
		return 0;
	}
}

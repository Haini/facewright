<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Command;

use OCA\FacewrightBridge\Service\ClusteringService;
use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Manual, single-user trigger for ClusteringService - see that class for the actual algorithm.
 * The admin page's "Run now" clustering trigger runs the same logic across every seen user.
 */
class ClusterFaces extends Command {
	public function __construct(
		private ClusteringService $clustering,
		private IDBConnection $db,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('facewright:cluster')
			->setDescription("Prototype: cluster a user's face detections by embedding similarity")
			->addArgument('user', InputArgument::REQUIRED)
			->addOption('max-distance', null, InputOption::VALUE_REQUIRED, 'Max cosine distance to count as a match', '0.5')
			->addOption('min-faces', null, InputOption::VALUE_REQUIRED, 'Min matches (incl. self) to form/join a cluster', '3')
			->addOption('reset', null, InputOption::VALUE_NONE, 'Clear this user\'s existing cluster assignments first');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$userId = $input->getArgument('user');
		$maxDistance = (float)$input->getOption('max-distance');
		$minFaces = (int)$input->getOption('min-faces');

		if ($input->getOption('reset')) {
			$this->clustering->resetUser($userId);
			$output->writeln('Reset existing cluster assignments.');
		}

		$result = $this->clustering->clusterUser($userId, $maxDistance, $minFaces);

		if ($result['skippedForeign'] > 0) {
			fwrite(STDERR, "Skipped {$result['skippedForeign']} detection(s) with non-InsightFace vector dimensions.\n");
		}

		$output->writeln(sprintf(
			'%d new cluster(s), %d face(s) joined an existing cluster, %d face(s) left unclustered (fewer than %d matches).',
			$result['newClusters'],
			$result['joined'],
			$result['skipped'],
			$minFaces,
		));
		if ($result['markedUnclusterable'] > 0) {
			$output->writeln(sprintf(
				'Marked %d detection(s) unclusterable - this pass assigned nothing, so they are retired '
				. 'out of NULL until new detections give them something to match.',
				$result['markedUnclusterable'],
			));
		}
		$this->printClusterSizes($output, $userId);

		return 0;
	}

	private function printClusterSizes(OutputInterface $output, string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->select('cluster_id', $qb->func()->count('*', 'c'))
			->from('recognize_face_detections')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->isNotNull('cluster_id'))
			->groupBy('cluster_id')
			->orderBy('c', 'DESC');
		foreach ($qb->executeQuery()->fetchAll() as $row) {
			$output->writeln("  cluster {$row['cluster_id']}: {$row['c']} face(s)");
		}
	}
}

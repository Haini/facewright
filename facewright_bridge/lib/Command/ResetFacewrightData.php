<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Command;

use OCA\FacewrightBridge\Service\ClusteringService;
use OCA\FacewrightBridge\Service\ResetService;
use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Wipes facewright's own detections/clusters/processed-tracking for a clean slate, leaving
 * Recognize's own legacy 128-dim data (pre-existing named people) untouched. See ResetService
 * for why this is safe and instance-wide rather than per-user.
 */
class ResetFacewrightData extends Command {
	public function __construct(
		private ResetService $reset,
		private IDBConnection $db,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('facewright:reset')
			->setDescription("Delete all of facewright's own face detections/clusters (512-dim InsightFace data) and processed-file tracking, instance-wide. Leaves Recognize's own legacy data untouched.")
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be deleted without deleting anything')
			->addOption('yes', null, InputOption::VALUE_NONE, 'Skip the confirmation prompt');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$counts = $this->countOwnRows();
		$output->writeln(sprintf(
			'Found %d facewright detection(s) across %d cluster(s), and %d processed-file record(s).',
			$counts['detections'],
			$counts['clusters'],
			$counts['processed'],
		));

		if ($input->getOption('dry-run')) {
			$output->writeln('Dry run - nothing deleted.');
			return 0;
		}

		if (!$input->getOption('yes')) {
			$helper = $this->getHelper('question');
			$question = new ConfirmationQuestion(
				'This will permanently delete the above, instance-wide, for all users. Continue? [y/N] ',
				false,
			);
			if (!$helper->ask($input, $output, $question)) {
				$output->writeln('Aborted.');
				return 1;
			}
		}

		$result = $this->reset->resetFacewrightData();
		$output->writeln(sprintf(
			'Deleted %d detection(s), %d cluster(s), cleared %d processed-file record(s).',
			$result['deletedDetections'],
			$result['deletedClusters'],
			$result['clearedProcessed'],
		));

		return 0;
	}

	/** @return array{detections: int, clusters: int, processed: int} */
	private function countOwnRows(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('face_vector', 'cluster_id')->from('recognize_face_detections');
		$rows = $qb->executeQuery()->fetchAll();

		$detections = 0;
		$clusterIds = [];
		foreach ($rows as $row) {
			$vector = json_decode($row['face_vector'], true);
			if (!is_array($vector) || count($vector) !== ClusteringService::EXPECTED_DIMENSIONS) {
				continue;
			}
			$detections++;
			if ($row['cluster_id'] !== null) {
				$clusterIds[(int)$row['cluster_id']] = true;
			}
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'c'))->from('facewright_processed');
		$processed = (int)$qb->executeQuery()->fetchOne();

		return ['detections' => $detections, 'clusters' => count($clusterIds), 'processed' => $processed];
	}
}

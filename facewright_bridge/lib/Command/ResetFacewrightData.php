<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Command;

use OCA\FacewrightBridge\Service\ResetService;
use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Wipes facewright's own faces, people and processed-file tracking for a clean slate, and empties the copy Memories
 * reads. See ResetService for why it is instance-wide rather than per-user.
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
			->setDescription("Delete all of facewright's own faces and people and the processed-file tracking, instance-wide, and empty the copy Memories reads")
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
			'Deleted %d detection(s), %d cluster(s), %d physical face(s), cleared %d processed-file record(s).',
			$result['deletedDetections'],
			$result['deletedClusters'],
			$result['deletedFaces'],
			$result['clearedProcessed'],
		));
		$output->writeln($result['cacheCleared']
			? "Emptied the ExApp's embedding cache."
			: "<comment>Could not empty the ExApp's embedding cache (logged); it is disposable and prunes itself.</comment>");

		return 0;
	}

	/**
	 * What a reset would remove, counted in the database.
	 *
	 * Aggregates rather than the fetchAll()-and-decode this used to do - the preview was the first
	 * thing to hit the memory_limit, which is the worst place for it: the safety rail on a
	 * destructive command is exactly what has to keep working.
	 *
	 * The counts mirror what ResetService::findOwnRows() collects, so the numbers printed here are the numbers the
	 * delete will report back.
	 *
	 * @return array{detections: int, clusters: int, processed: int}
	 */
	private function countOwnRows(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from('facewright_user_faces');
		$detections = (int)$qb->executeQuery()->fetchOne();

		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from('facewright_clusters');
		$clusters = (int)$qb->executeQuery()->fetchOne();

		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'c'))->from('facewright_processed');
		$processed = (int)$qb->executeQuery()->fetchOne();

		return ['detections' => $detections, 'clusters' => $clusters, 'processed' => $processed];
	}
}

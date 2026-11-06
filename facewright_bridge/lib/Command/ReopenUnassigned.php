<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Command;

use OCA\FacewrightBridge\Service\FaceState;
use OCA\FacewrightBridge\Service\FaceStore;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * One-time migration for the meaning change of cluster_id = -1 (see ClusteringService::UNASSIGNED
 * and docs/independence-implementation.md Stage 0). Before this runs, our own clustering treats -1
 * as "Recognize rejected this, re-evaluate it"; after the code in this same stage deploys, -1
 * means "unassigned - excluded from clustering until a human assigns it" and is never
 * re-evaluated automatically. Rows Recognize's own HDBSCAN previously parked at -1 would
 * therefore be stranded forever unless reopened once, here, before that meaning change ships.
 *
 * Safe to leave in the tree and re-run: once the meaning has changed, the only rows this can ever
 * touch are ones a human has since unassigned, which is exactly what --dry-run's count is for.
 */
class ReopenUnassigned extends Command {
	public function __construct(
		private IDBConnection $db,
		private FaceStore $faceStore,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('facewright:reopen-unassigned')
			->setDescription('One-time: reopen own detections at cluster_id = -1 (Recognize\'s legacy "rejected" verdict) back to pending, before -1 is redefined to mean "unassigned"')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report the count without writing')
			->addOption('user', null, InputOption::VALUE_REQUIRED, 'Restrict to one user id (default: all users)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$userId = $input->getOption('user');

		$matching = $this->countMatching($userId);
		$output->writeln("Found {$matching} own detection(s) at cluster_id = -1.");

		if ($input->getOption('dry-run')) {
			$output->writeln('Dry run - nothing changed.');
			return 0;
		}

		$affected = $this->reopen($userId);
		$output->writeln("Reopened {$affected} / {$matching} to pending (cluster_id = NULL).");
		$output->writeln('After this, -1 means unassigned - run this once, before deploying step 0.2.');

		return 0;
	}

	private function countMatching(?string $userId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from('facewright_user_faces')
			->where($qb->expr()->eq('state', $qb->createNamedParameter(FaceState::UNASSIGNED, IQueryBuilder::PARAM_INT)));
		if ($userId !== null) {
			$qb->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		}
		return (int)$qb->executeQuery()->fetchOne();
	}

	private function reopen(?string $userId): int {
		// The ids come from our own table; Recognize's table is then written by id, and our copy is
		// rewritten from those rows afterwards.
		$sel = $this->db->getQueryBuilder();
		$sel->select('id')
			->from('facewright_user_faces')
			->where($sel->expr()->eq('state', $sel->createNamedParameter(FaceState::UNASSIGNED, IQueryBuilder::PARAM_INT)));
		if ($userId !== null) {
			$sel->andWhere($sel->expr()->eq('user_id', $sel->createNamedParameter($userId)));
		}
		$ids = array_map('intval', $sel->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));

		$affected = 0;
		$this->db->beginTransaction();
		try {
			foreach (array_chunk($ids, 1000) as $batch) {
				$qb = $this->db->getQueryBuilder();
				$qb->update('recognize_face_detections')
					->set('cluster_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
					->where($qb->expr()->in('id', $qb->createNamedParameter($batch, IQueryBuilder::PARAM_INT_ARRAY)))
					->andWhere($qb->expr()->eq('cluster_id', $qb->createNamedParameter(-1, IQueryBuilder::PARAM_INT)));
				$affected += $qb->executeStatement();
			}
			$this->faceStore->mirrorFaces($ids);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return $affected;
	}
}

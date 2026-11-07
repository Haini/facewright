<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Command;

use OCA\FacewrightBridge\Service\FaceState;
use OCA\FacewrightBridge\Service\FaceStore;
use OCA\FacewrightBridge\Service\ProjectionService;
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
		private ProjectionService $projection,
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
		$output->writeln("Reopened {$affected} / {$matching} to pending.");

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
		$sel = $this->db->getQueryBuilder();
		$sel->select('id', 'user_id')
			->from('facewright_user_faces')
			->where($sel->expr()->eq('state', $sel->createNamedParameter(FaceState::UNASSIGNED, IQueryBuilder::PARAM_INT)));
		if ($userId !== null) {
			$sel->andWhere($sel->expr()->eq('user_id', $sel->createNamedParameter($userId)));
		}
		$byUser = [];
		$result = $sel->executeQuery();
		while (($row = $result->fetch()) !== false) {
			$byUser[(string)$row['user_id']][] = (int)$row['id'];
		}
		$result->closeCursor();

		$reopened = [];
		$this->db->beginTransaction();
		try {
			foreach ($byUser as $uid => $ids) {
				array_push($reopened, ...$this->faceStore->setState($uid, $ids, FaceState::PENDING, null, [FaceState::UNASSIGNED]));
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		// Pending is hidden from Memories: the faces leave Recognize's table.
		$this->projection->push($reopened);
		return count($reopened);
	}
}

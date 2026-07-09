<?php

declare(strict_types=1);

namespace OCA\RecognaizeBridge\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;

/**
 * Tracks which files recognaize has attempted detection on, in its own recognaize_processed
 * table - not recognize_face_detections, which never gets a row for a zero-face photo and so
 * can't answer "has this file been processed" on its own (that gap let zero-face files get
 * reprocessed forever by the batch trigger). Backs both the idempotency check DetectQueuedJob
 * and BatchTriggerService rely on, and the admin-page stats.
 */
class ProcessedFileService {
	public function __construct(
		private IDBConnection $db,
		private ITimeFactory $timeFactory,
		private IUserManager $userManager,
		private IRootFolder $rootFolder,
		private PathFilterService $pathFilter,
	) {
	}

	/**
	 * Upsert, not a plain insert - recognaize:detect deliberately reprocesses files that were
	 * already processed (unlike the automatic/batch paths, which skip them), so this needs to
	 * tolerate being called again for the same user_id+file_id.
	 */
	public function markProcessed(string $userId, int $fileId, int $facesFound): void {
		$now = $this->timeFactory->getTime();

		if ($this->isProcessed($userId, $fileId)) {
			$qb = $this->db->getQueryBuilder();
			$qb->update('recognaize_processed')
				->set('processed_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
				->set('faces_found', $qb->createNamedParameter($facesFound, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
			$qb->executeStatement();
			return;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->insert('recognaize_processed')
			->values([
				'user_id' => $qb->createNamedParameter($userId),
				'file_id' => $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
				'processed_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
				'faces_found' => $qb->createNamedParameter($facesFound, IQueryBuilder::PARAM_INT),
			]);
		$qb->executeStatement();
	}

	public function isProcessed(string $userId, int $fileId): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'c'))
			->from('recognaize_processed')
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return (int)$qb->executeQuery()->fetchOne() > 0;
	}

	public function countProcessed(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'c'))->from('recognaize_processed');
		return (int)$qb->executeQuery()->fetchOne();
	}

	public function countProcessedSince(int $timestamp): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'c'))
			->from('recognaize_processed')
			->where($qb->expr()->gte('processed_at', $qb->createNamedParameter($timestamp, IQueryBuilder::PARAM_INT)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	/**
	 * Total candidate files across all seen users, under the configured included paths - the
	 * same enumeration BatchTriggerService uses to decide what to enqueue, so this is exactly
	 * "processed + not yet processed", not an independent estimate.
	 */
	public function countCandidates(): int {
		$total = 0;
		$this->userManager->callForSeenUsers(function (IUser $user) use (&$total): void {
			$userFolder = $this->rootFolder->getUserFolder($user->getUID());
			foreach ($this->pathFilter->resolveSearchRoots($userFolder) as $folder) {
				$total += count($folder->searchByMime('image'));
			}
		});
		return $total;
	}
}

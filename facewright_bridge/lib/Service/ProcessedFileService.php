<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IUserManager;

/**
 * Tracks which files facewright has attempted detection on, in its own facewright_processed
 * table - not recognize_face_detections, which never gets a row for a zero-face photo and so
 * can't answer "has this file been processed" on its own (that gap let zero-face files get
 * reprocessed forever by the batch trigger). Backs both the idempotency check DetectQueuedJob
 * and BatchTriggerService rely on, and the admin-page stats.
 */
class ProcessedFileService {
	// faces_found value recording "detection was attempted and permanently failed", as opposed
	// to 0, which is a genuine zero-face photo. Both count as processed - the point of marking a
	// permanent failure is precisely that it must not be retried - but conflating them would
	// lose the distinction in a column that already exists to answer what happened to a file.
	public const FACES_FAILED = -1;

	public function __construct(
		private IDBConnection $db,
		private ITimeFactory $timeFactory,
		private IUserManager $userManager,
		private IRootFolder $rootFolder,
		private PathFilterService $pathFilter,
		private UserSettingsService $userSettings,
	) {
	}

	/**
	 * Upsert, not a plain insert - facewright:detect deliberately reprocesses files that were
	 * already processed (unlike the automatic/batch paths, which skip them), so this needs to
	 * tolerate being called again for the same user_id+file_id.
	 *
	 * IDBConnection::setValues() rather than the read-then-write this used to be: it inserts, and
	 * on a unique-constraint violation against facewright_proc_user_file falls back to an update.
	 * Besides halving the round trips, that closes a race fan-out widens - an old-shape
	 * {userId, fileId} job and a new-shape {fileId} job can both be live for the same file, both
	 * pass an isProcessed() check, and both try to insert.
	 *
	 * (There is no IDBConnection::insertOrUpdate() in NC's public API - only QBMapper's, which
	 * needs an Entity/Mapper layer this app deliberately doesn't have. setValues() is the
	 * equivalent primitive.)
	 *
	 * @return bool true if a new row was created, false if an existing one was updated
	 */
	public function markProcessed(string $userId, int $fileId, int $facesFound): bool {
		return $this->db->setValues(
			'facewright_processed',
			['user_id' => $userId, 'file_id' => $fileId],
			[
				'processed_at' => $this->timeFactory->getTime(),
				'faces_found' => $facesFound,
			],
		) === 1;
	}

	/** @param list<string> $userIds */
	public function markManyProcessed(array $userIds, int $fileId, int $facesFound): void {
		foreach ($userIds as $userId) {
			$this->markProcessed($userId, $fileId, $facesFound);
		}
	}

	/**
	 * Carries the source's faces_found across, so the FACES_FAILED (-1) vs 0 distinction survives a
	 * fan-out copy: a file the ExApp permanently rejected for one user must not start being
	 * retried for the next one.
	 */
	public function copyMarker(int $fileId, string $fromUser, string $toUser, int $fallbackFacesFound = 0): void {
		$qb = $this->db->getQueryBuilder();
		$qb->select('faces_found')
			->from('facewright_processed')
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($fromUser)));
		$found = $qb->executeQuery()->fetchOne();

		$this->markProcessed($toUser, $fileId, $found === false ? $fallbackFacesFound : (int)$found);
	}

	/**
	 * Which users have already had this file attempted. Replaces the per-user isProcessed() check
	 * on the detection path: under fan-out "processed" is no longer a yes/no per file, since a file
	 * long since processed for its owner still needs rows for someone it was just shared with.
	 *
	 * Filters on file_id alone, hence facewright_proc_file (Version000003000) - the pre-existing
	 * unique index leads with user_id and is no help here.
	 *
	 * @return list<string>
	 */
	public function getProcessedUsers(int $fileId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('user_id')
			->from('facewright_processed')
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		return array_column($qb->executeQuery()->fetchAll(), 'user_id');
	}

	public function deleteForFile(int $fileId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('facewright_processed')
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement();
	}

	/**
	 * @param list<string> $userIds users to keep. An empty list means "keep nobody" and deletes
	 *                              every row for the file - note this is NOT what a notIn() with an
	 *                              empty array would do (that renders as NOT IN (NULL) and matches
	 *                              nothing, so rows would survive exactly when nobody has access
	 *                              and they most need removing).
	 */
	public function deleteForFileExceptUsers(int $fileId, array $userIds): int {
		if ($userIds === []) {
			return $this->deleteForFile($fileId);
		}

		$qb = $this->db->getQueryBuilder();
		$qb->delete('facewright_processed')
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->notIn('user_id', $qb->createNamedParameter($userIds, IQueryBuilder::PARAM_STR_ARRAY)));
		return $qb->executeStatement();
	}

	public function isProcessed(string $userId, int $fileId): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'c'))
			->from('facewright_processed')
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return (int)$qb->executeQuery()->fetchOne() > 0;
	}

	/**
	 * Rows, i.e. (user, file) pairs - not files. Under fan-out one file yields one row per user
	 * with access, so this is the number that explains the table's growth. countProcessedFiles() is
	 * the one to compare against countCandidates().
	 */
	public function countProcessed(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'c'))->from('facewright_processed');
		return (int)$qb->executeQuery()->fetchOne();
	}

	/**
	 * Distinct files attempted, which is what "how much of the library is done" actually means.
	 * Needs facewright_proc_file to avoid a full scan.
	 */
	public function countProcessedFiles(): int {
		$qb = $this->db->getQueryBuilder();
		// createFunction rather than func()->count(): IFunctionBuilder has no distinct(), and
		// COUNT(DISTINCT ...) is plain standard SQL, so nothing here is dialect-specific.
		$qb->select($qb->createFunction('COUNT(DISTINCT ' . $qb->getColumnName('file_id') . ')'))
			->from('facewright_processed');
		return (int)$qb->executeQuery()->fetchOne();
	}

	/** Distinct files attempted for one user - the per-user half of countProcessedFiles(). */
	public function countProcessedFilesForUser(string $userId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(DISTINCT ' . $qb->getColumnName('file_id') . ')'))
			->from('facewright_processed')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	public function countProcessedSince(int $timestamp): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'c'))
			->from('facewright_processed')
			->where($qb->expr()->gte('processed_at', $qb->createNamedParameter($timestamp, IQueryBuilder::PARAM_INT)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	public function countProcessedSinceForUser(string $userId, int $timestamp): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'c'))
			->from('facewright_processed')
			->where($qb->expr()->gte('processed_at', $qb->createNamedParameter($timestamp, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	/**
	 * Candidate *files* under each opted-in user's own included paths - the same enumeration
	 * BatchTriggerService uses to decide what to enqueue, so this is exactly "processed + not yet
	 * processed", not an independent estimate. Users who have not opted in are not walked, exactly
	 * as they are not walked when enqueueing.
	 *
	 * The total is a set of file ids rather than a sum of the per-user counts. A file shared between
	 * two users is reached by both walks and would otherwise be counted twice - which was already
	 * wrong before fan-out, and becomes badly wrong after it, since countProcessedFiles() on the
	 * other side of the subtraction counts each file once. The per-user numbers are returned from
	 * the same walk because the admin overview needs both, and walking a ~54k-file library twice per
	 * page load is not acceptable.
	 *
	 * @return array{total: int, perUser: array<string, int>}
	 */
	public function countCandidates(): array {
		$seen = [];
		$perUser = [];

		foreach ($this->userSettings->enabledUsers() as $userId) {
			$mine = $this->candidateFileIds($userId);
			$seen += $mine;
			$perUser[$userId] = count($mine);
		}

		return ['total' => count($seen), 'perUser' => $perUser];
	}

	/**
	 * One user's candidate count. The personal settings page needs exactly this and must not pay for
	 * countCandidates(), which walks every opted-in user's library - a page load per user would
	 * otherwise cost a full-instance enumeration.
	 */
	public function countCandidatesForUser(string $userId): int {
		if (!$this->userSettings->isEnabled($userId)) {
			return 0;
		}
		return count($this->candidateFileIds($userId));
	}

	/** @return array<int, true> file ids under this user's included paths, as a set */
	private function candidateFileIds(string $userId): array {
		if ($this->userManager->get($userId) === null) {
			return [];
		}

		$ids = [];
		$userFolder = $this->rootFolder->getUserFolder($userId);
		foreach ($this->pathFilter->resolveSearchRoots($userId, $userFolder) as $folder) {
			foreach ($folder->searchByMime('image') as $node) {
				$ids[$node->getId()] = true;
			}
		}
		return $ids;
	}
}

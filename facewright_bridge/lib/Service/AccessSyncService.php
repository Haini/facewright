<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;

/**
 * Keeps detection rows in step with who can actually see a file, after the fact - the other half of
 * fan-out. Detection writes rows for everyone with access *at detection time*; shares, unshares and
 * moves happen later, and this is what reconciles them.
 *
 * Two directions, and they are not symmetric:
 *  - Gaining access copies an existing user's rows rather than re-running inference. The embedding
 *    is a property of the image, not of the viewer, so a second detection pass would spend seconds
 *    of CPU to produce the same numbers.
 *  - Losing access deletes rows, but only ours (see the dimension guard) and only for users who
 *    really have no remaining path to the file.
 */
class AccessSyncService {
	public function __construct(
		private IDBConnection $db,
		private AccessService $accessService,
		private ProcessedFileService $processedFiles,
		private MountFileService $mountFiles,
		private IUserManager $userManager,
		private FaceStore $faceStore,
		private ProjectionService $projection,
	) {
	}

	/**
	 * Reconciles every image under a mount, one page at a time, resuming from $afterFileId.
	 *
	 * In dry-run mode `copied`/`pruned` stay at 0 and the would-be work is reported instead as
	 * `dryRunCopy`/`dryRunPrune`, keyed "fileId:userId". That is not bookkeeping fussiness: mounts
	 * nest, and on this instance Bob has files/Bilder shared *and* four folders inside it shared
	 * separately. A real run writes each file once (the second mount finds her already marked and
	 * skips), but a dry run writes nothing, so every nested mount would re-count the same files -
	 * inflating the estimate by 18% here. Returning keys lets the caller union them instead.
	 *
	 * @return array{examined: int, copied: int, pruned: int, lastFileId: ?int, usersTouched: list<string>, dryRunCopy: array<string, int>, dryRunPrune: array<string, int>}
	 */
	public function reconcileMount(
		int $storageId,
		int $rootId,
		int $afterFileId,
		int $limit,
		bool $dryRun = false,
		bool $prune = true,
	): array {
		$root = $this->mountFiles->locate($rootId);
		if ($root === null) {
			return [
				'examined' => 0, 'copied' => 0, 'pruned' => 0, 'lastFileId' => null,
				'usersTouched' => [], 'dryRunCopy' => [], 'dryRunPrune' => [],
			];
		}

		// The mount root's access set is fine for deciding who to copy *to*: copying to someone who
		// also reaches the file by another mount is redundant, never wrong. It is NOT usable as the
		// keep-list for deletes - see the per-file recomputation below.
		$mountUsers = $this->accessService->getUsersWithAccessToRoot($rootId);
		$fileIds = $this->mountFiles->listImageFileIds($storageId, $root['path'], $afterFileId, $limit);

		$copied = 0;
		$pruned = 0;
		$touched = [];
		$lastFileId = null;
		$dryRunCopy = [];
		$dryRunPrune = [];

		// A share can be a single file, not a folder - Nextcloud mounts those the same way, with
		// the file itself as the mount root. The subtree query below would never return it, so it
		// gets reconciled once, on the first chunk. For a folder root this costs two indexed
		// lookups that find nothing.
		if ($afterFileId === 0) {
			$rootResult = $this->reconcileFile($rootId, $dryRun, $prune);
			$copied += $rootResult['copied'];
			$pruned += $rootResult['pruned'];
			$dryRunCopy += $rootResult['dryRunCopy'];
			$dryRunPrune += $rootResult['dryRunPrune'];
		}

		foreach ($fileIds as $fileId) {
			$lastFileId = $fileId;
			$rowUsers = $this->usersWithRowsFor($fileId);
			$markedUsers = $this->processedFiles->getProcessedUsers($fileId);

			$missing = array_values(array_diff($mountUsers, $markedUsers));
			if ($missing !== []) {
				$result = $this->copyExisting($fileId, $missing, $dryRun, $rowUsers, $markedUsers);
				$copied += $result['copied'];
				$dryRunCopy += $this->keyPerUser($fileId, $missing, $result['perUserRows']);
				if ($result['handled']) {
					foreach ($missing as $userId) {
						$touched[$userId] = true;
					}
				}
			}

			if (!$prune) {
				continue;
			}
			$stale = array_diff($rowUsers, $mountUsers);
			if ($stale === []) {
				continue;
			}
			// Only now is the per-file access set worth its query. A user who looks stale relative
			// to *this* mount may hold access through another one - Bilder/ shared to one person
			// and Bilder/2024/ separately to another is enough to trigger it. Recognize's
			// onAccessUpdate applies the mount-root set as the keep-list for every file in the
			// mount and deletes the second person's rows; we don't.
			$keep = $this->accessService->getUsersWithAccess($fileId);
			$reallyStale = array_values(array_diff($rowUsers, $keep));
			if ($reallyStale === []) {
				continue;
			}
			if ($dryRun) {
				$dryRunPrune += $this->keyPerUser($fileId, $reallyStale, $this->countOwnRowsForUsers($fileId, $reallyStale) / max(1, count($reallyStale)));
			} else {
				$pruned += $this->pruneExceptUsers($fileId, $keep);
			}
			foreach ($reallyStale as $userId) {
				$touched[$userId] = true;
			}
		}

		return [
			'examined' => count($fileIds),
			'copied' => $copied,
			'pruned' => $pruned,
			'lastFileId' => $lastFileId,
			'usersTouched' => array_keys($touched),
			'dryRunCopy' => $dryRunCopy,
			'dryRunPrune' => $dryRunPrune,
		];
	}

	/**
	 * Spreads a per-file row count across the users it applies to, keyed so a caller can union
	 * results from overlapping mounts instead of adding them up.
	 *
	 * @param list<string> $userIds
	 *
	 * @return array<string, int>
	 */
	private function keyPerUser(int $fileId, array $userIds, float|int $rowsPerUser): array {
		$keyed = [];
		foreach ($userIds as $userId) {
			$keyed[$fileId . ':' . $userId] = (int)$rowsPerUser;
		}
		return $keyed;
	}

	/**
	 * Single-file reconcile, for events that name one node (a move in or out of a shared folder).
	 *
	 * @return array{copied: int, pruned: int, dryRunCopy: array<string, int>, dryRunPrune: array<string, int>}
	 */
	public function reconcileFile(int $fileId, bool $dryRun = false, bool $prune = true): array {
		$access = $this->accessService->getUsersWithAccess($fileId);
		$rowUsers = $this->usersWithRowsFor($fileId);
		$markedUsers = $this->processedFiles->getProcessedUsers($fileId);

		$copied = 0;
		$dryRunCopy = [];
		$missing = array_values(array_diff($access, $markedUsers));
		if ($missing !== []) {
			$result = $this->copyExisting($fileId, $missing, $dryRun, $rowUsers, $markedUsers);
			$copied = $result['copied'];
			$dryRunCopy = $this->keyPerUser($fileId, $missing, $result['perUserRows']);
		}

		$pruned = 0;
		$dryRunPrune = [];
		if ($prune) {
			$stale = array_values(array_diff($rowUsers, $access));
			if ($stale !== []) {
				if ($dryRun) {
					$dryRunPrune = $this->keyPerUser($fileId, $stale, $this->countOwnRowsForUsers($fileId, $stale) / max(1, count($stale)));
				} else {
					$pruned = $this->pruneExceptUsers($fileId, $access);
				}
			}
		}

		return ['copied' => $copied, 'pruned' => $pruned, 'dryRunCopy' => $dryRunCopy, 'dryRunPrune' => $dryRunPrune];
	}

	/**
	 * Gives $toUsers whatever this file's existing detection result was, without asking the ExApp
	 * again. Two cases, and the second one is easy to miss:
	 *
	 *  - Someone holds rows: copy them. The embedding describes the image, not the viewer.
	 *  - Nobody holds rows but someone holds a processed marker: the file has already been through
	 *    detection and yielded nothing (a photo with no faces, or one that permanently failed to
	 *    decode). Copying the marker is what stops it being re-inferred. This matters far more than
	 *    it sounds: on this instance 28,056 of Consti's 54,332 processed files are zero-face, so
	 *    treating "no rows" as "not yet detected" would re-run inference on more than half the
	 *    library every time a folder is shared.
	 *
	 * @param list<string>      $toUsers     users lacking a processed marker for this file
	 * @param list<string>|null $rowUsers    precomputed, when the caller already has it - this runs
	 *                                       once per file in a chunk loop, so the saved query is
	 *                                       one per file, not one overall
	 * @param list<string>|null $markedUsers likewise
	 *
	 * @return array{copied: int, handled: bool, perUserRows: int} handled=false means nothing exists
	 *         to copy and the caller has to run real detection. copied stays 0 in a dry run;
	 *         perUserRows is what each target user would gain, for the caller's union arithmetic.
	 */
	public function copyExisting(
		int $fileId,
		array $toUsers,
		bool $dryRun = false,
		?array $rowUsers = null,
		?array $markedUsers = null,
	): array {
		if ($toUsers === []) {
			return ['copied' => 0, 'handled' => true, 'perUserRows' => 0];
		}

		$donor = $this->findDonor($fileId, $rowUsers ?? $this->usersWithRowsFor($fileId));
		if ($donor !== null) {
			if ($dryRun) {
				return ['copied' => 0, 'handled' => true, 'perUserRows' => $this->countOwnRows($fileId, $donor)];
			}
			return [
				'copied' => $this->copyToUsers($fileId, $donor, $toUsers),
				'handled' => true,
				'perUserRows' => 0,
			];
		}

		$marked = $markedUsers ?? $this->processedFiles->getProcessedUsers($fileId);
		if ($marked === []) {
			return ['copied' => 0, 'handled' => false, 'perUserRows' => 0];
		}
		if (!$dryRun) {
			foreach ($toUsers as $toUser) {
				$this->processedFiles->copyMarker($fileId, $marked[0], $toUser);
			}
		}
		// A marker copy moves no detection rows - the file was already known to have no faces.
		return ['copied' => 0, 'handled' => true, 'perUserRows' => 0];
	}

	/**
	 * A user among $candidates who holds *our* rows for this file, i.e. one whose rows are worth
	 * copying. Legacy 128-dim rows don't qualify: they belong to a different embedding space, and
	 * propagating them would silently corrupt every distance computation in the target's pool.
	 *
	 * @param list<string> $candidates
	 */
	public function findDonor(int $fileId, array $candidates): ?string {
		if ($candidates === []) {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('u.user_id')
			->from('facewright_user_faces', 'u')
			->innerJoin('u', 'facewright_faces', 'f', $qb->expr()->eq('f.id', 'u.face_id'))
			->where($qb->expr()->eq('f.file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('u.user_id', $qb->createNamedParameter($candidates, IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('u.user_id', 'ASC')
			->setMaxResults(1);
		$userId = $qb->executeQuery()->fetchOne();
		return $userId === false ? null : (string)$userId;
	}

	/**
	 * @param list<string> $toUsers
	 *
	 * @return int rows written
	 */
	public function copyToUsers(int $fileId, string $fromUser, array $toUsers): int {
		$toUsers = array_values(array_diff(array_unique($toUsers), [$fromUser]));
		if ($toUsers === []) {
			return 0;
		}

		// The donor's rows: which physical face each points at, so each copy links to the same one.
		$qb = $this->db->getQueryBuilder();
		$qb->select('u.id', 'u.face_id')
			->from('facewright_user_faces', 'u')
			->innerJoin('u', 'facewright_faces', 'f', $qb->expr()->eq('f.id', 'u.face_id'))
			->where($qb->expr()->eq('f.file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('u.user_id', $qb->createNamedParameter($fromUser)))
			->orderBy('u.id', 'ASC');
		$rows = $qb->executeQuery()->fetchAll();

		// donor detection_id => face_id, so each copy links to the same physical face.
		$faceIdsByDonorRow = [];
		foreach ($rows as $row) {
			$faceIdsByDonorRow[(int)$row['id']] = (int)$row['face_id'];
		}

		// A user can already hold the rows without the marker: a crash between the two (see below), which only the
		// marker needs to heal. Their rows would break the unique (user, face) index, and every retry with it.
		$holders = $this->usersWithRowsFor($fileId);

		$written = 0;
		foreach ($toUsers as $toUser) {
			if (!$this->userManager->userExists($toUser)) {
				continue;
			}
			if (!in_array($toUser, $holders, true)) {
				$written += $this->copyRowsToUser($fileId, $toUser, $rows, $faceIdsByDonorRow);
			}
			// Rows before marker, same invariant as DetectionService::detect().
			$this->processedFiles->copyMarker($fileId, $fromUser, $toUser, count($rows));
		}

		return $written;
	}

	/**
	 * Copies the donor's faces for one file to one user: a pending row of ours and its link for each, in
	 * one transaction.
	 *
	 * @param list<array<string, mixed>> $rows
	 * @param array<int, int> $faceIdsByDonorRow donor detection id => physical face id
	 *
	 * @return int rows written
	 */
	private function copyRowsToUser(int $fileId, string $toUser, array $rows, array $faceIdsByDonorRow): int {
		$written = 0;
		$this->db->beginTransaction();
		try {
			foreach ($rows as $row) {
				$faceId = $faceIdsByDonorRow[(int)$row['id']] ?? null;
				if ($faceId === null) {
					continue;
				}
				// The copy starts PENDING (no person): people are per user, so the donor's person means nothing in
				// this user's namespace, and names do not travel with a share. The recipient's own clustering
				// picks the face up. Pending is hidden from Memories, so nothing is written to Recognize's table.
				$newRowId = $this->faceStore->addPendingFace($toUser, $faceId);
				$written++;

				$link = $this->db->getQueryBuilder();
				$link->insert('facewright_detection_faces')
					->values([
						'detection_id' => $link->createNamedParameter($newRowId, IQueryBuilder::PARAM_INT),
						'face_id' => $link->createNamedParameter($faceId, IQueryBuilder::PARAM_INT),
						'user_id' => $link->createNamedParameter($toUser),
					]);
				$link->executeStatement();
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return $written;
	}

	/**
	 * @param list<string> $keepUserIds users who still have access. Empty means nobody does, and
	 *                                  then every one of our rows for the file goes - deliberately
	 *                                  not expressed as notIn(..., []), which renders as
	 *                                  NOT IN (NULL) and matches nothing.
	 *
	 * @return int rows deleted across both tables
	 */
	public function pruneExceptUsers(int $fileId, array $keepUserIds): int {
		$keep = array_values(array_unique($keepUserIds));

		// Dumped first, then deleted by that exact id list - not "NOT IN (SELECT ... WHERE
		// file_id = ?)" against the rows already being deleted, which is exactly the kind of
		// re-run-the-selecting-query trap this project's own working practices warn against.
		$idQb = $this->db->getQueryBuilder();
		// Ours only, by construction: the ids come from our own table. Recognize's own
		// removeDetectionsForFileFromUsersNotInList() has no such guard, and copying its shape here
		// would delete this instance's legacy 128-dim rows - data this project has never claimed.
		$idQb->select('u.id')
			->from('facewright_user_faces', 'u')
			->innerJoin('u', 'facewright_faces', 'f', $idQb->expr()->eq('f.id', 'u.face_id'))
			->where($idQb->expr()->eq('f.file_id', $idQb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		if ($keep !== []) {
			$idQb->andWhere($idQb->expr()->notIn('u.user_id', $idQb->createNamedParameter($keep, IQueryBuilder::PARAM_STR_ARRAY)));
		}
		$idsToDelete = array_map('intval', array_column($idQb->executeQuery()->fetchAll(), 'id'));

		$deleted = 0;
		if ($idsToDelete !== []) {
			foreach (array_chunk($idsToDelete, 1000) as $chunk) {
				$this->db->beginTransaction();
				try {
					$linkQb = $this->db->getQueryBuilder();
					$linkQb->delete('facewright_detection_faces')
						->where($linkQb->expr()->in('detection_id', $linkQb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
					$linkQb->executeStatement();

					$this->faceStore->deleteFaces($chunk);
					$deleted += count($chunk);
					$this->db->commit();
				} catch (\Throwable $e) {
					$this->db->rollBack();
					throw $e;
				}
			}
		}

		// What Memories shows follows ours: the deleted faces leave Recognize's table too.
		$this->projection->push($idsToDelete);

		// Nobody has any path to the file left (the forgetFile() case) - the physical faces
		// themselves are orphaned too, not just this user's detection rows.
		if ($keep === []) {
			$facesQb = $this->db->getQueryBuilder();
			$facesQb->delete('facewright_faces')
				->where($facesQb->expr()->eq('file_id', $facesQb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
			$facesQb->executeStatement();
		}

		// facewright_processed is entirely ours, so no dimension question arises there.
		$deleted += $this->processedFiles->deleteForFileExceptUsers($fileId, $keep);

		return $deleted;
	}

	/** Deletes every trace of a file, for every user - what a file being gone means. */
	public function forgetFile(int $fileId): int {
		return $this->pruneExceptUsers($fileId, []);
	}

	/** @return list<string> every user holding one of our rows for this file */
	public function usersWithRowsFor(int $fileId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('u.user_id')
			->from('facewright_user_faces', 'u')
			->innerJoin('u', 'facewright_faces', 'f', $qb->expr()->eq('f.id', 'u.face_id'))
			->where($qb->expr()->eq('f.file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->groupBy('u.user_id');
		return array_column($qb->executeQuery()->fetchAll(), 'user_id');
	}

	private function countOwnRows(int $fileId, string $userId): int {
		return $this->countOwnRowsForUsers($fileId, [$userId]);
	}

	/** @param list<string> $userIds */
	private function countOwnRowsForUsers(int $fileId, array $userIds): int {
		if ($userIds === []) {
			return 0;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'c'))
			->from('facewright_user_faces', 'u')
			->innerJoin('u', 'facewright_faces', 'f', $qb->expr()->eq('f.id', 'u.face_id'))
			->where($qb->expr()->eq('f.file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('u.user_id', $qb->createNamedParameter($userIds, IQueryBuilder::PARAM_STR_ARRAY)));
		return (int)$qb->executeQuery()->fetchOne();
	}
}

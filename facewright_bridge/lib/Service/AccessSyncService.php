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
		$qb->select('user_id')
			->from('recognize_face_detections')
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('user_id', $qb->createNamedParameter($candidates, IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($this->ownRowsExpr($qb))
			->orderBy('user_id', 'ASC')
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

		// Columns named explicitly on both sides, and that is not pedantry: the table's physical
		// order is (x, y, height, width) while DetectionService writes (x, y, width, height), so a
		// positional copy would silently transpose every bounding box.
		$qb = $this->db->getQueryBuilder();
		$qb->select('x', 'y', 'width', 'height', 'face_vector')
			->from('recognize_face_detections')
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($fromUser)))
			->andWhere($this->ownRowsExpr($qb));
		$rows = $qb->executeQuery()->fetchAll();

		$written = 0;
		foreach ($toUsers as $toUser) {
			if (!$this->userManager->userExists($toUser)) {
				continue;
			}
			foreach ($rows as $row) {
				$insert = $this->db->getQueryBuilder();
				$insert->insert('recognize_face_detections')
					->values([
						'user_id' => $insert->createNamedParameter($toUser),
						'file_id' => $insert->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
						'x' => $insert->createNamedParameter($row['x']),
						'y' => $insert->createNamedParameter($row['y']),
						'width' => $insert->createNamedParameter($row['width']),
						'height' => $insert->createNamedParameter($row['height']),
						'face_vector' => $insert->createNamedParameter($row['face_vector']),
						// cluster_id deliberately omitted so the column default (NULL) applies.
						// Clusters are per-user rows in recognize_face_clusters, so the donor's
						// cluster id means nothing in this user's namespace. NULL is what
						// ClusteringService treats as pending, so the existing per-user
						// ClusterScanJob chain picks these up with no extra wiring - at the cost
						// that names don't travel with a share: the recipient's clustering
						// rediscovers the same people under its own, untitled cluster ids.
					]);
				$insert->executeStatement();
				$written++;
			}
			// Rows before marker, same invariant as DetectionService::detect().
			$this->processedFiles->copyMarker($fileId, $fromUser, $toUser, count($rows));
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

		$qb = $this->db->getQueryBuilder();
		$qb->delete('recognize_face_detections')
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			// Ours only. Recognize's own removeDetectionsForFileFromUsersNotInList() has no
			// equivalent guard, and copying its shape here would delete this instance's legacy
			// 128-dim rows - data this project has never claimed ownership of.
			->andWhere($this->ownRowsExpr($qb));
		if ($keep !== []) {
			$qb->andWhere($qb->expr()->notIn('user_id', $qb->createNamedParameter($keep, IQueryBuilder::PARAM_STR_ARRAY)));
		}
		$deleted = $qb->executeStatement();

		// facewright_processed is entirely ours, so no dimension question arises there.
		$deleted += $this->processedFiles->deleteForFileExceptUsers($fileId, $keep);

		return $deleted;
	}

	/** Deletes every trace of a file, for every user - what a file being gone means. */
	public function forgetFile(int $fileId): int {
		return $this->pruneExceptUsers($fileId, []);
	}

	/** @return list<string> every user holding any detection row for this file, ours or legacy */
	public function usersWithRowsFor(int $fileId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('user_id')
			->from('recognize_face_detections')
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->groupBy('user_id');
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
			->from('recognize_face_detections')
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('user_id', $qb->createNamedParameter($userIds, IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($this->ownRowsExpr($qb));
		return (int)$qb->executeQuery()->fetchOne();
	}

	/**
	 * One definition, shared by every query that has to tell our rows from Recognize's.
	 *
	 * @see DetectionService::ownRowsExpr()
	 */
	private function ownRowsExpr(IQueryBuilder $qb): string {
		return DetectionService::ownRowsExpr($qb);
	}
}

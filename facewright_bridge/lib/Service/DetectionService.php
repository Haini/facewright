<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Service;

use OCA\AppAPI\PublicFunctions;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Http\Client\IResponse;
use OCP\IDBConnection;

/**
 * Core detection logic shared by the manual occ command, the automatic file-event job, and the
 * batch trigger. Calls the facewright ExApp and stores the physical face in facewright_faces and, through
 * FaceStore, a pending row per user with access. The copy Memories reads is written by ProjectionService.
 */
class DetectionService {
	/**
	 * Mimetypes the ExApp can actually decode. It hands bytes to cv2.imdecode(), so this is
	 * OpenCV's format support, not "anything Nextcloud calls an image" - notably `image/x-dcraw`
	 * (Nextcloud's catch-all for raw camera files: .RW2, .CR2, .NEF, ...) always fails there.
	 *
	 * Both entry points into detection filter on this: FileCreatedListener when an upload fires,
	 * and BatchTriggerService when enumerating the backlog, which searches by the `image` prefix
	 * and so would otherwise sweep raw files in. DetectQueuedJob re-checks it as well, since the
	 * queue can outlive a change here.
	 */
	public const SUPPORTED_MIMETYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];

	public function __construct(
		private IDBConnection $db,
		private PublicFunctions $appAPI,
		private ProcessedFileService $processedFiles,
		private FaceStore $faceStore,
	) {
	}

	/**
	 * `permanent` distinguishes "this file will never decode" from "facewright was unreachable
	 * this time". Only the former may be recorded as processed - marking a transient failure
	 * would silently drop the file for good the next time the ExApp restarts mid-scan.
	 *
	 * One inference, N users' rows: $fetchUserId is only whose credentials the ExApp downloads the
	 * file as (so $path must be relative to *that* user's files root), while $targetUserIds is
	 * everyone who gets detection rows. Passing null keeps the single-user contract
	 * occ facewright:detect relies on.
	 *
	 * @param list<string>|null $targetUserIds
	 *
	 * @return array{success: bool, faces: int, inserted: int, users: int, error: ?string, permanent: bool}
	 */
	public function detect(string $fetchUserId, string $path, int $fileId, ?array $targetUserIds = null): array {
		// facewright's own inference can take longer than AppAPI's default 3s request timeout.
		$response = $this->appAPI->exAppRequest(
			'facewright',
			'/detect',
			$fetchUserId,
			'POST',
			['path' => $path],
			['timeout' => 30],
		);
		if (!$response instanceof IResponse) {
			// Transport-level: ExApp down, HaRP tunnel stale, timeout. Always worth retrying.
			return $this->failure('Request to facewright failed: ' . ($response['error'] ?? 'unknown error'), false);
		}
		$status = $response->getStatusCode();
		if ($status !== 200) {
			// 4xx is the ExApp's verdict on this specific file and will not change on a retry -
			// 400 "Could not decode image" for a truncated/unsupported file, 404 for one that has
			// gone away. 5xx is the ExApp itself failing, so that stays retryable.
			return $this->failure(
				'facewright returned HTTP ' . $status . ': ' . $response->getBody(),
				$status >= 400 && $status < 500,
			);
		}

		$data = json_decode($response->getBody(), true);
		$faces = $data['faces'] ?? [];
		$imageWidth = $data['image_width'] ?? null;
		$imageHeight = $data['image_height'] ?? null;
		if (!$imageWidth || !$imageHeight) {
			// A 200 in the wrong shape means the two halves are out of step (ExApp deployed
			// without image_width/image_height). That is a deploy to fix, not a bad file, so it
			// stays retryable rather than burning every file scanned during the mismatch.
			return $this->failure('facewright response is missing image dimensions', false);
		}

		$targets = array_values(array_unique($targetUserIds ?? [$fetchUserId]));
		// A re-run must not add a user's faces twice (the unique index would refuse them). One query, not one per user.
		//
		// Only the *inserts* are skipped for a user who already has rows - they still get a
		// processed marker below. Skipping both would leave a user with rows but no marker, which
		// the caller reads as still-pending, so the file would be re-enqueued and re-inferred on
		// every pass forever.
		$insertTargets = array_values(array_diff($targets, $this->usersWithRowsFor($fileId, $targets)));

		// One transaction for the whole file: the physical faces and each user's rows are written together
		// or not at all, so a crash cannot leave them disagreeing.
		// The processed marker stays outside and after it - see the invariant below.
		$this->db->beginTransaction();
		try {
			$inserted = $this->storeFaces($fileId, $faces, $insertTargets, $imageWidth, $imageHeight);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		// Recorded even for zero-face results: such a file has no row anywhere else, and would otherwise be
		// reprocessed on every trigger run.
		//
		// Rows first, marker second, and that order is an invariant rather than a style choice: a
		// crash in between leaves rows without a marker, which the next scan heals (it finds the
		// rows, skips the insert, writes the marker). Marker-first would strand the file as
		// processed-but-empty forever.
		$this->processedFiles->markManyProcessed($targets, $fileId, count($faces));

		return [
			'success' => true,
			'faces' => count($faces),
			'inserted' => $inserted,
			'users' => count($targets),
			'error' => null,
			'permanent' => false,
		];
	}

	/**
	 * Writes one file's faces: the physical face, then one pending row of ours per
	 * target user. Runs inside the caller's transaction.
	 *
	 * @param array<int, array<string, mixed>> $faces
	 * @param list<string> $insertTargets
	 *
	 * @return int detection rows inserted
	 */
	private function storeFaces(int $fileId, array $faces, array $insertTargets, int|float $imageWidth, int|float $imageHeight): int {
		$inserted = 0;
		foreach ($faces as $faceIndex => $face) {
			[$x1, $y1, $x2, $y2] = $face['bbox'];
			$x = $x1 / $imageWidth;
			$y = $y1 / $imageHeight;
			$width = ($x2 - $x1) / $imageWidth;
			$height = ($y2 - $y1) / $imageHeight;

			// The physical face, then the per-user row.
			$faceId = $this->upsertFace($fileId, $faceIndex, $x, $y, $width, $height, $face['det_score'] ?? null, $face['embedding']);

			foreach ($insertTargets as $userId) {
				// Pending faces are hidden from Memories, so nothing reaches the copy here. The embedding lives in
				// facewright_faces.embedding.
				$this->faceStore->addPendingFace($userId, $faceId);
				$inserted++;
			}
		}

		return $inserted;
	}

	/** @return array{success: bool, faces: int, inserted: int, users: int, error: ?string, permanent: bool} */
	private function failure(string $error, bool $permanent): array {
		return ['success' => false, 'faces' => 0, 'inserted' => 0, 'users' => 0, 'error' => $error, 'permanent' => $permanent];
	}

	/**
	 * Which of $userIds already hold *our* detection rows for this file.
	 *
	 * Only rows of our own table count: anything else would skip the insert for a user and still mark them processed,
	 * and nothing revisits a file whose marker says it is done.
	 *
	 * @param list<string> $userIds
	 *
	 * @return list<string>
	 */
	private function usersWithRowsFor(int $fileId, array $userIds): array {
		if ($userIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('u.user_id')
			->from('facewright_user_faces', 'u')
			->innerJoin('u', 'facewright_faces', 'f', $qb->expr()->eq('f.id', 'u.face_id'))
			->where($qb->expr()->eq('f.file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('u.user_id', $qb->createNamedParameter($userIds, IQueryBuilder::PARAM_STR_ARRAY)))
			->groupBy('u.user_id');
		return array_column($qb->executeQuery()->fetchAll(), 'user_id');
	}

	/**
	 * Upserts the physical face (facewright_faces) for one detected face in a file, and returns
	 * its id. IDBConnection::setValues() has no "return the id" mode - it returns 1 on insert, 0
	 * on update (there is no public insertOrUpdate() to read back from either) - so the id is
	 * fetched with a second query. One extra query per face is accepted here; the row-at-a-time
	 * insert loop this lives in is already CLAUDE.md Open item 6.
	 *
	 * @param list<float> $embedding
	 */
	private function upsertFace(int $fileId, int $faceIndex, float $x, float $y, float $width, float $height, ?float $detScore, array $embedding): int {
		$this->db->setValues('facewright_faces', [
			'file_id' => $fileId,
			'face_index' => $faceIndex,
		], [
			'x' => $x,
			'y' => $y,
			'width' => $width,
			'height' => $height,
			'det_score' => $detScore,
			'embedding' => $blob = FaceVectorCodec::encode($embedding),
			// Written with the blob, always: the ExApp's cache is validated by it (ExAppClusteringClient).
			'embedding_crc' => crc32($blob),
			'model' => 'buffalo_l',
		]);

		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('facewright_faces')
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('face_index', $qb->createNamedParameter($faceIndex, IQueryBuilder::PARAM_INT)));
		return (int)$qb->executeQuery()->fetchOne();
	}
}

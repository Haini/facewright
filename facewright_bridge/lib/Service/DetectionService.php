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
 * FaceStore, a pending row per user with access. Recognize's table is a copy, written by ProjectionService.
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

	/**
	 * Decimal places kept when encoding an embedding.
	 *
	 * InsightFace emits float32, but PHP floats are doubles and serialize_precision=-1 asks
	 * json_encode for the shortest round-trip of a *double* - so every component was being written
	 * at 21 characters when ~9 carries the entire float32 value. Measured on the live table: 10,752
	 * bytes per vector against 4,805 at 6 decimal places, and re-packing a stored vector through
	 * float32 and back changes its cosine distance by exactly 0.0, i.e. the extra characters
	 * carried no information at all.
	 *
	 * 6 was chosen over the alternatives because it is free: 4, 5 and 6 decimal places all yield 3
	 * rows per 16 KB InnoDB page, so the extra fidelity costs nothing. The resulting cosine error
	 * is 3.3e-7 against a clustering threshold of 0.5.
	 *
	 * The size effect is bigger than the byte count suggests. At ~10.7 KB a row exceeded InnoDB
	 * DYNAMIC's ~8,126-byte limit and its vector went off-page, taking a dedicated 16 KB page
	 * whatever the payload - which is why 195,883 rows occupied 3,087 MB for 2,008 MB of vectors,
	 * and why every vector fetch cost a page read read-ahead could not batch.
	 */
	public const VECTOR_PRECISION = 6;

	/**
	 * char_length(face_vector) above which a row of Recognize's table that has no row of ours is a stale
	 * copy of ours (512-dim InsightFace) rather than Recognize's legacy 128-dim face-api.js data; see
	 * ProjectionService::reconcile(). Used instead of decoding the JSON because it keeps the test set-based.
	 *
	 * Measured on the live table, there are three bands, not two, because re-encoding is
	 * incremental and old and new rows coexist while it runs:
	 *
	 *   legacy 128-dim          2,621 -  2,655   (39 rows)
	 *   ours, VECTOR_PRECISION  4,748 -  4,863
	 *   ours, pre-compaction   10,672 - 10,827
	 *
	 * 3500 is the only floor that reads all three correctly, with 2,093 characters of room below
	 * and 1,248 above. It replaced 6000, which was correct for two bands but would have silently
	 * reclassified every compacted row as Recognize's the moment the first one was written - across
	 * all 14 call sites at once, with no error anywhere.
	 *
	 * One row escapes the proxy and always has: id 31948 is a 512-dim embedding of all zeros, so it
	 * encodes to 1,292 characters and reads as legacy. It is a degenerate detection - cosine
	 * distance against an all-zero vector is undefined - and it is already parked at UNCLUSTERABLE,
	 * so the misclassification costs nothing beyond reset never collecting it. Worth knowing
	 * because the 1,292 lower bound previously recorded for the legacy band was in fact this row;
	 * the real legacy floor is 2,621.
	 */
	public const VECTOR_LENGTH_FLOOR = 3500;

	/**
	 * The one place an embedding becomes text. Anything writing face_vector goes through here, so
	 * the encoding cannot drift between the detection path and the compaction command.
	 *
	 * @param list<float> $embedding
	 */
	public static function encodeVector(array $embedding): string {
		return json_encode(array_map(
			static fn (float $f): float => round($f, self::VECTOR_PRECISION),
			$embedding,
		));
	}

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
		// recognize_face_detections has no unique constraint on (user_id, file_id), so nothing at
		// the DB level would stop a re-run from doubling a user's rows. One indexed query
		// (recognize_facedet_file) rather than one per user.
		//
		// Only the *inserts* are skipped for a user who already has rows - they still get a
		// processed marker below. Skipping both would leave a user with rows but no marker, which
		// the caller reads as still-pending, so the file would be re-enqueued and re-inferred on
		// every pass forever.
		$insertTargets = array_values(array_diff($targets, $this->usersWithRowsFor($fileId, $targets)));

		// One transaction for the whole file: Recognize's rows, the links and our own copy of each
		// assignment are written together or not at all, so a crash cannot leave them disagreeing.
		// The processed marker stays outside and after it - see the invariant below.
		$this->db->beginTransaction();
		try {
			$inserted = $this->storeFaces($fileId, $faces, $insertTargets, $imageWidth, $imageHeight);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		// Recorded even for zero-face results - recognize_face_detections never gets a row then,
		// which used to mean such files were reprocessed on every subsequent trigger run.
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
	 * Writes one file's faces: the physical face, then one detection row, link and own-table row per
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

			// The physical face, then the per-user row, then its link.
			$faceId = $this->upsertFace($fileId, $faceIndex, $x, $y, $width, $height, $face['det_score'] ?? null, $face['embedding']);

			foreach ($insertTargets as $userId) {
				// Pending faces are hidden from Memories, so nothing is written to Recognize's table here, and the
				// embedding is not encoded as text any more: it lives in facewright_faces.embedding.
				$detectionId = $this->faceStore->addPendingFace($userId, $faceId);

				$link = $this->db->getQueryBuilder();
				$link->insert('facewright_detection_faces')
					->values([
						'detection_id' => $link->createNamedParameter($detectionId, IQueryBuilder::PARAM_INT),
						'face_id' => $link->createNamedParameter($faceId, IQueryBuilder::PARAM_INT),
						'user_id' => $link->createNamedParameter($userId),
					]);
				$link->executeStatement();

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
	 * Counting Recognize's legacy rows here as well - which this did until it caused a real
	 * incident - loses the file permanently. Recognize's classifier is enqueued unconditionally by
	 * its own FsActionService (no faces.enabled check), so on a busy instance it routinely wins the
	 * race to a newly uploaded photo and writes its 128-dim rows first. We would then skip our own
	 * insert for that user but still mark them processed, leaving them with vectors that
	 * ClusteringService's dimensionality filter can never cluster and that Memories - which inner
	 * joins detections to recognize_face_clusters - can never display. Nothing revisits the file,
	 * because the marker says it is done.
	 *
	 * Restricting the test to our own rows means the worst case is a user holding both a legacy row
	 * and ours for one file. That is visible and self-correcting; the alternative is silent and
	 * permanent.
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
			'embedding' => FaceVectorCodec::encode($embedding),
			'model' => 'buffalo_l',
		]);

		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('facewright_faces')
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('face_index', $qb->createNamedParameter($faceIndex, IQueryBuilder::PARAM_INT)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	/**
	 * What told our rows from Recognize's legacy ones in Recognize's table, while that table was the source of
	 * truth: a facewright_detection_faces link, or a face_vector longer than VECTOR_LENGTH_FLOOR. It has no reader
	 * since Stage E (a row of ours is a row of facewright_user_faces) and goes in Stage F of docs/own-tables.md.
	 */
	public static function ownRowsExpr(IQueryBuilder $qb, string $alias = ''): string {
		$prefix = $alias === '' ? '' : $alias . '.';

		$linked = $qb->getConnection()->getQueryBuilder();
		$linked->select('detection_id')->from('facewright_detection_faces');

		$byLength = $qb->expr()->gt(
			$qb->func()->charLength($prefix . 'face_vector'),
			$qb->createNamedParameter(self::VECTOR_LENGTH_FLOOR, IQueryBuilder::PARAM_INT),
		);
		$byLink = $qb->expr()->in($prefix . 'id', $qb->createFunction($linked->getSQL()));

		return (string)$qb->expr()->orX($byLink, $byLength);
	}
}

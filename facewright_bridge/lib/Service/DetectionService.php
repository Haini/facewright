<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Service;

use OCA\AppAPI\PublicFunctions;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Http\Client\IResponse;
use OCP\IDBConnection;

/**
 * Core detection logic shared by the manual occ command, the automatic file-event job, and the
 * batch trigger. Calls the facewright ExApp and stores results in Recognize's own
 * oc_recognize_face_detections table - see CLAUDE.md for the column mapping this mirrors.
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
	 * char_length(face_vector) above which a row is one of ours (512-dim InsightFace) rather than
	 * Recognize's legacy 128-dim face-api.js data. Measured on the live table: legacy rows span
	 * 1,292-2,658 characters and ours 10,672-10,826, so 6000 sits in the middle of an 8,000-wide
	 * gap. Used instead of decoding the JSON because it keeps the "is this ours" test set-based -
	 * face_vector is an off-page BLOB, and touching it costs a dedicated page read per row (the
	 * lesson from clustering's 1023 MB -> 340 MB fix).
	 *
	 * The authoritative definition of "ours" is still ClusteringService::EXPECTED_DIMENSIONS; this
	 * is a cheap proxy for it, valid only while those two length bands stay disjoint.
	 */
	public const VECTOR_LENGTH_FLOOR = 6000;

	public function __construct(
		private IDBConnection $db,
		private PublicFunctions $appAPI,
		private ProcessedFileService $processedFiles,
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

		$inserted = 0;
		foreach ($faces as $face) {
			[$x1, $y1, $x2, $y2] = $face['bbox'];
			// Encoded once and reused: at ~10.7 KB per embedding, re-encoding per user would be
			// the most expensive thing in this loop.
			$vector = json_encode($face['embedding']);
			$x = $x1 / $imageWidth;
			$y = $y1 / $imageHeight;
			$width = ($x2 - $x1) / $imageWidth;
			$height = ($y2 - $y1) / $imageHeight;

			foreach ($insertTargets as $userId) {
				$qb = $this->db->getQueryBuilder();
				$qb->insert('recognize_face_detections')
					->values([
						'user_id' => $qb->createNamedParameter($userId),
						'file_id' => $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
						'x' => $qb->createNamedParameter($x),
						'y' => $qb->createNamedParameter($y),
						'width' => $qb->createNamedParameter($width),
						'height' => $qb->createNamedParameter($height),
						'face_vector' => $qb->createNamedParameter($vector),
					]);
				$qb->executeStatement();
				$inserted++;
			}
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

	/** @return array{success: bool, faces: int, inserted: int, users: int, error: ?string, permanent: bool} */
	private function failure(string $error, bool $permanent): array {
		return ['success' => false, 'faces' => 0, 'inserted' => 0, 'users' => 0, 'error' => $error, 'permanent' => $permanent];
	}

	/**
	 * Which of $userIds already hold detection rows for this file - ours or Recognize's, since
	 * either way re-inserting would duplicate what a reader sees.
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
		$qb->select('user_id')
			->from('recognize_face_detections')
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('user_id', $qb->createNamedParameter($userIds, IQueryBuilder::PARAM_STR_ARRAY)))
			->groupBy('user_id');
		return array_column($qb->executeQuery()->fetchAll(), 'user_id');
	}
}

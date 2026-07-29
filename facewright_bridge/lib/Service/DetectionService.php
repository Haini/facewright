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
	 * @return array{success: bool, inserted: int, error: ?string, permanent: bool}
	 */
	public function detect(string $userId, string $path, int $fileId): array {
		// facewright's own inference can take longer than AppAPI's default 3s request timeout.
		$response = $this->appAPI->exAppRequest(
			'facewright',
			'/detect',
			$userId,
			'POST',
			['path' => $path],
			['timeout' => 30],
		);
		if (!$response instanceof IResponse) {
			// Transport-level: ExApp down, HaRP tunnel stale, timeout. Always worth retrying.
			return ['success' => false, 'inserted' => 0, 'error' => 'Request to facewright failed: ' . ($response['error'] ?? 'unknown error'), 'permanent' => false];
		}
		$status = $response->getStatusCode();
		if ($status !== 200) {
			// 4xx is the ExApp's verdict on this specific file and will not change on a retry -
			// 400 "Could not decode image" for a truncated/unsupported file, 404 for one that has
			// gone away. 5xx is the ExApp itself failing, so that stays retryable.
			return [
				'success' => false,
				'inserted' => 0,
				'error' => 'facewright returned HTTP ' . $status . ': ' . $response->getBody(),
				'permanent' => $status >= 400 && $status < 500,
			];
		}

		$data = json_decode($response->getBody(), true);
		$faces = $data['faces'] ?? [];
		$imageWidth = $data['image_width'] ?? null;
		$imageHeight = $data['image_height'] ?? null;
		if (!$imageWidth || !$imageHeight) {
			// A 200 in the wrong shape means the two halves are out of step (ExApp deployed
			// without image_width/image_height). That is a deploy to fix, not a bad file, so it
			// stays retryable rather than burning every file scanned during the mismatch.
			return ['success' => false, 'inserted' => 0, 'error' => 'facewright response is missing image dimensions', 'permanent' => false];
		}

		$inserted = 0;
		foreach ($faces as $face) {
			[$x1, $y1, $x2, $y2] = $face['bbox'];
			$qb = $this->db->getQueryBuilder();
			$qb->insert('recognize_face_detections')
				->values([
					'user_id' => $qb->createNamedParameter($userId),
					'file_id' => $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
					'x' => $qb->createNamedParameter($x1 / $imageWidth),
					'y' => $qb->createNamedParameter($y1 / $imageHeight),
					'width' => $qb->createNamedParameter(($x2 - $x1) / $imageWidth),
					'height' => $qb->createNamedParameter(($y2 - $y1) / $imageHeight),
					'face_vector' => $qb->createNamedParameter(json_encode($face['embedding'])),
				]);
			$qb->executeStatement();
			$inserted++;
		}

		// Recorded even for zero-face results - recognize_face_detections never gets a row then,
		// which used to mean such files were reprocessed on every subsequent trigger run.
		$this->processedFiles->markProcessed($userId, $fileId, $inserted);

		return ['success' => true, 'inserted' => $inserted, 'error' => null, 'permanent' => false];
	}
}

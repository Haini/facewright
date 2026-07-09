<?php

declare(strict_types=1);

namespace OCA\RecognaizeBridge\Service;

use OCA\AppAPI\PublicFunctions;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Http\Client\IResponse;
use OCP\IDBConnection;

/**
 * Core detection logic shared by the manual occ command, the automatic file-event job, and the
 * batch trigger. Calls the recognaize ExApp and stores results in Recognize's own
 * oc_recognize_face_detections table - see CLAUDE.md for the column mapping this mirrors.
 */
class DetectionService {
	public function __construct(
		private IDBConnection $db,
		private PublicFunctions $appAPI,
	) {
	}

	public function hasExistingDetections(string $userId, int $fileId): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'c'))
			->from('recognize_face_detections')
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return (int)$qb->executeQuery()->fetchOne() > 0;
	}

	/** @return array{success: bool, inserted: int, error: ?string} */
	public function detect(string $userId, string $path, int $fileId): array {
		// recognaize's own inference can take longer than AppAPI's default 3s request timeout.
		$response = $this->appAPI->exAppRequest(
			'recognaize',
			'/detect',
			$userId,
			'POST',
			['path' => $path],
			['timeout' => 30],
		);
		if (!$response instanceof IResponse) {
			return ['success' => false, 'inserted' => 0, 'error' => 'Request to recognaize failed: ' . ($response['error'] ?? 'unknown error')];
		}
		if ($response->getStatusCode() !== 200) {
			return ['success' => false, 'inserted' => 0, 'error' => 'recognaize returned HTTP ' . $response->getStatusCode() . ': ' . $response->getBody()];
		}

		$data = json_decode($response->getBody(), true);
		$faces = $data['faces'] ?? [];
		$imageWidth = $data['image_width'] ?? null;
		$imageHeight = $data['image_height'] ?? null;
		if (!$imageWidth || !$imageHeight) {
			return ['success' => false, 'inserted' => 0, 'error' => 'recognaize response is missing image dimensions'];
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

		return ['success' => true, 'inserted' => $inserted, 'error' => null];
	}
}

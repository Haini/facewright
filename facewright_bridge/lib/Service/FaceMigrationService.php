<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;

/**
 * Backfills facewright_faces / facewright_detection_faces (Stage 1 of
 * docs/independence-implementation.md) for detections written before those tables existed.
 * Resumable and chunked by file_id - a cursor in appconfig survives across runs and is deleted
 * once the whole table is caught up.
 *
 * A "legacy" row here means char_length(face_vector) > VECTOR_LENGTH_FLOOR with no
 * facewright_detection_faces link yet - the last place this project uses the length proxy on its
 * own, on purpose: these are exactly the rows DetectionService::ownRowsExpr()'s join-OR-length
 * predicate still needs the length half for.
 *
 * Fan-out means several users can hold identical detection rows for the same physical face (same
 * stored geometry, from the same detection run) - each such group is migrated to exactly one
 * facewright_faces row, keyed by the geometry the rows share.
 */
class FaceMigrationService {
	private const APP_ID = 'facewright_bridge';
	private const CURSOR_KEY = 'migrate_faces_after_file_id';

	public function __construct(
		private IDBConnection $db,
		private IAppConfig $appConfig,
	) {
	}

	public function getCursor(): int {
		return $this->appConfig->getValueInt(self::APP_ID, self::CURSOR_KEY, 0);
	}

	public function resetCursor(): void {
		$this->appConfig->deleteKey(self::APP_ID, self::CURSOR_KEY);
	}

	/**
	 * Migrates one chunk of files (every file with at least one unlinked legacy row, ordered by
	 * file_id), starting after $afterFileId.
	 *
	 * @return array{filesProcessed: int, facesCreated: int, linksCreated: int, skipped: list<array{fileId: int, ids: list<int>}>, lastFileId: ?int}
	 */
	public function migrateChunk(int $afterFileId, int $chunkSize, bool $dryRun): array {
		$fileIds = $this->selectCandidateFileIds($afterFileId, $chunkSize);

		$facesCreated = 0;
		$linksCreated = 0;
		$skipped = [];

		foreach ($fileIds as $fileId) {
			if ($dryRun) {
				$result = $this->planFile($fileId);
			} else {
				$this->db->beginTransaction();
				try {
					$result = $this->migrateFile($fileId);
					$this->db->commit();
				} catch (\Throwable $e) {
					$this->db->rollBack();
					throw $e;
				}
			}
			$facesCreated += $result['facesCreated'];
			$linksCreated += $result['linksCreated'];
			if ($result['skippedIds'] !== []) {
				$skipped[] = ['fileId' => $fileId, 'ids' => $result['skippedIds']];
			}
		}

		$lastFileId = $fileIds === [] ? null : max($fileIds);
		if ($lastFileId !== null && !$dryRun) {
			$this->appConfig->setValueInt(self::APP_ID, self::CURSOR_KEY, $lastFileId);
		}

		return [
			'filesProcessed' => count($fileIds),
			'facesCreated' => $facesCreated,
			'linksCreated' => $linksCreated,
			'skipped' => $skipped,
			'lastFileId' => $lastFileId,
		];
	}

	/** @return list<int> ascending */
	private function selectCandidateFileIds(int $afterFileId, int $chunkSize): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('file_id')
			->from('recognize_face_detections')
			->where($qb->expr()->gt('file_id', $qb->createNamedParameter($afterFileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gt(
				$qb->func()->charLength('face_vector'),
				$qb->createNamedParameter(DetectionService::VECTOR_LENGTH_FLOOR, IQueryBuilder::PARAM_INT),
			))
			->andWhere($qb->expr()->notIn('id', $qb->createFunction($this->linkedIdsSql())))
			->orderBy('file_id', 'ASC')
			->setMaxResults($chunkSize);
		return array_map('intval', array_column($qb->executeQuery()->fetchAll(), 'file_id'));
	}

	private function linkedIdsSql(): string {
		$sub = $this->db->getQueryBuilder();
		$sub->select('detection_id')->from('facewright_detection_faces');
		return $sub->getSQL();
	}

	/** @return array{facesCreated: int, linksCreated: int, skippedIds: list<int>} counts only, no writes */
	private function planFile(int $fileId): array {
		$groups = $this->groupLegacyRows($fileId);
		$facesCreated = 0;
		$linksCreated = 0;
		$skippedIds = [];
		foreach ($groups as $group) {
			$vector = json_decode($group['rows'][0]['face_vector'], true);
			if (!is_array($vector) || count($vector) !== FaceVectorCodec::DIMENSIONS) {
				foreach ($group['rows'] as $row) {
					$skippedIds[] = (int)$row['id'];
				}
				continue;
			}
			$facesCreated++;
			$linksCreated += count($group['rows']);
		}
		return ['facesCreated' => $facesCreated, 'linksCreated' => $linksCreated, 'skippedIds' => $skippedIds];
	}

	/** @return array{facesCreated: int, linksCreated: int, skippedIds: list<int>} */
	private function migrateFile(int $fileId): array {
		$groups = $this->groupLegacyRows($fileId);

		$facesCreated = 0;
		$linksCreated = 0;
		$skippedIds = [];

		foreach ($groups as $group) {
			$geometry = $group['geometry'];
			$vector = json_decode($group['rows'][0]['face_vector'], true);
			if (!is_array($vector) || count($vector) !== FaceVectorCodec::DIMENSIONS) {
				foreach ($group['rows'] as $row) {
					$skippedIds[] = (int)$row['id'];
				}
				continue;
			}

			$this->db->setValues('facewright_faces', [
				'file_id' => $fileId,
				'face_index' => $group['faceIndex'],
			], [
				'x' => $geometry['x'],
				'y' => $geometry['y'],
				'width' => $geometry['width'],
				'height' => $geometry['height'],
				'det_score' => null,
				'embedding' => FaceVectorCodec::encode($vector),
				'model' => 'buffalo_l',
			]);
			$facesCreated++;

			$faceIdQb = $this->db->getQueryBuilder();
			$faceIdQb->select('id')
				->from('facewright_faces')
				->where($faceIdQb->expr()->eq('file_id', $faceIdQb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
				->andWhere($faceIdQb->expr()->eq('face_index', $faceIdQb->createNamedParameter($group['faceIndex'], IQueryBuilder::PARAM_INT)));
			$faceId = (int)$faceIdQb->executeQuery()->fetchOne();

			$rowIds = array_map(static fn (array $row): int => (int)$row['id'], $group['rows']);
			$existingLinksQb = $this->db->getQueryBuilder();
			$existingLinksQb->select('detection_id')
				->from('facewright_detection_faces')
				->where($existingLinksQb->expr()->in('detection_id', $existingLinksQb->createNamedParameter($rowIds, IQueryBuilder::PARAM_INT_ARRAY)));
			$alreadyLinked = array_map('intval', array_column($existingLinksQb->executeQuery()->fetchAll(), 'detection_id'));

			foreach ($group['rows'] as $row) {
				$detectionId = (int)$row['id'];
				if (in_array($detectionId, $alreadyLinked, true)) {
					// Re-run of a chunk that partially completed - links already made.
					continue;
				}
				$linkQb = $this->db->getQueryBuilder();
				$linkQb->insert('facewright_detection_faces')
					->values([
						'detection_id' => $linkQb->createNamedParameter($detectionId, IQueryBuilder::PARAM_INT),
						'face_id' => $linkQb->createNamedParameter($faceId, IQueryBuilder::PARAM_INT),
						'user_id' => $linkQb->createNamedParameter($row['user_id']),
					]);
				$linkQb->executeStatement();
				$linksCreated++;
			}
		}

		return ['facesCreated' => $facesCreated, 'linksCreated' => $linksCreated, 'skippedIds' => $skippedIds];
	}

	/**
	 * Every still-unlinked legacy row of $fileId, grouped by the geometry its stored floats
	 * share - fan-out copies were inserted from the same stored floats, so identical physical
	 * faces have identical keys across users. face_index is the rank of the group by its
	 * smallest row id, so re-running a chunk (partially migrated, cursor not yet advanced)
	 * assigns the same indices again and setValues() lands on the same facewright_faces rows.
	 *
	 * @return list<array{geometry: array{x: float, y: float, width: float, height: float}, faceIndex: int, rows: list<array{id: int, user_id: string, face_vector: string}>}>
	 */
	private function groupLegacyRows(int $fileId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'user_id', 'x', 'y', 'width', 'height', 'face_vector')
			->from('recognize_face_detections')
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gt(
				$qb->func()->charLength('face_vector'),
				$qb->createNamedParameter(DetectionService::VECTOR_LENGTH_FLOOR, IQueryBuilder::PARAM_INT),
			))
			->andWhere($qb->expr()->notIn('id', $qb->createFunction($this->linkedIdsSql())))
			->orderBy('id', 'ASC');

		$groups = [];
		$order = [];
		foreach ($qb->executeQuery()->fetchAll() as $row) {
			$key = sprintf('%.6f|%.6f|%.6f|%.6f', (float)$row['x'], (float)$row['y'], (float)$row['width'], (float)$row['height']);
			if (!isset($groups[$key])) {
				$groups[$key] = [
					'geometry' => ['x' => (float)$row['x'], 'y' => (float)$row['y'], 'width' => (float)$row['width'], 'height' => (float)$row['height']],
					'rows' => [],
				];
				$order[] = $key;
			}
			$groups[$key]['rows'][] = ['id' => (int)$row['id'], 'user_id' => (string)$row['user_id'], 'face_vector' => (string)$row['face_vector']];
		}

		$result = [];
		foreach ($order as $faceIndex => $key) {
			$result[] = [
				'geometry' => $groups[$key]['geometry'],
				'faceIndex' => $faceIndex,
				'rows' => $groups[$key]['rows'],
			];
		}
		return $result;
	}

	/**
	 * The reconciliation check step 1.6 requires before proceeding to 1.7: every row long enough
	 * to be ours must now be linked, unless it's degenerate (its JSON doesn't decode to a
	 * 512-element array - the "all-zero embedding" case CLAUDE.md documents). Recomputed from the
	 * data rather than a persisted counter, so it's correct even across multiple partial runs.
	 *
	 * @return array{total: int, linked: int, degenerate: list<int>, unresolved: list<int>} reconciled iff unresolved === []
	 */
	public function verifyCompletion(): array {
		$totalQb = $this->db->getQueryBuilder();
		$totalQb->select($totalQb->func()->count('*'))
			->from('recognize_face_detections')
			->where($totalQb->expr()->gt(
				$totalQb->func()->charLength('face_vector'),
				$totalQb->createNamedParameter(DetectionService::VECTOR_LENGTH_FLOOR, IQueryBuilder::PARAM_INT),
			));
		$total = (int)$totalQb->executeQuery()->fetchOne();

		$unlinkedQb = $this->db->getQueryBuilder();
		$unlinkedQb->select('id', 'face_vector')
			->from('recognize_face_detections')
			->where($unlinkedQb->expr()->gt(
				$unlinkedQb->func()->charLength('face_vector'),
				$unlinkedQb->createNamedParameter(DetectionService::VECTOR_LENGTH_FLOOR, IQueryBuilder::PARAM_INT),
			))
			->andWhere($unlinkedQb->expr()->notIn('id', $unlinkedQb->createFunction($this->linkedIdsSql())));

		$degenerate = [];
		$unresolved = [];
		foreach ($unlinkedQb->executeQuery()->fetchAll() as $row) {
			$vector = json_decode($row['face_vector'], true);
			if (is_array($vector) && count($vector) === FaceVectorCodec::DIMENSIONS) {
				$unresolved[] = (int)$row['id'];
			} else {
				$degenerate[] = (int)$row['id'];
			}
		}

		$linkedQb = $this->db->getQueryBuilder();
		$linkedQb->select($linkedQb->func()->count('*'))->from('facewright_detection_faces');
		$linked = (int)$linkedQb->executeQuery()->fetchOne();

		return ['total' => $total, 'linked' => $linked, 'degenerate' => $degenerate, 'unresolved' => $unresolved];
	}
}

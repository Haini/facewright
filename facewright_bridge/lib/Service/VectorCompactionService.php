<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Rewrites stored embeddings from the original json_encode() output to DetectionService's
 * VECTOR_PRECISION encoding - the one-off half of that change, for rows written before it.
 *
 * Worth stating why this is a command and not a Migration step: it rewrites ~2 GB across 195,883
 * rows, which is far too much to do inside occ upgrade's maintenance window, and it is safely
 * resumable, so there is no benefit to coupling it to the app version. New detections are already
 * written compact by DetectionService itself; this only catches up the backlog.
 *
 * Resumable by construction, in two independent ways: the id cursor means an interrupted run
 * continues where it stopped, and the length predicate means an already-compacted row is never
 * selected again even if the cursor is reset to 0. Re-running after completion is a pure-read
 * no-op.
 */
class VectorCompactionService {
	/**
	 * char_length above which a row is still in the pre-compaction encoding.
	 *
	 * This is deliberately its own constant rather than a reuse of VECTOR_LENGTH_FLOOR, even though
	 * 6000 was that constant's value before this change. They answer different questions - "is this
	 * row ours" versus "does this row still need rewriting" - and tying them together would mean a
	 * future precision change silently altered which rows get selected here.
	 */
	public const LEGACY_ENCODING_FLOOR = 6000;

	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * One chunk, in id order starting after $afterId.
	 *
	 * @return array{examined: int, compacted: int, skipped: int, before: int, after: int, lastId: ?int}
	 */
	public function compactChunk(int $afterId, int $chunkSize, bool $dryRun): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'face_vector')
			->from('recognize_face_detections')
			->where($qb->expr()->gt(
				$qb->func()->charLength('face_vector'),
				$qb->createNamedParameter(self::LEGACY_ENCODING_FLOOR, IQueryBuilder::PARAM_INT),
			))
			->andWhere($qb->expr()->gt('id', $qb->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC')
			->setMaxResults($chunkSize);

		$examined = 0;
		$compacted = 0;
		$skipped = 0;
		$before = 0;
		$after = 0;
		$lastId = null;

		$result = $qb->executeQuery();
		$pending = [];
		while ($row = $result->fetch()) {
			$examined++;
			$lastId = (int)$row['id'];

			$vector = json_decode($row['face_vector'], true);
			// Anything that is not one of our own 512-dim vectors is left exactly as it is. The
			// length predicate should already have excluded legacy rows, but this is the
			// authoritative test and it is cheap here, where the JSON is decoded anyway.
			if (!is_array($vector) || count($vector) !== FaceVectorCodec::DIMENSIONS) {
				$skipped++;
				continue;
			}

			$encoded = DetectionService::encodeVector($vector);

			// Two refusals rather than one, because a bad write here is invisible until something
			// downstream misreads it. The first catches an encoding that somehow grew; the second
			// catches one that shrank so far it would drop under the ownership floor and make the
			// row read as Recognize's at all 14 call sites that use it.
			if (strlen($encoded) >= strlen($row['face_vector'])
				|| strlen($encoded) <= DetectionService::VECTOR_LENGTH_FLOOR) {
				$skipped++;
				continue;
			}

			$before += strlen($row['face_vector']);
			$after += strlen($encoded);
			$compacted++;
			$pending[(int)$row['id']] = $encoded;
		}
		$result->closeCursor();

		// Collected first, written after the cursor is closed: updating rows the open result set is
		// still walking is the kind of thing that works until the driver buffers differently.
		if (!$dryRun) {
			foreach ($pending as $id => $encoded) {
				$qb = $this->db->getQueryBuilder();
				$qb->update('recognize_face_detections')
					->set('face_vector', $qb->createNamedParameter($encoded))
					->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
				$qb->executeStatement();
			}
		}

		return [
			'examined' => $examined,
			'compacted' => $compacted,
			'skipped' => $skipped,
			'before' => $before,
			'after' => $after,
			'lastId' => $lastId,
		];
	}

	/** Rows still awaiting compaction. */
	public function countRemaining(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from('recognize_face_detections')
			->where($qb->expr()->gt(
				$qb->func()->charLength('face_vector'),
				$qb->createNamedParameter(self::LEGACY_ENCODING_FLOOR, IQueryBuilder::PARAM_INT),
			));
		return (int)$qb->executeQuery()->fetchOne();
	}
}

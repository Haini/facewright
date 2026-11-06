<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * The only writer of facewright_user_faces and facewright_clusters, the tables that will replace
 * Recognize's copies (docs/own-tables.md, Stage C).
 *
 * Until the switch, Recognize's tables stay the source of truth and this class keeps ours an exact
 * mirror. Every writer that changes recognize_face_detections or recognize_face_clusters tells this
 * class which ids it touched, after its own statement and inside the same transaction, and the rows
 * here are then rewritten *from the Recognize rows*. They are not computed from what the caller meant
 * to do: a guard that skipped a row, or a concurrent edit that won, then decides for both tables at
 * once, and no caller has to reason about its own WHERE clause twice.
 *
 * It opens no transaction of its own. Callers that need the two writes to be atomic wrap them.
 *
 * Mirroring only updates rows that exist. A face that was never copied (the backfill has not reached
 * it, or it is one of Recognize's own legacy rows) stays absent; the backfill and addFaces() are the
 * only ways in.
 */
class FaceStore {
	private const CHUNK = 1000;

	public function __construct(
		private IDBConnection $db,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Adds faces that were just written to Recognize's table, in state PENDING.
	 *
	 * @param array<int, int> $faceIdsByRowId detection id => facewright_faces.id
	 */
	public function addFaces(string $userId, array $faceIdsByRowId): void {
		foreach ($faceIdsByRowId as $rowId => $faceId) {
			$this->db->setValues('facewright_user_faces', ['id' => (int)$rowId], [
				'user_id' => $userId,
				'face_id' => (int)$faceId,
				'cluster_id' => null,
				'state' => FaceState::PENDING,
			]);
		}
	}

	/**
	 * Rewrites the state of these faces from their Recognize rows. A row that no longer exists there
	 * is removed here, so this also serves deletions.
	 *
	 * @param list<int> $rowIds
	 */
	public function mirrorFaces(array $rowIds): void {
		foreach (array_chunk($this->ids($rowIds), self::CHUNK) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id', 'cluster_id')
				->from('recognize_face_detections')
				->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$result = $qb->executeQuery();

			$groups = []; // "state:cluster" => [state, cluster, list<id>]
			$present = [];
			while ($row = $result->fetch()) {
				$id = (int)$row['id'];
				$present[$id] = true;
				try {
					$mapped = FaceState::fromSentinel($row['cluster_id'] !== null ? (int)$row['cluster_id'] : null);
				} catch (\InvalidArgumentException $e) {
					// Not a value anything here writes. Treat it as not looked at, loudly, rather than
					// let one odd row fail the caller's write.
					$this->logger->warning("facewright: face {$id} has cluster_id {$row['cluster_id']}, mirrored as pending", ['app' => 'facewright_bridge']);
					$mapped = ['state' => FaceState::PENDING, 'cluster_id' => null];
				}
				$key = $mapped['state'] . ':' . ($mapped['cluster_id'] ?? '');
				$groups[$key] ??= [$mapped['state'], $mapped['cluster_id'], []];
				$groups[$key][2][] = $id;
			}
			$result->closeCursor();

			foreach ($groups as [$state, $clusterId, $ids]) {
				$update = $this->db->getQueryBuilder();
				$update->update('facewright_user_faces')
					->set('state', $update->createNamedParameter($state, IQueryBuilder::PARAM_INT))
					->set('cluster_id', $update->createNamedParameter($clusterId, $clusterId === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT))
					->where($update->expr()->in('id', $update->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
				$update->executeStatement();
			}

			$gone = array_values(array_diff($chunk, array_keys($present)));
			if ($gone !== []) {
				$this->deleteFaces($gone);
			}
		}
	}

	/**
	 * Removes faces whose Recognize rows were deleted.
	 *
	 * @param list<int> $rowIds
	 */
	public function deleteFaces(array $rowIds): void {
		foreach (array_chunk($this->ids($rowIds), self::CHUNK) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('facewright_user_faces')
				->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$qb->executeStatement();
		}
	}

	/**
	 * Rewrites these people from their Recognize rows; one that no longer exists there is removed.
	 *
	 * @param list<int> $clusterIds
	 */
	public function mirrorClusters(array $clusterIds): void {
		foreach (array_chunk($this->ids($clusterIds), self::CHUNK) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id', 'user_id', 'title')
				->from('recognize_face_clusters')
				->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$result = $qb->executeQuery();

			$present = [];
			while ($row = $result->fetch()) {
				$present[] = (int)$row['id'];
				$this->db->setValues('facewright_clusters', ['id' => (int)$row['id']], [
					'user_id' => (string)$row['user_id'],
					'title' => (string)$row['title'],
				]);
			}
			$result->closeCursor();

			$gone = array_values(array_diff($chunk, $present));
			if ($gone !== []) {
				$delete = $this->db->getQueryBuilder();
				$delete->delete('facewright_clusters')
					->where($delete->expr()->in('id', $delete->createNamedParameter($gone, IQueryBuilder::PARAM_INT_ARRAY)));
				$delete->executeStatement();
			}
		}
	}

	/**
	 * Mirrors ClusteringService::resetUser(): every face of the user goes back to PENDING except the
	 * ones a human ignored, and their people are deleted. Written as statements, not read back from
	 * Recognize's table, because a user can have a hundred thousand faces.
	 */
	public function resetUser(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('facewright_user_faces')
			->set('state', $qb->createNamedParameter(FaceState::PENDING, IQueryBuilder::PARAM_INT))
			->set('cluster_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->neq('state', $qb->createNamedParameter(FaceState::IGNORED, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();

		$qb = $this->db->getQueryBuilder();
		$qb->delete('facewright_clusters')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}

	/**
	 * @param list<int|string> $ids
	 * @return list<int>
	 */
	private function ids(array $ids): array {
		return array_values(array_unique(array_map('intval', $ids)));
	}
}

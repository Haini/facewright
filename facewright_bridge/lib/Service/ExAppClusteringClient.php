<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Service;

use OCA\AppAPI\PublicFunctions;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Http\Client\IResponse;
use OCP\IDBConnection;

/**
 * The bridge's side of the ExApp's /cluster (docs/exapp-clustering.md). The ExApp keeps a cache of embeddings keyed by
 * facewright_faces.id and validated by the CRC32 of the stored blob: a face id's embedding can change, because
 * DetectionService::upsertFace() updates in place on a forced re-detection. The checksum is facewright_faces.embedding_crc,
 * which every writer of the embedding sets; where it is NULL (unknown) it is computed here.
 *
 * Called by occ facewright:cluster-compare (which writes nothing) and by the exapp engine,
 * ClusteringService::clusterUserExApp(), which writes the answer through persistAssignments(). Errors are thrown, never
 * retried silently, apart from the one documented retry after a 409.
 */
class ExAppClusteringClient {
	private const APP_ID = 'facewright';
	// IN() lists for checksums and blobs: the same size as ClusteringService's vector fetch.
	private const SQL_BATCH = 500;
	// Faces per /cluster/cache/put (about 2.7 KB each as base64). The ExApp accepts up to 2,000; C1 measured 2,000 at
	// about 150 ms through AppAPI on dev.
	private const PUT_BATCH = 1000;
	// Pairs per /cluster/cache/check (about 25 bytes each): 50,000 pairs were 1.2 MB in C1.
	private const CHECK_BATCH = 20000;
	private const TIMEOUT = 120;
	// /cluster under the exapp engine attempts every pending face in one request. Sized in S3 from C5: user2 (a pool of
	// about 55,000) took 2.26 s of compute per 300 attempts, about 7.5 ms each; a full reopen of user2 (about 31,000
	// attempts) is then about 235 s, and twice that is under this. HaRP's own timeout is 1,800 s.
	private const CLUSTER_TIMEOUT = 600;
	// forget runs inside a file delete request: it must not hold one up for long when the ExApp hangs. A failure costs
	// nothing lasting (the ExApp prunes entries no pass has seen for 30 days).
	private const FORGET_TIMEOUT = 10;
	// Ids per /cluster/cache/forget (a few bytes each).
	private const FORGET_BATCH = 50000;

	/** @var array{requests: int, bytesSent: int, ms: int} totals since the last takeStats() */
	private array $stats = ['requests' => 0, 'bytesSent' => 0, 'ms' => 0];

	public function __construct(
		private IDBConnection $db,
		private PublicFunctions $appAPI,
	) {
	}

	/**
	 * CRC32 of each face's embedding blob, the same value as Python's zlib.crc32 and PHP's crc32() (checked in C1).
	 * The stored embedding_crc when set; when it is NULL (unknown: a row from before the column, or a writer that does
	 * not set it), MariaDB and MySQL compute it in the SELECT, and elsewhere the blob is read and hashed here.
	 *
	 * @param list<int> $faceIds
	 * @return array<int, int> face id => crc; ids with no row are absent
	 */
	public function checksums(array $faceIds): array {
		$inDatabase = in_array($this->db->getDatabaseProvider(true), [IDBConnection::PLATFORM_MYSQL, IDBConnection::PLATFORM_MARIADB], true);
		$out = [];
		foreach (array_chunk(array_values(array_unique($faceIds)), self::SQL_BATCH) as $batch) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id')
				->from('facewright_faces')
				->where($qb->expr()->in('id', $qb->createNamedParameter($batch, IQueryBuilder::PARAM_INT_ARRAY)));
			if ($inDatabase) {
				$qb->selectAlias($qb->createFunction(
					'COALESCE(' . $qb->getColumnName('embedding_crc') . ', CRC32(' . $qb->getColumnName('embedding') . '))'
				), 'crc');
			} else {
				// Read the blob only where the checksum is unknown.
				$qb->addSelect('embedding_crc')
					->selectAlias($qb->createFunction('CASE WHEN ' . $qb->getColumnName('embedding_crc') . ' IS NULL THEN '
						. $qb->getColumnName('embedding') . ' END'), 'embedding');
			}
			$result = $qb->executeQuery();
			while ($row = $result->fetch()) {
				$out[(int)$row['id']] = match (true) {
					$inDatabase => (int)$row['crc'],
					$row['embedding_crc'] !== null => (int)$row['embedding_crc'],
					default => crc32(self::blob($row['embedding'])),
				};
			}
			$result->closeCursor();
		}
		return $out;
	}

	/**
	 * Makes the ExApp's cache hold these faces with their current embeddings: asks which it lacks or holds stale, then
	 * uploads those. Never holds more than one batch of blobs at once.
	 *
	 * @param list<int> $faceIds
	 * @param array<int, int>|null $checksums from checksums(), if the caller has them already
	 * @return array{checked: int, uploaded: int}
	 */
	public function syncCache(string $userId, array $faceIds, ?array $checksums = null): array {
		$checksums ??= $this->checksums($faceIds);
		$missing = [];
		foreach (array_chunk($checksums, self::CHECK_BATCH, true) as $batch) {
			$pairs = [];
			foreach ($batch as $faceId => $crc) {
				$pairs[] = [$faceId, $crc];
			}
			$answer = $this->post($userId, '/cluster/cache/check', ['faces' => $pairs]);
			array_push($missing, ...array_map('intval', $answer['missing'] ?? []));
		}
		return ['checked' => count($checksums), 'uploaded' => $this->upload($userId, $missing)];
	}

	/**
	 * One /cluster call. $pool is in the order PHP iterates its pool (that order decides ties), each entry
	 * [rowId, faceId, crc, label] with label null or a person id > 0. On a 409 the faces the ExApp names are synced and
	 * the call is repeated once; a second 409 is an error.
	 *
	 * @param list<array{0: int, 1: int, 2: int, 3: ?int}> $pool
	 * @param list<int> $attempt row ids, in attempt order
	 * @return array{decisions: list<array{id: int, action: string, label: ?int, matches: int}>, labels: list<array{0: int, 1: int}>, compute_ms: int, total_ms: int}
	 */
	public function cluster(string $userId, array $pool, array $attempt, float $maxDistance, int $minFaces): array {
		$body = ['max_distance' => $maxDistance, 'min_faces' => $minFaces, 'pool' => $pool, 'attempt' => $attempt];
		$answer = $this->post($userId, '/cluster', $body, true, self::CLUSTER_TIMEOUT);
		if (isset($answer['missing'])) {
			$this->syncCache($userId, array_map('intval', $answer['missing']));
			$answer = $this->post($userId, '/cluster', $body, true, self::CLUSTER_TIMEOUT);
			if (isset($answer['missing'])) {
				throw new ExAppClusteringException('/cluster still lacks ' . count($answer['missing']) . ' face(s) after a sync');
			}
		}
		return $answer;
	}

	/**
	 * Tells the ExApp to drop these faces' embeddings: called after the faces were deleted and committed (S4). Not tied
	 * to a user. Throws like every call here; callers that delete treat it as best-effort.
	 *
	 * @param list<int> $faceIds
	 * @return int entries the ExApp removed
	 */
	public function forget(array $faceIds): int {
		$removed = 0;
		foreach (array_chunk(array_values(array_unique($faceIds)), self::FORGET_BATCH) as $batch) {
			$removed += (int)($this->post(null, '/cluster/cache/forget', ['faces' => $batch], false, self::FORGET_TIMEOUT)['removed'] ?? 0);
		}
		return $removed;
	}

	/** Empties the ExApp's whole cache (occ facewright:reset). */
	public function clear(): void {
		$this->post(null, '/cluster/cache/clear', []);
	}

	/** @return array{requests: int, bytesSent: int, ms: int} request totals since the last call, then resets them */
	public function takeStats(): array {
		$stats = $this->stats;
		$this->stats = ['requests' => 0, 'bytesSent' => 0, 'ms' => 0];
		return $stats;
	}

	/**
	 * Reads the blobs and puts them, PUT_BATCH at a time. The checksum sent is the one of the blob read here, so a face
	 * re-detected since checksums() is uploaded consistently (the pool's older checksum then makes /cluster answer 409).
	 *
	 * @param list<int> $faceIds
	 */
	private function upload(string $userId, array $faceIds): int {
		$uploaded = 0;
		$batch = [];
		foreach (array_chunk($faceIds, self::SQL_BATCH) as $ids) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id', 'embedding')
				->from('facewright_faces')
				->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
			$result = $qb->executeQuery();
			while ($row = $result->fetch()) {
				$blob = self::blob($row['embedding']);
				$batch[] = ['id' => (int)$row['id'], 'crc' => crc32($blob), 'embedding' => base64_encode($blob)];
				if (count($batch) === self::PUT_BATCH) {
					$uploaded += (int)($this->post($userId, '/cluster/cache/put', ['faces' => $batch])['stored'] ?? 0);
					$batch = [];
				}
			}
			$result->closeCursor();
		}
		if ($batch !== []) {
			$uploaded += (int)($this->post($userId, '/cluster/cache/put', ['faces' => $batch])['stored'] ?? 0);
		}
		return $uploaded;
	}

	/**
	 * @param bool $allowMissing return a 409's body (it names the faces the ExApp lacks) instead of throwing
	 * @return array<string, mixed>
	 */
	private function post(?string $userId, string $route, array $body, bool $allowMissing = false, int $timeout = self::TIMEOUT): array {
		$started = microtime(true);
		// The user is the one being clustered (null for forget and clear); the ExApp does not use it. AppAPI sends $body
		// as JSON.
		$response = $this->appAPI->exAppRequest(self::APP_ID, $route, $userId, 'POST', $body, ['timeout' => $timeout]);
		$this->stats['requests']++;
		$this->stats['bytesSent'] += strlen(json_encode($body));
		$this->stats['ms'] += (int)round((microtime(true) - $started) * 1000);

		if (!$response instanceof IResponse) {
			throw new ExAppClusteringException("{$route}: request failed: " . ($response['error'] ?? 'unknown error'));
		}
		$status = $response->getStatusCode();
		$data = json_decode((string)$response->getBody(), true);
		if ($status === 409 && $allowMissing && is_array($data) && isset($data['missing'])) {
			return $data;
		}
		if ($status !== 200 || !is_array($data)) {
			throw new ExAppClusteringException("{$route}: HTTP {$status}: " . substr((string)$response->getBody(), 0, 500));
		}
		return $data;
	}

	/** @param string|resource $value */
	private static function blob($value): string {
		return is_resource($value) ? stream_get_contents($value) : (string)$value;
	}
}

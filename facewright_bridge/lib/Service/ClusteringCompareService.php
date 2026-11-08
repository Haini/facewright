<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Service;

use OCP\IDBConnection;

/**
 * occ facewright:cluster-compare (docs/exapp-clustering.md, C4): runs the PHP matching and the ExApp's /cluster on the
 * same pool, chunk by chunk as ClusterScanJob would, and reports where they differ. Writes nothing to Nextcloud's
 * database; fills the ExApp's embedding cache.
 *
 * Per chunk, both engines start from PHP's state after the previous chunk, so one difference never compounds. People
 * PHP created in a chunk carry into the next under positive stand-in ids above every real person id: in production
 * they would exist by then, and /cluster refuses labels <= 0 and numbers its own new people from -1 on every call.
 *
 * Memory: PHP matches in place on the pool (no copy of the vectors); the ExApp's request is built from the labels
 * before the chunk. A 55k-face pool is about 670 MB in PHP, held once.
 */
class ClusteringCompareService {
	// A candidate this close to the threshold can fall either side through summation order alone (PHP's loop vs BLAS).
	private const BOUNDARY_EPSILON = 1e-9;
	// Two candidates this close are a tie: their order is decided by pool order, which both engines follow, but a tie
	// between two different people is still reported so it can be told apart from a real difference.
	private const TIE_EPSILON = 1e-12;
	// Rows classified per chunk. Each classification is a pass over the pool (one row's distances); beyond the cap a
	// difference stays unexplained, which fails the run: the cap can only make it stricter.
	private const CLASSIFY_CAP = 50;
	private const STAND_IN_OFFSET = 1000000;

	public function __construct(
		private ClusteringService $clustering,
		private ExAppClusteringClient $client,
		private IDBConnection $db,
	) {
	}

	/**
	 * @param callable(array): void $onChunk receives each chunk's report as it finishes
	 * @return array{attemptable: int, chunks: int, sync: array, totals: array, differences: list<array>, peakMemory: int}
	 */
	public function compare(string $userId, bool $reopen, int $maxChunks, int $chunkSize, float $maxDistance, int $minFaces, int $show, callable $onChunk): array {
		$pool = $this->clustering->loadPoolForComparison($userId, $reopen);
		$detections = $pool['detections'];
		$faceIds = $pool['faceIds'];
		$pendingIds = $pool['pendingIds'];
		unset($pool);

		$totals = [
			'php' => ['new' => 0, 'join' => 0, 'skip' => 0, 'ms' => 0],
			'exapp' => ['new' => 0, 'join' => 0, 'skip' => 0, 'ms' => 0, 'compute_ms' => 0, 'requests' => 0, 'bytes' => 0],
			'differences' => ['boundary' => 0, 'tie' => 0, 'unexplained' => 0],
		];
		$result = ['attemptable' => count($pendingIds), 'poolSize' => count($detections), 'chunks' => 0, 'sync' => [], 'totals' => &$totals, 'differences' => []];
		if ($pendingIds === []) {
			$result['peakMemory'] = memory_get_peak_usage(true);
			return $result;
		}

		$started = microtime(true);
		$checksums = $this->client->checksums(array_values(array_unique($faceIds)));
		$this->client->takeStats();
		$sync = $this->client->syncCache($userId, array_keys($checksums), $checksums);
		$stats = $this->client->takeStats();
		$result['sync'] = $sync + ['requests' => $stats['requests'], 'bytes' => $stats['bytesSent'], 'ms' => (int)round((microtime(true) - $started) * 1000)];

		$nextStandIn = $this->maxPersonId() + self::STAND_IN_OFFSET;
		$afterId = 0;
		while ($maxChunks === 0 || $result['chunks'] < $maxChunks) {
			// The ids a chunk would attempt: pending on entry, above the cursor, still unlabelled (in production an
			// earlier chunk's written rows are no longer pending when the pool is reloaded).
			$attempt = [];
			foreach ($pendingIds as $id) {
				if ($id > $afterId && $detections[$id]['cluster_id'] === null) {
					$attempt[] = $id;
					if (count($attempt) === $chunkSize) {
						break;
					}
				}
			}
			if ($attempt === []) {
				break;
			}
			$afterId = max($attempt);
			$result['chunks']++;

			// The state both engines start from, in pool order.
			$before = [];
			$rows = [];
			foreach ($detections as $id => $detection) {
				$before[$id] = $detection['cluster_id'];
				$rows[] = [$id, $faceIds[$id], $checksums[$faceIds[$id]], $detection['cluster_id']];
			}

			$this->client->takeStats();
			$t = microtime(true);
			$exapp = $this->client->cluster($userId, $rows, $attempt, $maxDistance, $minFaces);
			$exappMs = (int)round((microtime(true) - $t) * 1000);
			$exappStats = $this->client->takeStats();
			unset($rows);

			$next = 0;
			$t = microtime(true);
			$php = $this->clustering->assignInMemory($detections, $attempt, $maxDistance, $minFaces, function () use (&$next): int {
				return --$next;
			});
			$phpMs = (int)round((microtime(true) - $t) * 1000);

			$chunk = $this->compareChunk($detections, $before, $attempt, $php, $exapp, $maxDistance, $show - count($result['differences']));
			array_push($result['differences'], ...$chunk['shown']);

			$counts = array_count_values(array_column($exapp['decisions'], 'action'));
			$report = [
				'chunk' => $result['chunks'],
				'attempted' => count($attempt),
				'php' => ['new' => $php['newClusters'], 'join' => $php['joined'], 'skip' => $php['skipped'], 'ms' => $phpMs],
				'exapp' => ['new' => $counts['new'] ?? 0, 'join' => $counts['join'] ?? 0, 'skip' => $counts['skip'] ?? 0, 'ms' => $exappMs, 'compute_ms' => (int)$exapp['compute_ms'], 'requests' => $exappStats['requests'], 'bytes' => $exappStats['bytesSent']],
				'differences' => $chunk['counts'],
			];
			foreach (['php', 'exapp'] as $engine) {
				foreach ($report[$engine] as $key => $value) {
					$totals[$engine][$key] += $value;
				}
			}
			foreach ($chunk['counts'] as $class => $n) {
				$totals['differences'][$class] += $n;
			}
			$onChunk($report);

			// Carry PHP's state forward: its new people become positive stand-ins.
			$standIns = [];
			foreach ($php['changedIds'] as $id) {
				$label = $detections[$id]['cluster_id'];
				if ($label !== null && $label < 0) {
					$standIns[$label] ??= $nextStandIn++;
					$detections[$id]['cluster_id'] = $standIns[$label];
				}
			}
		}

		$result['peakMemory'] = memory_get_peak_usage(true);
		return $result;
	}

	/**
	 * Compares one chunk's outcome: every row either engine changed must end with the same label, where existing people
	 * (> 0) must be equal and new people (< 0) must map one to one. The decision counts must agree too.
	 *
	 * @return array{counts: array{boundary: int, tie: int, unexplained: int}, shown: list<array>}
	 */
	private function compareChunk(array $detections, array $before, array $attempt, array $php, array $exapp, float $maxDistance, int $show): array {
		$exappLabels = [];
		foreach ($exapp['labels'] as [$id, $label]) {
			$exappLabels[(int)$id] = (int)$label;
		}
		$rows = array_unique(array_merge($php['changedIds'], array_keys($exappLabels)));
		sort($rows);

		$phpToExapp = [];
		$exappToPhp = [];
		$differing = [];
		foreach ($rows as $id) {
			$p = $detections[$id]['cluster_id'];
			$e = $exappLabels[$id] ?? $before[$id];
			$same = match (true) {
				$p === null || $e === null => $p === $e,
				$p > 0 || $e > 0 => $p === $e,
				default => $this->mapsOneToOne($p, $e, $phpToExapp, $exappToPhp),
			};
			if (!$same) {
				$differing[] = ['id' => $id, 'php' => $p, 'exapp' => $e];
			}
		}

		$counts = ['boundary' => 0, 'tie' => 0, 'unexplained' => 0];
		$shown = [];
		$classified = 0;
		foreach ($differing as $diff) {
			$class = 'unexplained';
			if ($classified < self::CLASSIFY_CAP) {
				$classified++;
				$class = $this->classify($diff, $detections, $before, $attempt, $exapp, $maxDistance);
			}
			$counts[$class]++;
			if (count($shown) < $show) {
				$shown[] = $diff + ['class' => $class];
			}
		}

		$exappCounts = array_count_values(array_column($exapp['decisions'], 'action'));
		$decisionsAgree = $php['newClusters'] === ($exappCounts['new'] ?? 0)
			&& $php['joined'] === ($exappCounts['join'] ?? 0)
			&& $php['skipped'] === ($exappCounts['skip'] ?? 0);
		if (!$decisionsAgree && $counts['boundary'] + $counts['tie'] === 0) {
			// Counts that differ while no row's label does, and nothing near the threshold explains it.
			$counts['unexplained']++;
			if (count($shown) < $show) {
				$shown[] = ['id' => null, 'php' => "{$php['newClusters']} new / {$php['joined']} join / {$php['skipped']} skip",
					'exapp' => ($exappCounts['new'] ?? 0) . ' new / ' . ($exappCounts['join'] ?? 0) . ' join / ' . ($exappCounts['skip'] ?? 0) . ' skip',
					'class' => 'unexplained'];
			}
		}
		return ['counts' => $counts, 'shown' => $shown];
	}

	private function mapsOneToOne(int $p, int $e, array &$phpToExapp, array &$exappToPhp): bool {
		if (!isset($phpToExapp[$p]) && !isset($exappToPhp[$e])) {
			$phpToExapp[$p] = $e;
			$exappToPhp[$e] = $p;
			return true;
		}
		return ($phpToExapp[$p] ?? null) === $e && ($exappToPhp[$e] ?? null) === $p;
	}

	/**
	 * boundary: a candidate at distance within BOUNDARY_EPSILON of the threshold; tie: two candidates with different
	 * labels within TIE_EPSILON of each other. Looked for at the row itself, then at the attempted rows of the chunk
	 * whose decision gave it a label in either engine (a difference at a seed spreads to the faces it pulled in).
	 */
	private function classify(array $diff, array $detections, array $before, array $attempt, array $exapp, float $maxDistance): string {
		$seeds = [$diff['id']];
		foreach ($exapp['decisions'] as $decision) {
			if ($decision['label'] !== null && in_array($decision['label'], [$diff['exapp']], true)) {
				$seeds[] = (int)$decision['id'];
			}
		}
		foreach ($attempt as $id) {
			if ($detections[$id]['cluster_id'] !== null && $detections[$id]['cluster_id'] === $diff['php']) {
				$seeds[] = $id;
			}
		}
		foreach (array_slice(array_values(array_unique($seeds)), 0, 5) as $seed) {
			$class = $this->nearThreshold($seed, $detections, $before, $maxDistance);
			if ($class !== null) {
				return $class;
			}
		}
		return 'unexplained';
	}

	private function nearThreshold(int $seed, array $detections, array $before, float $maxDistance): ?string {
		$mine = $detections[$seed]['vector'];
		$candidates = [];
		foreach ($detections as $id => $other) {
			if ($id === $seed) {
				continue;
			}
			// The same loop as ClusteringService::cosineDistance(), so the same rounding.
			$dot = 0.0;
			foreach ($mine as $i => $v) {
				$dot += $v * $other['vector'][$i];
			}
			$distance = 1.0 - $dot;
			if (abs($distance - $maxDistance) < self::BOUNDARY_EPSILON) {
				return 'boundary';
			}
			if ($distance <= $maxDistance && $before[$id] !== null) {
				$candidates[] = [$distance, $before[$id]];
			}
		}
		usort($candidates, fn ($a, $b) => $a[0] <=> $b[0]);
		for ($i = 1; $i < count($candidates); $i++) {
			if ($candidates[$i][0] - $candidates[$i - 1][0] < self::TIE_EPSILON && $candidates[$i][1] !== $candidates[$i - 1][1]) {
				return 'tie';
			}
		}
		return null;
	}

	private function maxPersonId(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->max('id'))->from('facewright_clusters');
		return (int)$qb->executeQuery()->fetchOne();
	}
}

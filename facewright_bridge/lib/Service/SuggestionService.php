<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;

/**
 * "Possibly the same person": for one of a user's people, the others whose faces look alike, for
 * the People page. Clustering splits one real person across several clusters when the photos
 * differ enough (age, glasses, lighting); this finds those splits so the user can merge them.
 * Per person only: a library-wide list costs named x candidates comparisons, which reaches
 * seconds once hundreds of people are named, and is less actionable than "who else is this?".
 *
 * A person is represented by a centroid - the normalized mean of up to REPRESENTATIVES of their
 * best-scored faces - and two people are compared by cosine distance between centroids. Centroids
 * are cached in facewright_cluster_centroids and recomputed when a person's size changed since.
 * That is the only place embeddings are read, and only from facewright_faces, never face_vector.
 *
 * Candidates are the people listClusters() shows at the user's own minimum cluster size (named
 * people always), so the suggestions never point at a person the list would hide.
 */
class SuggestionService {
	public const REPRESENTATIVES = 10;
	public const DEFAULT_MAX_DISTANCE = 0.6;

	private const KEY_MAX_DISTANCE = 'suggest_max_distance';
	private const APP_ID = 'facewright_bridge';

	/** @var array<string, int|float> what the last forPerson() call did, for occ's report */
	private array $lastRun = [];

	public function __construct(
		private IDBConnection $db,
		private PeopleService $people,
		private UserSettingsService $userSettings,
		private IAppConfig $appConfig,
		private ITimeFactory $timeFactory,
	) {
	}

	public function maxDistance(): float {
		return $this->appConfig->getValueFloat(self::APP_ID, self::KEY_MAX_DISTANCE, self::DEFAULT_MAX_DISTANCE);
	}

	/**
	 * Diagnostics of the last forPerson() call: clusters, candidates, centroidsCached,
	 * centroidsComputed, comparisons, centroidSeconds, distanceSeconds. A tool for measuring cost, not an API.
	 *
	 * @return array<string, int|float>
	 */
	public function lastRun(): array {
		return $this->lastRun;
	}

	/**
	 * The people most like $clusterId, closest first. With $store false nothing is written, so a
	 * run can measure the cold cost against a database it must not change.
	 *
	 * @return list<array{id: int, title: string, count: int, cover: ?int, distance: float}>
	 * @throws \InvalidArgumentException if $clusterId isn't $userId's
	 */
	public function forPerson(string $userId, int $clusterId, int $limit = 10, bool $store = true): array {
		$subject = $this->people->getCluster($userId, $clusterId);

		$all = $this->people->listClusters($userId, null, 0, $this->userSettings->getClusterMinFaces($userId));
		$candidates = array_values(array_filter($all, fn (array $c): bool => $c['id'] !== $clusterId));
		// The subject may itself be below the size filter; it is still what is being compared.
		$self = ['id' => $clusterId, 'title' => $subject['title'], 'count' => $this->people->countFaces($userId, $clusterId)];

		$t0 = hrtime(true);
		[$centroids, $stats] = $this->centroids($userId, array_merge([$self], $candidates), $store);
		$t1 = hrtime(true);

		$found = [];
		$comparisons = 0;
		if (isset($centroids[$clusterId])) {
			$dismissed = $this->dismissedPartners($userId, $clusterId);
			$max = $this->maxDistance();
			foreach ($candidates as $candidate) {
				if (!isset($centroids[$candidate['id']]) || isset($dismissed[$candidate['id']])) {
					continue;
				}
				$comparisons++;
				$distance = 1.0 - $this->dot($centroids[$clusterId], $centroids[$candidate['id']]);
				if ($distance <= $max) {
					$found[] = $candidate + ['distance' => $distance];
				}
			}
			usort($found, fn (array $a, array $b): int => $a['distance'] <=> $b['distance']);
			$found = array_slice($found, 0, $limit);
		}
		$t2 = hrtime(true);

		$covers = $this->people->coversFor($userId, array_column($found, 'id'));
		$this->lastRun = $stats + [
			'clusters' => count($all),
			'candidates' => count($candidates),
			'comparisons' => $comparisons,
			'centroidSeconds' => ($t1 - $t0) / 1e9,
			'distanceSeconds' => ($t2 - $t1) / 1e9,
		];

		return array_map(fn (array $c): array => [
			'id' => $c['id'],
			'title' => $c['title'],
			'count' => $c['count'],
			'cover' => $covers[$c['id']] ?? null,
			'distance' => $c['distance'],
		], $found);
	}

	/**
	 * Remembers that two people are not the same, so neither is suggested for the other again.
	 *
	 * @throws \InvalidArgumentException if either isn't $userId's, or they are the same
	 */
	public function dismiss(string $userId, int $a, int $b): void {
		if ($a === $b) {
			throw new \InvalidArgumentException('A person is always the same as themselves.');
		}
		$this->people->getCluster($userId, $a);
		$this->people->getCluster($userId, $b);
		$this->db->insertIgnoreConflict('facewright_merge_dismissals', [
			'user_id' => $userId,
			'cluster_a' => min($a, $b),
			'cluster_b' => max($a, $b),
		]);
	}

	/**
	 * Centroids for $clusters, from the cache where it is current and recomputed otherwise.
	 * A cluster with no usable embedding has no entry.
	 *
	 * @param list<array{id: int, count: int}> $clusters
	 * @return array{0: array<int, list<float>>, 1: array{centroidsCached: int, centroidsComputed: int}}
	 */
	private function centroids(string $userId, array $clusters, bool $store): array {
		$counts = array_column($clusters, 'count', 'id');
		$out = [];
		$cached = 0;

		foreach (array_chunk(array_keys($counts), 1000) as $batch) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('cluster_id', 'centroid', 'member_count')
				->from('facewright_cluster_centroids')
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->in('cluster_id', $qb->createNamedParameter($batch, IQueryBuilder::PARAM_INT_ARRAY)));
			$result = $qb->executeQuery();
			while ($row = $result->fetch()) {
				$id = (int)$row['cluster_id'];
				if ((int)$row['member_count'] !== $counts[$id]) {
					continue; // stale
				}
				try {
					$out[$id] = FaceVectorCodec::decode(is_resource($row['centroid']) ? stream_get_contents($row['centroid']) : (string)$row['centroid']);
					$cached++;
				} catch (\UnexpectedValueException) {
					// unusable: recomputed below
				}
			}
			$result->closeCursor();
		}

		$computed = 0;
		foreach ($counts as $id => $count) {
			if (isset($out[$id])) {
				continue;
			}
			$vector = $this->computeCentroid($userId, $id);
			if ($vector === null) {
				continue;
			}
			$out[$id] = $vector;
			$computed++;
			if ($store) {
				$this->db->setValues('facewright_cluster_centroids', ['cluster_id' => $id], [
					'user_id' => $userId,
					'centroid' => FaceVectorCodec::encode($vector),
					'member_count' => $count,
					'computed_at' => $this->timeFactory->getTime(),
				]);
			}
		}

		return [$out, ['centroidsCached' => $cached, 'centroidsComputed' => $computed]];
	}

	/**
	 * The normalized mean of up to REPRESENTATIVES of the cluster's best faces (highest detector
	 * confidence first, rows without a score last, as for covers), or null if it has none usable.
	 *
	 * @return ?list<float>
	 */
	private function computeCentroid(string $userId, int $clusterId): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('f.embedding')
			->from('recognize_face_detections', 'd')
			->innerJoin('d', 'facewright_detection_faces', 'l', $qb->expr()->eq('l.detection_id', 'd.id'))
			->innerJoin('l', 'facewright_faces', 'f', $qb->expr()->eq('f.id', 'l.face_id'))
			->where($qb->expr()->eq('d.user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('d.cluster_id', $qb->createNamedParameter($clusterId, IQueryBuilder::PARAM_INT)))
			->orderBy($qb->createFunction('(f.det_score IS NULL)'), 'ASC')
			->addOrderBy('f.det_score', 'DESC')
			->addOrderBy('d.id', 'ASC')
			->setMaxResults(self::REPRESENTATIVES);
		$result = $qb->executeQuery();

		$sum = array_fill(0, FaceVectorCodec::DIMENSIONS, 0.0);
		$used = 0;
		while ($row = $result->fetch()) {
			try {
				$vector = FaceVectorCodec::decode(is_resource($row['embedding']) ? stream_get_contents($row['embedding']) : (string)$row['embedding']);
			} catch (\UnexpectedValueException) {
				continue;
			}
			foreach ($vector as $i => $v) {
				$sum[$i] += $v;
			}
			$used++;
		}
		$result->closeCursor();
		if ($used === 0) {
			return null;
		}

		$norm = sqrt($this->dot($sum, $sum));
		if ($norm <= 0.0) {
			return null;
		}
		foreach ($sum as $i => $v) {
			$sum[$i] = $v / $norm;
		}
		return $sum;
	}

	/** @return array<int, true> the people $clusterId was dismissed against */
	private function dismissedPartners(string $userId, int $clusterId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('cluster_a', 'cluster_b')
			->from('facewright_merge_dismissals')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->eq('cluster_a', $qb->createNamedParameter($clusterId, IQueryBuilder::PARAM_INT)),
				$qb->expr()->eq('cluster_b', $qb->createNamedParameter($clusterId, IQueryBuilder::PARAM_INT)),
			));
		$partners = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$partners[(int)$row['cluster_a'] === $clusterId ? (int)$row['cluster_b'] : (int)$row['cluster_a']] = true;
		}
		$result->closeCursor();
		return $partners;
	}

	/**
	 * Both vectors are L2-normalized, so cosine similarity is the dot product - the same shortcut
	 * as ClusteringService::cosineDistance().
	 *
	 * @param list<float> $a
	 * @param list<float> $b
	 */
	private function dot(array $a, array $b): float {
		$dot = 0.0;
		foreach ($a as $i => $v) {
			$dot += $v * $b[$i];
		}
		return $dot;
	}
}

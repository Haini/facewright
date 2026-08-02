<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;

/**
 * Prototype clustering, extracted from the facewright:cluster command so both it and the admin
 * page's "Run now" trigger share one implementation. Incremental nearest-neighbor assignment
 * modeled on Immich's approach rather than Recognize's own batch HDBSCAN (see CLAUDE.md for
 * why): a face joins/starts a cluster once it has at least $minFaces matches (including itself)
 * within $maxDistance cosine distance of an existing detection. Reclusters everything from
 * scratch each run, but only ever touches detections with no cluster_id yet, so a re-run after
 * new detections have landed is exactly "catch up on the new ones" - already-clustered
 * detections are left alone.
 *
 * Simplification vs. Immich: when a core face has no directly-matched cluster, it always
 * starts a new one, rather than also falling back to the single globally-closest clustered
 * face beyond the initial match set. Close enough for threshold experimentation.
 */
class ClusteringService {
	// recognize_face_detections predates this project on this instance (Nextcloud app
	// uninstalls don't drop tables) and still holds real rows from Recognize's own old
	// face-api.js classifier - 128-dim vectors, not our 512-dim InsightFace ones. Mixing the
	// two in a distance computation would silently corrupt every match, so filter strictly.
	// Public: ResetService reuses this as the same "is this row ours" test, so the two never
	// drift apart into different definitions of "our data".
	public const EXPECTED_DIMENSIONS = 512;

	// Sentinel written to cluster_id for a detection Facewright has evaluated against the full
	// candidate pool and found unclusterable at the current thresholds. It does NOT mean
	// "excluded forever": such rows stay in the matching pool and are still assignable as a
	// side effect of a later face matching them (see assignClusters()), so a person who only
	// becomes clusterable once a third photo of them lands still clusters.
	//
	// The reason for marking at all is that a NULL cluster_id is what Recognize's own
	// MaintenanceJob queries (FaceDetectionMapper::getUsersForUnclustered(), filtered only on
	// "cluster_id IS NULL" plus a minimum detection size - no embedding-dimension filter). Any
	// row left NULL keeps that job enqueueing Recognize's ClusterFacesJob, which loads our
	// 512-dim vectors into its 128-dim HDBSCAN, balloons to ~3.2 GB (it does
	// ini_set('memory_limit', '-1')) and dies on "Column at offset 128 does not exist".
	//
	// -2 rather than -1, which looks like the natural choice since -1 is Recognize's own noise
	// label: Recognize *deliberately re-reads* its -1 rows via
	// FaceDetectionMapper::sampleRejectedDetectionsByUserId() ("WHERE cluster_id = -1") and
	// feeds them straight back into the same HDBSCAN run. Marking -1 would therefore hide our
	// rows from the trigger while leaving them in the crash path. Recognize has no predicate
	// matching -2 anywhere (its only cluster_id filters are IS NULL, = -1, = <real id>, and
	// IS NOT NULL), and Memories inner-joins detections to recognize_face_clusters, so a
	// sentinel with no cluster row is invisible there too.
	public const UNCLUSTERABLE = -2;

	// Recognize's own noise label. Rows sitting at -1 are ones *Recognize* ran through its
	// 128-dim HDBSCAN and rejected - including ours, which it had no business judging, since
	// analyzing 512-dim vectors in a 128-dim space is meaningless. Facewright therefore treats
	// -1 as "not yet evaluated by us" (i.e. pending), re-evaluates it properly, and settles it
	// at UNCLUSTERABLE - which also drains Recognize's rejected-sample pool over time.
	public const RECOGNIZE_REJECTED = -1;

	// Once a pre-existing cluster has this many representatives loaded in loadDetections(),
	// further members are skipped entirely (never fetched, decoded, or held in memory) instead
	// of loading the full cluster - at this project's current scale (a handful of clusters
	// holding tens of thousands of members each), re-verifying a candidate against, say, a
	// cluster's 639th member buys nothing a match against its first 20 wouldn't already tell us.
	private const CLUSTER_REPRESENTATIVE_CAP = 20;

	// How many detections to fetch vectors for per round-trip in loadDetections()'s second pass.
	// Sized so one batch stays well clear of max_allowed_packet (500 x ~10.7 KB ~= 5 MB against
	// MariaDB's 16 MB default) and clear of per-statement placeholder limits on other backends.
	private const VECTOR_FETCH_BATCH = 500;

	public function __construct(
		private IDBConnection $db,
		private IUserManager $userManager,
	) {
	}

	/** @return array{newClusters: int, joined: int, skipped: int, skippedForeign: int, markedUnclusterable: int} */
	public function clusterUser(string $userId, float $maxDistance, int $minFaces): array {
		['detections' => $detections, 'pendingIds' => $idsToProcess, 'skippedForeign' => $skippedForeign] = $this->loadDetections($userId);

		$stats = $this->assignClusters($detections, $idsToProcess, $userId, $maxDistance, $minFaces);
		$this->persistAssignments($detections, $stats['changedIds']);

		// One full pass that assigned nothing is the same proof ClusterScanJob's chain uses: with
		// the data and thresholds unchanged, re-running it would decide identically. Everything
		// still pending is therefore evaluated-and-unclusterable, not merely unvisited.
		$markedUnclusterable = 0;
		if ($idsToProcess !== [] && ($stats['newClusters'] + $stats['joined']) === 0) {
			$stillPending = array_values(array_filter(
				$idsToProcess,
				fn (int $id): bool => $detections[$id]['cluster_id'] === null,
			));
			$markedUnclusterable = $this->markUnclusterable($userId, $stillPending);
		}

		return [
			'newClusters' => $stats['newClusters'],
			'joined' => $stats['joined'],
			'skipped' => $stats['skipped'],
			'skippedForeign' => $skippedForeign,
			'markedUnclusterable' => $markedUnclusterable,
		];
	}

	/**
	 * Chunked counterpart to clusterUser(), for users with too many detections to match and
	 * persist in one synchronous request/job run - see ClusterScanJob, which owns pacing this
	 * across cron cycles via the $afterId cursor. Matches against every unclustered detection
	 * plus up to CLUSTER_REPRESENTATIVE_CAP representatives of each pre-existing cluster (see
	 * loadDetections()), but only *attempts* up to $chunkSize currently-unclustered ids with
	 * id > $afterId this call - that's what bounds the O(chunk x pool) comparison cost and the
	 * persisted-write cost per invocation.
	 *
	 * `pendingUnassignedIds` is every id that was pending on entry and still has no cluster after
	 * this call - i.e. exactly what markUnclusterable() should be given once the chain concludes
	 * a pass changed nothing. It is returned rather than recomputed there so the marking can
	 * never drift from the dimension-filtered set this actually evaluated.
	 *
	 * @return array{attempted: int, newClusters: int, joined: int, skipped: int,
	 *     skippedForeign: int, lastId: ?int, remainingUnclustered: int,
	 *     pendingUnassignedIds: list<int>}
	 */
	public function clusterUserChunk(string $userId, float $maxDistance, int $minFaces, int $afterId, int $chunkSize): array {
		['detections' => $detections, 'pendingIds' => $pendingIds, 'skippedForeign' => $skippedForeign] = $this->loadDetections($userId);

		$unclusteredIds = array_values(array_filter(
			$pendingIds,
			fn (int $id): bool => $id > $afterId,
		));
		$idsToProcess = array_slice($unclusteredIds, 0, $chunkSize);

		$stats = $this->assignClusters($detections, $idsToProcess, $userId, $maxDistance, $minFaces);
		$this->persistAssignments($detections, $stats['changedIds']);

		// Only rows that were pending on entry and are still unassigned - not every null in the
		// pool, which now also includes already-evaluated UNCLUSTERABLE rows. This feeds
		// ClusterScanJob's "nothing left" stop condition, so counting those would wedge the chain.
		$pendingUnassignedIds = [];
		foreach ($pendingIds as $pendingId) {
			if ($detections[$pendingId]['cluster_id'] === null) {
				$pendingUnassignedIds[] = $pendingId;
			}
		}
		$remainingUnclustered = count($pendingUnassignedIds);

		return [
			'attempted' => count($idsToProcess),
			'newClusters' => $stats['newClusters'],
			'joined' => $stats['joined'],
			'skipped' => $stats['skipped'],
			'skippedForeign' => $skippedForeign,
			'lastId' => $idsToProcess === [] ? null : max($idsToProcess),
			'remainingUnclustered' => $remainingUnclustered,
			'pendingUnassignedIds' => $pendingUnassignedIds,
		];
	}

	/**
	 * Runs clusterUser() across every seen user - used by occ facewright:cluster for small,
	 * synchronous, single- or all-user runs. The admin page's "Run now" trigger and
	 * occ facewright:cluster-scan use ClusterScanJob (chunked) instead, since this blocks for
	 * the whole user's detection set in one request - fine for a prototype-scale library, not
	 * for the ~14k-detection scale this instance reached (see CLAUDE.md).
	 *
	 * @return array{newClusters: int, joined: int, skipped: int, perUser: array<string, array{newClusters: int, joined: int, skipped: int}>}
	 */
	public function runForAllUsers(float $maxDistance, int $minFaces): array {
		$totals = ['newClusters' => 0, 'joined' => 0, 'skipped' => 0, 'perUser' => []];

		$this->userManager->callForSeenUsers(function (IUser $user) use (&$totals, $maxDistance, $minFaces): void {
			$result = $this->clusterUser($user->getUID(), $maxDistance, $minFaces);
			$totals['newClusters'] += $result['newClusters'];
			$totals['joined'] += $result['joined'];
			$totals['skipped'] += $result['skipped'];
			$totals['perUser'][$user->getUID()] = [
				'newClusters' => $result['newClusters'],
				'joined' => $result['joined'],
				'skipped' => $result['skipped'],
			];
		});

		return $totals;
	}

	/**
	 * Counts unclustered Facewright (512-dim) detections per user - the denominator behind the
	 * admin page's "still need to be clustered" indicator, and how ClusterTriggerService decides
	 * which users actually need a fresh ClusterScanJob rather than starting one for every seen
	 * user regardless of whether they have anything unclustered.
	 *
	 * Same dimension-filter-in-PHP approach as loadDetections() (see its docblock) rather than a
	 * SQL JSON_LENGTH() filter, for the same portability reason - this project runs across
	 * whatever DB backend the Nextcloud instance uses, not just MySQL/MariaDB.
	 *
	 * Counts exactly what loadDetections() treats as pending - NULL plus RECOGNIZE_REJECTED -
	 * and deliberately excludes UNCLUSTERABLE. This answers "is there new work?", which decides
	 * whether to start a chain at all. Counting UNCLUSTERABLE here would make the answer
	 * permanently yes, since those rows persist by design, so every trigger check would start a
	 * fresh chain over the whole backlog. The chain still reconsiders them once started - they
	 * stay in the matching pool - they just don't get a vote on starting one.
	 *
	 * @return array<string, int> userId => count
	 */
	public function countUnclusteredByUser(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('user_id')
			->selectAlias($qb->func()->count('*'), 'c')
			->from('recognize_face_detections')
			->where($qb->expr()->orX(
				$qb->expr()->isNull('cluster_id'),
				$qb->expr()->eq('cluster_id', $qb->createNamedParameter(self::RECOGNIZE_REJECTED, IQueryBuilder::PARAM_INT)),
			))
			// Aggregated in the database, not by decoding every embedding in PHP. This runs on
			// every admin page load and from ClusterTriggerService, and it used to SELECT
			// face_vector for each unclustered row purely to check its length: before fan-out that
			// was ~58 rows, after it is ~65,800 - measured at 674 MB shipped to PHP and 65,821
			// json_decode() calls per page load.
			//
			// Honest about what this does and doesn't buy: char_length() still faults the off-page
			// BLOB (measured at 264k logical page reads versus 198k for a query that never touches
			// the column), so it is not free the way selectWantedIds()' id/cluster_id projection
			// is. What it removes is the 674 MB of transfer and all of the JSON decoding, leaving
			// a two-row result. Dropping the dimension filter entirely would be cheaper still, but
			// would silently miscount the legacy 128-dim rows this project has never owned.
			->andWhere($qb->expr()->gt(
				$qb->func()->charLength('face_vector'),
				$qb->createNamedParameter(DetectionService::VECTOR_LENGTH_FLOOR, IQueryBuilder::PARAM_INT),
			))
			->groupBy('user_id');

		$counts = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$counts[$row['user_id']] = (int)$row['c'];
		}
		$result->closeCursor();

		return $counts;
	}

	/**
	 * Marks the given detections UNCLUSTERABLE. Called only once a full pass has demonstrably
	 * assigned nothing - see UNCLUSTERABLE's docblock for why leaving rows NULL is what keeps
	 * Recognize's MaintenanceJob re-arming its 3.2 GB ClusterFacesJob.
	 *
	 * Takes an explicit id list rather than a "WHERE cluster_id IS NULL" sweep on purpose. Such
	 * a sweep also catches this user's legacy 128-dim rows, which Facewright never evaluated and
	 * has no business relabelling - the dimensionality filter is this project's whole definition
	 * of "our data" (see EXPECTED_DIMENSIONS), and a bulk UPDATE would quietly cross it. The
	 * caller passes exactly the ids it loaded, dimension-filtered, and found no cluster for.
	 *
	 * @param list<int> $ids
	 * @return int rows marked
	 */
	public function markUnclusterable(string $userId, array $ids): int {
		$marked = 0;
		foreach (array_chunk($ids, self::VECTOR_FETCH_BATCH) as $batch) {
			$qb = $this->db->getQueryBuilder();
			$qb->update('recognize_face_detections')
				->set('cluster_id', $qb->createNamedParameter(self::UNCLUSTERABLE, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->in('id', $qb->createNamedParameter($batch, IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
			$marked += $qb->executeStatement();
		}
		return $marked;
	}

	public function resetUser(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('recognize_face_detections')
			->set('cluster_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();

		$qb = $this->db->getQueryBuilder();
		$qb->delete('recognize_face_clusters')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}

	/**
	 * Attempts to assign a cluster to each of $idsToProcess (in order), matching against the
	 * full $detections candidate pool - shared by clusterUser() and clusterUserChunk() so the
	 * matching/join/create logic exists exactly once. Mutates $detections in place (both the
	 * processed ids and any other still-unclustered match that gets pulled in as a side effect,
	 * same as the original single-pass algorithm did) and reports which ids actually changed, so
	 * the caller persists only those instead of rewriting every already-clustered row again.
	 *
	 * @param array<int, array{file_id: int, vector: list<float>, cluster_id: ?int}> $detections
	 * @param list<int> $idsToProcess
	 * @return array{newClusters: int, joined: int, skipped: int, changedIds: list<int>}
	 */
	private function assignClusters(array &$detections, array $idsToProcess, string $userId, float $maxDistance, int $minFaces): array {
		$newClusters = 0;
		$joined = 0;
		$skipped = 0;
		$changedIds = [];

		foreach ($idsToProcess as $id) {
			if ($detections[$id]['cluster_id'] !== null) {
				// already assigned as a side effect of an earlier id in this same pass
				continue;
			}

			$matches = $this->findMatches($detections, $id, $maxDistance);
			// "matches" excludes $id itself here, so +1 mirrors Immich's "includes the face itself".
			if (count($matches) + 1 < $minFaces) {
				$skipped++;
				continue;
			}

			$clusterId = null;
			foreach ($matches as $match) {
				if ($detections[$match['id']]['cluster_id'] !== null) {
					$clusterId = $detections[$match['id']]['cluster_id'];
					break;
				}
			}

			if ($clusterId === null) {
				$clusterId = $this->createCluster($userId);
				$newClusters++;
			} else {
				$joined++;
			}

			$detections[$id]['cluster_id'] = $clusterId;
			$changedIds[] = $id;
			foreach ($matches as $match) {
				if ($detections[$match['id']]['cluster_id'] === null) {
					$detections[$match['id']]['cluster_id'] = $clusterId;
					$changedIds[] = $match['id'];
				}
			}
		}

		return ['newClusters' => $newClusters, 'joined' => $joined, 'skipped' => $skipped, 'changedIds' => $changedIds];
	}

	/**
	 * Loads every unclustered detection for $userId in full, plus up to
	 * CLUSTER_REPRESENTATIVE_CAP representatives (earliest by id) of each pre-existing cluster
	 * - not full cluster membership. See CLUSTER_REPRESENTATIVE_CAP's docblock for why capping
	 * only the already-clustered side is safe: those faces' cluster assignment is already
	 * settled, so a few representatives are enough to recognize a new match against that
	 * cluster; the unclustered side still needs every candidate since new-cluster formation
	 * depends on it.
	 *
	 * Split into two passes on purpose, and that split is what makes the cap cost anything at
	 * all. A single "SELECT ... face_vector ... WHERE user_id = ?" pass transfers every row's
	 * vector and only then discards the over-cap ones in PHP - which saves memory but zero I/O,
	 * because face_vector is a longtext: at ~10.7 KB it lives off-page in DYNAMIC row format, so
	 * InnoDB spends a dedicated 16 KB page read on each row whether or not we keep it, and
	 * read-ahead can't batch those. So pass 1 selects no vector column at all (clustered-index
	 * leaf pages only, never faulting in a BLOB page) purely to decide *which* ids are wanted,
	 * and pass 2 fetches vectors for just those. On this instance that is 22,233 rows fetched
	 * rather than 65,556 - see CLAUDE.md for the measurements behind this.
	 *
	 * Note $skippedForeign now counts foreign-dimension rows only among the rows actually
	 * fetched, not across the user's whole table, since over-cap cluster members are never
	 * examined. It's a diagnostic counter rather than a control input, so undercounting it is
	 * harmless - but don't start treating it as a total.
	 *
	 * UNCLUSTERABLE (-1) rows are normalized to a null cluster_id in the returned pool. That is
	 * deliberate and load-bearing in two places in assignClusters(): they must not be offered as
	 * a cluster for a new face to *join* (they aren't a real cluster), and they must remain
	 * eligible for side-effect assignment when some other face pulls them into a new cluster.
	 * What that normalization must NOT be used for is deciding what to attempt - a -1 row has
	 * already been evaluated, so re-attempting it every pass is pure cost. Hence `pendingIds`,
	 * which lists only the ids whose stored cluster_id was genuinely NULL; callers drive
	 * $idsToProcess off that, never off the normalized cluster_id.
	 *
	 * @return array{detections: array<int, array{file_id: int, vector: list<float>, cluster_id: ?int}>, pendingIds: list<int>, skippedForeign: int}
	 */
	private function loadDetections(string $userId): array {
		$wantedIds = $this->selectWantedIds($userId);

		// Rows are streamed and decoded one at a time rather than fetchAll()'d: at real-library
		// scale fetchAll() would hold a batch's entire raw result set (JSON text) in memory on
		// top of the decoded vectors being accumulated alongside it. Holding both forms at once
		// is what originally pushed this past PHP's memory_limit.
		$detections = [];
		$pendingIds = [];
		$skippedForeign = 0;
		foreach (array_chunk($wantedIds, self::VECTOR_FETCH_BATCH) as $batch) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id', 'file_id', 'face_vector', 'cluster_id')
				->from('recognize_face_detections')
				->where($qb->expr()->in('id', $qb->createNamedParameter($batch, IQueryBuilder::PARAM_INT_ARRAY)))
				->orderBy('id', 'ASC');
			$result = $qb->executeQuery();

			while ($row = $result->fetch()) {
				$vector = json_decode($row['face_vector'], true);
				if (!is_array($vector) || count($vector) !== self::EXPECTED_DIMENSIONS) {
					$skippedForeign++;
					continue;
				}
				$id = (int)$row['id'];
				$clusterId = $row['cluster_id'] !== null ? (int)$row['cluster_id'] : null;
				if ($clusterId === null || $clusterId === self::RECOGNIZE_REJECTED) {
					// Never evaluated by us - Recognize's -1 verdict does not count, see its
					// constant docblock.
					$pendingIds[] = $id;
					$clusterId = null;
				} elseif ($clusterId === self::UNCLUSTERABLE) {
					// Evaluated already: matchable, but not attempted again - see the docblock.
					$clusterId = null;
				}
				$detections[$id] = [
					'file_id' => (int)$row['file_id'],
					'vector' => $vector,
					'cluster_id' => $clusterId,
				];
			}
			$result->closeCursor();
		}

		return ['detections' => $detections, 'pendingIds' => $pendingIds, 'skippedForeign' => $skippedForeign];
	}

	/**
	 * Pass 1 of loadDetections(): works out which detection ids are worth fetching vectors for -
	 * every unclustered row of $userId's, plus the first CLUSTER_REPRESENTATIVE_CAP rows of each
	 * cluster they already have. Selects no face_vector precisely so it never faults in an
	 * off-page BLOB page; see loadDetections()'s docblock for why that distinction is the whole
	 * point.
	 *
	 * Returns ids ascending, and loadDetections() preserves that order (batches are consecutive
	 * slices, each re-sorted by id) because clusterUserChunk()'s $afterId cursor walks
	 * $detections in id order.
	 *
	 * @return list<int> ascending
	 */
	private function selectWantedIds(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'cluster_id')
			->from('recognize_face_detections')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('id', 'ASC');
		$result = $qb->executeQuery();

		$wantedIds = [];
		$representativeCounts = []; // cluster_id => representatives already taken this call
		while ($row = $result->fetch()) {
			$clusterId = $row['cluster_id'] !== null ? (int)$row['cluster_id'] : null;
			// Only real clusters (positive ids) get capped. The sentinels are not clusters, and
			// capping them would strand all but 20 of those rows outside the pool, so they could
			// never be matched into a cluster later. They belong with the unclustered side:
			// loaded in full, every time.
			if ($clusterId !== null && $clusterId > 0) {
				$kept = $representativeCounts[$clusterId] ?? 0;
				if ($kept >= self::CLUSTER_REPRESENTATIVE_CAP) {
					// Enough representatives of this pre-existing cluster already; unclustered
					// rows are never subject to the cap, see the class docblock.
					continue;
				}
				$representativeCounts[$clusterId] = $kept + 1;
			}
			$wantedIds[] = (int)$row['id'];
		}
		$result->closeCursor();

		return $wantedIds;
	}

	/** @return list<array{id: int, distance: float}> sorted closest-first */
	private function findMatches(array $detections, int $id, float $maxDistance): array {
		$matches = [];
		foreach ($detections as $otherId => $other) {
			if ($otherId === $id) {
				continue;
			}
			$distance = $this->cosineDistance($detections[$id]['vector'], $other['vector']);
			if ($distance <= $maxDistance) {
				$matches[] = ['id' => $otherId, 'distance' => $distance];
			}
		}
		usort($matches, fn ($a, $b) => $a['distance'] <=> $b['distance']);
		return $matches;
	}

	private function cosineDistance(array $a, array $b): float {
		// Both vectors are already L2-normalized (facewright's /detect returns normed_embedding),
		// so cosine similarity is just the dot product.
		$dot = 0.0;
		foreach ($a as $i => $v) {
			$dot += $v * $b[$i];
		}
		return 1.0 - $dot;
	}

	private function createCluster(string $userId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('recognize_face_clusters')
			->values([
				'title' => $qb->createNamedParameter(''),
				'user_id' => $qb->createNamedParameter($userId),
			]);
		$qb->executeStatement();
		return $qb->getLastInsertId();
	}

	/** @param list<int> $ids */
	private function persistAssignments(array $detections, array $ids): void {
		foreach (array_unique($ids) as $id) {
			$clusterId = $detections[$id]['cluster_id'];
			if ($clusterId === null) {
				continue;
			}
			$qb = $this->db->getQueryBuilder();
			$qb->update('recognize_face_detections')
				->set('cluster_id', $qb->createNamedParameter($clusterId, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
			$qb->executeStatement();
		}
	}
}

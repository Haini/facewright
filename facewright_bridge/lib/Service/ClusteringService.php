<?php

declare(strict_types=1);

namespace OCA\FacewrightBridge\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

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

	// -1 means "unassigned: excluded from clustering until a human assigns it" - not "pending,
	// re-evaluate me". Every consumer other than our own clustering already agreed on this
	// reading before this constant existed: it is what Memories' "unassigned faces" view
	// selects, what Memories' "move to person" action operates on, and what a Memories person
	// delete writes to its members via Recognize's own FaceClusterMapper::delete() - deleting a
	// person in Memories un-clusters its faces, it does not erase them. Recognize's now-disabled
	// HDBSCAN also wrote -1 as its own "noise" verdict, including on rows it had no business
	// judging (analyzing our 512-dim vectors in its 128-dim space is meaningless) - those legacy
	// rows were reopened once by `occ facewright:reopen-unassigned` before this meaning shipped,
	// so this constant never needs to treat -1 as "not yet evaluated".
	//
	// Clustering therefore never seeds a match from a -1 row, never matches a candidate against
	// one, and never assigns -1 as a side effect of matching some other face - the same as any
	// row a human has deliberately taken out of a cluster. resetUser() is the one thing that
	// reopens -1 rows back to NULL, and it does so as part of what "reset" means generally, not
	// as special-cased -1 handling.
	public const UNASSIGNED = -1;

	// -3 means "not a face, or not someone the user wants to see": set only by a human, from the
	// People page. Clustering never seeds from it, never matches against it and never changes it,
	// and resetUser() keeps it - an ignore is a judgement about the detection, not about how it
	// was clustered. Invisible to Memories (it inner-joins recognize_face_clusters, and its
	// unassigned view reads -1 only) and to Recognize (its cluster_id predicates are IS NULL,
	// = -1 and = <real id>; only its own resetClusters() would wipe it, like every other
	// assignment). Not a cluster: nothing may ever create a recognize_face_clusters row for it.
	public const IGNORED = -3;

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
		private LoggerInterface $logger,
		private FaceStore $faceStore,
		private ProjectionService $projection,
	) {
	}

	/** @return array{newClusters: int, joined: int, skipped: int, written: int, skippedForeign: int, markedUnclusterable: int} */
	public function clusterUser(string $userId, float $maxDistance, int $minFaces): array {
		['detections' => $detections, 'pendingIds' => $idsToProcess, 'skippedForeign' => $skippedForeign] = $this->loadDetections($userId);

		$stats = $this->assignClusters($detections, $idsToProcess, $userId, $maxDistance, $minFaces);
		$written = $this->persistAndRevert($userId, $detections, $stats);

		// One full pass that wrote nothing is the same proof ClusterScanJob's chain uses: with the
		// data and thresholds unchanged, re-running it would decide identically. Everything still
		// pending is therefore evaluated-and-unclusterable, not merely unvisited. "Wrote nothing",
		// not "matched nothing": a match that could not be written is not progress.
		$markedUnclusterable = 0;
		if ($idsToProcess !== [] && $written === 0) {
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
			'written' => $written,
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
	 * `written` is the number of rows actually written to the database. That, not the number of matches
	 * found, is what ClusterScanJob counts as progress: a match that could not be written (a human
	 * changed the row meanwhile, or the two tables disagree about it) leaves the face pending and
	 * must not keep the chain alive.
	 *
	 * @return array{attempted: int, newClusters: int, joined: int, skipped: int, written: int,
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
		$written = $this->persistAndRevert($userId, $detections, $stats);

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
			'written' => $written,
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
	 * for a real library (see CLAUDE.md's invariants on I/O).
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
	 * Counts unclustered Facewright detections per user - the denominator behind the admin page's
	 * "still need to be clustered" indicator, and how ClusterTriggerService decides which users
	 * actually need a fresh ClusterScanJob rather than starting one for every seen user
	 * regardless of whether they have anything unclustered.
	 *
	 * Reads facewright_user_faces, like loadDetections(), so Recognize's legacy rows are never
	 * counted: they have no row there.
	 *
	 * Counts exactly what loadDetections() treats as pending - state PENDING - and
	 * deliberately excludes both UNASSIGNED and UNCLUSTERABLE. This answers "is there new work?",
	 * which decides whether to start a chain at all. Counting UNCLUSTERABLE here would make the
	 * answer permanently yes, since those rows persist by design, so every trigger check would
	 * start a fresh chain over the whole backlog; counting UNASSIGNED would re-open work a human
	 * deliberately closed. The chain still reconsiders UNCLUSTERABLE rows once started - they
	 * stay in the matching pool - they just don't get a vote on starting one.
	 *
	 * @return array<string, int> userId => count
	 */
	public function countUnclusteredByUser(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('u.user_id')
			->selectAlias($qb->func()->count('*'), 'c')
			->from('facewright_user_faces', 'u')
			->where($qb->expr()->eq('u.state', $qb->createNamedParameter(FaceState::PENDING, IQueryBuilder::PARAM_INT)))
			->groupBy('u.user_id');

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
	 * Takes an explicit id list rather than a sweep of everything pending on purpose: the caller passes exactly
	 * the ids it loaded and found no cluster for, so a face that arrived meanwhile has not been judged.
	 *
	 * @param list<int> $ids
	 * @return int rows marked
	 */
	public function markUnclusterable(string $userId, array $ids): int {
		$marked = 0;
		foreach (array_chunk($ids, self::VECTOR_FETCH_BATCH) as $batch) {
			// Only faces still pending (or already marked): a face a human moved, removed or ignored while the pass
			// was running keeps what the human set.
			$matched = $this->inTransaction(fn (): array => $this->faceStore->setState(
				$userId, $batch, FaceState::UNCLUSTERABLE, null, [FaceState::PENDING, FaceState::UNCLUSTERABLE],
			));
			$marked += count($matched);
			// Hidden states are not in Recognize's table: this is what removes a remnant left there.
			$this->projection->push($matched);
		}
		return $marked;
	}

	/**
	 * Puts this user's UNCLUSTERABLE rows back to pending, so a later run reconsiders them.
	 *
	 * Needed because the -2 mark records a verdict reached under one set of thresholds and is never
	 * revisited - which is correct while the thresholds hold, and silently wrong the moment they
	 * change. Now that max-distance and min-faces are per-user and editable from the personal
	 * settings page, the second use of those controls would otherwise appear to do nothing.
	 * PersonalSettingsController calls this only when the stored values actually moved.
	 *
	 * Far narrower than resetUser(): no clusters are deleted, already-clustered rows are untouched,
	 * and the dimensionality guard keeps it off legacy 128-dim rows Facewright never evaluated.
	 *
	 * @return int rows returned to pending
	 */
	public function clearUnclusterableMarks(string $userId): int {
		$sel = $this->db->getQueryBuilder();
		$sel->select('id')
			->from('facewright_user_faces')
			->where($sel->expr()->eq('user_id', $sel->createNamedParameter($userId)))
			->andWhere($sel->expr()->eq('state', $sel->createNamedParameter(FaceState::UNCLUSTERABLE, IQueryBuilder::PARAM_INT)));
		$ids = array_map('intval', $sel->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));

		$reopened = $this->inTransaction(fn (): array => $this->faceStore->setState(
			$userId, $ids, FaceState::PENDING, null, [FaceState::UNCLUSTERABLE],
		));
		$this->projection->push($reopened);
		return count($reopened);
	}

	/**
	 * Clears every cluster_id for $userId and deletes their clusters outright. This also reopens
	 * UNASSIGNED (-1) and UNCLUSTERABLE (-2) rows back to pending, which is the intended meaning
	 * of a full reset: a person a human previously unassigned goes back into the matching pool
	 * along with everything else. IGNORED (-3) rows are kept.
	 */
	public function resetUser(string $userId): void {
		$touched = $this->inTransaction(function () use ($userId): array {
			$touched = $this->faceStore->resetUser($userId);

			// The people are gone, so what SuggestionService kept about them is meaningless.
			foreach (['facewright_cluster_centroids', 'facewright_merge_dismissals'] as $table) {
				$qb = $this->db->getQueryBuilder();
				$qb->delete($table)
					->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
				$qb->executeStatement();
			}
			return $touched;
		});
		// Faces back to hidden leave Recognize's table, and the people with them.
		$this->projection->push($touched['faces'], $touched['clusters']);
	}

	/**
	 * Runs $work in one transaction and returns its result.
	 *
	 * @template T
	 * @param callable(): T $work
	 * @return T
	 */
	private function inTransaction(callable $work): mixed {
		$this->db->beginTransaction();
		try {
			$result = $work();
			$this->db->commit();
			return $result;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
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
	 * @return array{newClusters: int, joined: int, skipped: int, changedIds: list<int>, createdClusterIds: list<int>}
	 */
	private function assignClusters(array &$detections, array $idsToProcess, string $userId, float $maxDistance, int $minFaces): array {
		$newClusters = 0;
		$joined = 0;
		$skipped = 0;
		$changedIds = [];
		$createdClusterIds = [];

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
				$createdClusterIds[] = $clusterId;
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

		return ['newClusters' => $newClusters, 'joined' => $joined, 'skipped' => $skipped, 'changedIds' => $changedIds, 'createdClusterIds' => $createdClusterIds];
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
	 * all. Pass 1 selects no embedding at all (clustered-index leaf pages only, never faulting in
	 * a BLOB page) purely to decide *which* ids are wanted, and pass 2 fetches embeddings for just
	 * those - via facewright_faces (Stage 1 of docs/independence-implementation.md), a
	 * 2 KB-per-row blob rather than recognize_face_detections.face_vector's ~4.8 KB JSON, so this
	 * pass is cheaper again than the ~340 MB it was measured at against that column (see CLAUDE.md's
	 * "Capping rows in PHP saves no I/O").
	 *
	 * Note $skippedForeign now counts embedding-decode failures only among the faces actually
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
		$wanted = $this->selectWantedIds($userId); // detectionId => [faceId, clusterId]

		// Within one user a face is never shared across two detection rows (fan-out only shares a
		// face *across* users), so this is normally 1:1 - grouped defensively rather than assumed.
		$detectionIdsByFaceId = [];
		foreach ($wanted as $detectionId => $info) {
			$detectionIdsByFaceId[$info['faceId']][] = $detectionId;
		}

		$detections = [];
		$pendingIds = [];
		$skippedForeign = 0;
		foreach (array_chunk(array_keys($detectionIdsByFaceId), self::VECTOR_FETCH_BATCH) as $batch) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id', 'file_id', 'embedding')
				->from('facewright_faces')
				->where($qb->expr()->in('id', $qb->createNamedParameter($batch, IQueryBuilder::PARAM_INT_ARRAY)));
			$result = $qb->executeQuery();

			while ($row = $result->fetch()) {
				$faceId = (int)$row['id'];
				$detectionIds = $detectionIdsByFaceId[$faceId] ?? [];
				try {
					$vector = FaceVectorCodec::decode($row['embedding']);
				} catch (\UnexpectedValueException) {
					$skippedForeign += count($detectionIds);
					continue;
				}
				foreach ($detectionIds as $id) {
					$clusterId = $wanted[$id]['clusterId'];
					if ($clusterId === null) {
						$pendingIds[] = $id;
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
			}
			$result->closeCursor();
		}

		// selectWantedIds() returns ids ascending, but batching by face id (not detection id) and
		// an unordered IN() query don't preserve that - re-sort explicitly. pendingIds' ascending
		// order is what clusterUserChunk()'s $afterId cursor relies on to walk the backlog
		// monotonically.
		sort($pendingIds);

		return ['detections' => $detections, 'pendingIds' => $pendingIds, 'skippedForeign' => $skippedForeign];
	}

	/**
	 * Pass 1 of loadDetections(): works out which detections are worth fetching embeddings for -
	 * every unclustered row of $userId's, plus the first CLUSTER_REPRESENTATIVE_CAP rows of each
	 * cluster they already have - and which physical face (facewright_faces.id) each one points
	 * to. Reads facewright_user_faces, whose rows are all ours by construction. Faces a human took
	 * out of clustering (UNASSIGNED, IGNORED) are left out by the WHERE, so they are never a seed,
	 * never a match candidate and never fetched; without that, an ignored row would be loaded as a
	 * real cluster id and offered as a join target.
	 *
	 * @return array<int, array{faceId: int, clusterId: ?int}> keyed by detection id, ascending
	 */
	private function selectWantedIds(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('u.id', 'u.cluster_id', 'u.state', 'u.face_id')
			->from('facewright_user_faces', 'u')
			->where($qb->expr()->eq('u.user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->in('u.state', $qb->createNamedParameter(
				[FaceState::PENDING, FaceState::ASSIGNED, FaceState::UNCLUSTERABLE],
				IQueryBuilder::PARAM_INT_ARRAY,
			)))
			->orderBy('u.id', 'ASC');
		$result = $qb->executeQuery();

		$wanted = [];
		$representativeCounts = []; // cluster_id => representatives already taken this call
		while ($row = $result->fetch()) {
			// Back to the representation the rest of the pass works in: null (pending), the
			// UNCLUSTERABLE marker, or a person's id.
			$clusterId = match ((int)$row['state']) {
				FaceState::ASSIGNED => (int)$row['cluster_id'],
				FaceState::UNCLUSTERABLE => self::UNCLUSTERABLE,
				default => null,
			};
			// Only real clusters (positive ids) get capped. UNCLUSTERABLE is not a cluster, and
			// capping it would strand all but 20 of those rows outside the pool, so they could
			// never be matched into a cluster later. It belongs with the unclustered side:
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
			$wanted[(int)$row['id']] = ['faceId' => (int)$row['face_id'], 'clusterId' => $clusterId];
		}
		$result->closeCursor();

		return $wanted;
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
		// Not pushed here: an empty person is invisible to Memories, and the pass either fills it (and
		// persistAssignments() pushes it with its faces) or deletes it again.
		return $this->faceStore->allocateCluster($userId);
	}

	/**
	 * Writes the assignments a pass decided on, but only onto rows that are still pending
	 * (NULL) or UNCLUSTERABLE, and only into clusters that still exist. A pass spends seconds to
	 * minutes matching between loading the pool and getting here, and a human can act on the same
	 * rows in that window: a face moved to a person, removed (UNASSIGNED) or ignored must keep
	 * what the human set, and a person deleted or merged away must not be refilled. Clusters this
	 * pass created that ended up with no member are deleted again rather than left as empty
	 * "Unnamed" people.
	 *
	 * @param list<int> $ids
	 * @param list<int> $createdClusterIds
	 * @return list<int> ids of the rows actually written
	 */
	private function persistAssignments(string $userId, array $detections, array $ids, array $createdClusterIds): array {
		$ids = array_values(array_unique($ids));

		$targets = [];
		foreach ($ids as $id) {
			if ($detections[$id]['cluster_id'] !== null) {
				$targets[(int)$detections[$id]['cluster_id']] = true;
			}
		}
		$existing = [];
		foreach (array_chunk(array_keys($targets), 1000) as $batch) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id')
				->from('facewright_clusters')
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->in('id', $qb->createNamedParameter($batch, IQueryBuilder::PARAM_INT_ARRAY)));
			$result = $qb->executeQuery();
			while (($clusterId = $result->fetchOne()) !== false) {
				$existing[(int)$clusterId] = true;
			}
			$result->closeCursor();
		}

		$uf = $this->db->getQueryBuilder()->getTableName('facewright_user_faces');
		$fc = $this->db->getQueryBuilder()->getTableName('facewright_clusters');
		$written = 0;
		$requested = 0;
		$writtenIds = [];
		$this->inTransaction(function () use ($userId, $detections, $ids, $createdClusterIds, $existing, $uf, $fc, &$written, &$requested, &$writtenIds): void {
			foreach ($ids as $id) {
				$clusterId = $detections[$id]['cluster_id'];
				if ($clusterId === null) {
					continue;
				}
				$requested++;
				if (!isset($existing[(int)$clusterId])) {
					continue;
				}
				// Guarded on OUR row: still pending or unclusterable, this user's, and the person still exists.
				// The read that chose the face and this guard must test the same table.
				$changed = $this->db->executeStatement(
					"UPDATE {$uf} SET state = " . FaceState::ASSIGNED . ', cluster_id = ? WHERE id = ? AND user_id = ? AND state IN ('
					. FaceState::PENDING . ',' . FaceState::UNCLUSTERABLE . ") AND EXISTS (SELECT 1 FROM {$fc} c WHERE c.id = ? AND c.user_id = ?)",
					[(int)$clusterId, (int)$id, $userId, (int)$clusterId, $userId],
				);
				$written += $changed;
				if ($changed > 0) {
					$writtenIds[] = (int)$id;
				}
			}

			if ($createdClusterIds !== []) {
				$this->faceStore->deleteEmptyUntitled($userId, $createdClusterIds);
			}
		});
		// People first, then faces, then the people that did not survive: ProjectionService orders it.
		$this->projection->push($writtenIds, $createdClusterIds);

		if ($written < $requested) {
			$this->logger->info(
				'facewright: ' . ($requested - $written) . " cluster assignment(s) for {$userId} skipped, rows changed during the pass",
				['app' => 'facewright_bridge'],
			);
		}

		return $writtenIds;
	}

	/**
	 * Persists a pass's assignments and undoes, in memory, every one that was not written.
	 *
	 * assignClusters() decides on the in-memory pool, and a decision is only an assignment once the
	 * row has been written. A face whose write was rejected is still pending: reporting it as
	 * assigned would hide it from the "still unclustered" count and from the unclusterable marking
	 * at the end of a chain, and counting its match as progress would restart the chain forever.
	 *
	 * @param array<int, array{file_id: int, vector: list<float>, cluster_id: ?int}> $detections
	 * @param array{changedIds: list<int>, createdClusterIds: list<int>} $stats
	 * @return int rows actually written
	 */
	private function persistAndRevert(string $userId, array &$detections, array $stats): int {
		$writtenIds = $this->persistAssignments($userId, $detections, $stats['changedIds'], $stats['createdClusterIds']);
		foreach (array_diff($stats['changedIds'], $writtenIds) as $id) {
			$detections[$id]['cluster_id'] = null;
		}
		return count($writtenIds);
	}
}

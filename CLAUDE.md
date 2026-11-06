# Facewright

A replacement backend for Nextcloud Recognize's face-detection pipeline, built as a Nextcloud
AppAPI ExApp. Uses InsightFace instead of Recognize's face-api.js/tfjs-node stack, but keeps
Recognize installed as a companion app rather than forking it.

This repo holds two apps:
- `appinfo/`, `ex_app/` etc. at the top level — `facewright`, the Python AppAPI ExApp (InsightFace
  detection).
- `facewright_bridge/` — a small, separate, regular Nextcloud PHP app. It exists because ExApps
  have no path to real database access; it calls `facewright`, keeps the results in its own tables
  and copies what Memories needs into Recognize's.

`README.md` is the user-facing overview and quickstart. This file is for anyone changing the code:
the architecture, the invariants that are easy to break silently, and the operational rules that
were learned the hard way. It deliberately does not narrate how each of those was discovered.

**Current work:** start at "Where things stand", the top of `docs/own-tables.md`. It says what runs on live and what is
open next, with the exact commands.

## Architecture

Memories' People page has no face-detection logic of its own — it reads two tables Recognize's PHP
side populates: `oc_recognize_face_detections` and `oc_recognize_face_clusters`. So this is a
**companion app**, not a fork:

- Recognize stays installed and enabled (its migrations create the schema, Memories keeps
  working), but its own classifier is kept off so it doesn't fight our writes.
- `facewright` owns detection + embedding (InsightFace `buffalo_l`, CPU, via AppAPI).
- `facewright_bridge` owns everything Nextcloud-side: triggers, queueing, per-user fan-out,
  clustering, settings, the face and people data in its own tables, and the copy of it in
  Recognize's tables that Memories reads.
- Rejected: patching Recognize to call the ExApp. Smaller diff, but inherits clustering thresholds
  tuned for a different embedding space and means an ongoing rebase against upstream.

Clustering follows Immich's algorithm (its default model is the same `buffalo_l`): incremental
nearest-neighbour assignment, cosine distance ≤ 0.5, ≥ 3 matches before a face joins/starts a
cluster. Recognize's own HDBSCAN thresholds do not transfer (128-dim, Euclidean, different model).

### Why Recognize cannot simply be uninstalled

Three hard dependencies on the app being **enabled**, not just on its tables existing:

1. `Memories\Util::recognizeIsInstalled()` requires `isEnabledForUser('recognize')` and version
   ≥ 3.8.0; `recognizeIsEnabled()` additionally requires Recognize's own `faces.enabled` appconfig
   to be `'true'`. Fail either and the People page never renders.
2. Every *write* from Memories (rename/delete/create a person, remove a face) is a WebDAV call
   against Recognize's `OCA\Recognize\Dav\RootCollection` at `/remote.php/dav/recognize/...`.
3. Memories' frontend hardcodes `recognize` and `facerecognition` as the only people backends.

Writes from Memories and Photos are answered by this app, not by Recognize: `RecognizeWriteRouterPlugin`
turns the five DAV requests they send (create, rename, delete person; remove or move a face) into
`PeopleService` calls, scoped to the logged-in user, and refuses any other change under `recognize/`.
The router has no off switch (it had `block_recognize_writes` until Stage E; rollback is in `docs/own-tables.md`).
The coupling is the path shapes, the `Destination` header and the status codes; see `docs/own-tables.md`, Stage B.

Only reads are decoupled (`RecognizeBackend.php` queries the tables directly). Nextcloud's own
Photos app has the same three dependencies. The full coupling inventory, the write-path bugs it
causes, and the stages already done are in `docs/independence.md` and
`docs/independence-implementation.md`. The current plan is `docs/own-tables.md`: all face and people
data in tables we own, a temporary one-way push to Recognize's tables with Memories' edits routed through
our people service, and an upstream Memories change as the end state. It replaces the `recognize`-contract shim.

## Components

### `facewright` (ExApp)

- `ex_app/lib/main.py` — FastAPI app with `AppAPIAuthMiddleware`; `FaceAnalysis` loaded once in
  the `lifespan` hook. `POST /detect` takes `{"path": "..."}` (relative to the calling user's
  files root), downloads it with `nc_py_api` **as that user** (the user comes from the
  `AUTHORIZATION-APP-API` header; the app secret is the trust anchor), and returns
  `{faces: [{bbox, kps, det_score, embedding}], image_width, image_height}`. `bbox` is absolute
  pixels `[x1,y1,x2,y2]`; the bridge converts.
- `nc_py_api`'s `files.download` raises the base `NextcloudException` with `.status_code`, never
  the `NotFound` subclass — check the status code explicitly.
- Only `/heartbeat` is exempt from signature checks; a bare `curl` at the container 401s.

### `facewright_bridge` (Nextcloud app)

Services (`lib/Service/`):
- `DetectionService` — calls the ExApp via `OCA\AppAPI\PublicFunctions::exAppRequest()`, converts
  boxes, stores the physical face in `facewright_faces` and a pending row per user through `FaceStore`. Owns
  `SUPPORTED_MIMETYPES` and, until Stage F, `VECTOR_PRECISION`, `VECTOR_LENGTH_FLOOR`, `encodeVector()` and
  `ownRowsExpr()` (see invariants).
- `ProcessedFileService` — the `facewright_processed` table (`user_id`, `file_id`,
  `processed_at`, `faces_found`). One row per (user, file) after every attempt, including
  zero-face results (`faces_found = 0`) and permanent failures (`FACES_FAILED = -1`). This — not
  the detections table — is the idempotency check everywhere.
- `PathFilterService` — per-user included-paths allowlist, exact-or-folder-prefix matching.
  Empty list = nothing included. Also `resolveSearchRoots()` so enumeration and the "not yet
  processed" statistic walk the identical file set. `images()` is how anything here lists a
  folder's images: `searchByMime()` builds every Node in one array and is unaffordable on a large
  library. Only an empty page ends that walk: LIMIT is applied in SQL and core drops rejected rows
  afterwards, so a short page does not mean the last page.
- `UserSettingsService` — per-user config via `OCP\Config\IUserConfig`.
- `AccessService` / `AccessSyncService` / `PruneService` / `MountFileService` — who can see a file
  and keeping rows in step with that (see "Multi-user fan-out").
- `ClusteringService`, `ClusterTriggerService` — clustering.
- `PeopleService` — the one seam every people-level operation (list with paging/search/covers,
  create, rename, merge, delete, detach, ignore, move, delete-empty) goes through, scoped to one
  user per call; the bulk forms (`moveFaces`, `detachFaces`, `ignoreFaces`, `mergeMany`,
  `deleteMany`) resolve every id before writing and run in one transaction. What
  `PeopleApiController`'s OCS API, `occ facewright:people` and the DAV router call.
- `SuggestionService` — "possibly the same person", **per person only** (a library-wide list costs
  named × candidates comparisons, ~7 s at 400 named people, so it was removed): cosine distance
  between cached centroids (`facewright_cluster_centroids`, the normalized mean of a person's 10
  best-scored faces, stale when the person's size changed), threshold `suggest_max_distance`
  (default 0.6, uncalibrated). Dismissed pairs live in `facewright_merge_dismissals`.
- `BatchTriggerService` — enumerates unprocessed candidates for enabled users and enqueues jobs.
- `FaceStore` — the only writer of `facewright_user_faces` / `facewright_clusters` (see "Our own tables").
- `ProjectionService` — copies them into Recognize's tables for Memories, and reconciles drift; the one place that
  writes `recognize_face_detections` / `_clusters` (plus the deliberate direct statements in `ResetService` and
  `PeopleService::moveLegacyMembers`).
- `ResetService`, `VectorCompactionService` — maintenance.

Background jobs (`lib/BackgroundJob/`):
- `DetectQueuedJob {fileId}` — the one detection pipeline, for every trigger path.
- `BatchScanJob {chunkSize, userId?}` — self-requeuing: enqueues one chunk of candidates per cron
  tick, stops re-adding itself once a pass enqueues nothing.
- `ClusterScanJob {userId, afterId, passProgress, ...}` — self-requeuing per-user clustering in
  chunks (see invariants for the stop condition).
- `AccessSyncJob` / `FileAccessSyncJob` — reconcile rows after access changes.
- `DetectionMaintenanceJob`, `ClusteringMaintenanceJob` — hourly `TimedJob`s that restart the
  respective chain if new work exists. These are the safety net when an event is missed.
- `ProjectionMaintenanceJob` — hourly reconcile of Recognize's tables against ours, capped per run.

Listeners (`lib/Listener/`):
- `FileCreatedListener` — `NodeCreatedEvent`, `CacheEntryInsertedEvent` and `NodeRenamedEvent`
  → enqueue `DetectQueuedJob` for supported images (a moved folder enqueues `BatchScanJob`
  instead). Gated by `automatic_detection_enabled`. Renames matter because "phone auto-uploads
  to an excluded folder, user sorts into an included one" is the normal way photos arrive.
- `FileAccessListener` — mount add/remove, delete, rename → access reconciliation.

Settings: an admin section (instance-wide toggles, defaults, scan/cluster triggers, live status,
per-user overview) and a personal section (enable, own included paths, own thresholds, own
backlog scan). Plain PHP templates + vanilla JS, no build step.

The **People page** (`/apps/facewright_bridge/`, a top-bar entry) curates people from face crops:
name, merge, delete, move/remove/ignore faces, unassigned and ignored views, merge suggestions,
bulk selection, a photo view with every face boxed. Memories stays the photo browser. It is a
shell (`PageController`, `templates/main.php`, `js/people.js`, `css/people.css`, hash router) over
`PeopleApiController` and `SuggestionApiController` (OCS under `/ocs/v2.php/apps/facewright_bridge/
api/v1/`, every action `#[NoAdminRequired]` and scoped to the session user, no parameter names
another user) and `FacePreviewController` (`/faces/{id}/preview`, a square crop of Nextcloud's
1024 px preview; a plain route because `<img>` cannot send OCS headers). Requirements and rationale:
`docs/people-ui.md`; build plan: `docs/people-ui-implementation.md`. Things that bit:
- Nextcloud's global CSS resets `dialog` margins and gives every `button` an opaque fill: dialogs
  need `margin: auto` and the face boxes need `background: transparent !important`.
- Esc on a `<dialog>` is handled on the document for the topmost one: whether the browser fires
  `cancel` varies, and focus leaves the dialog when a button is redrawn.
- An `<img>` with its `src` removed still draws a broken-image icon once sized by CSS; a failed
  crop is swapped for a placeholder element instead.
- A name already taken answers 400 with `code: title_exists` (`DuplicateTitleException`), which the
  page turns into a merge offer; never match on the English message.

`occ` commands: `facewright:detect <user> <path>` (forces re-detection), `facewright:scan`,
`facewright:cluster <user>`, `facewright:cluster-scan`, `facewright:merge-clusters [--dry-run]`,
`facewright:backfill-access [--dry-run] [--prune]`, `facewright:compact-vectors [--dry-run]`,
`facewright:init-user-settings [--dry-run]`, `facewright:reset [--dry-run] [--yes]`,
`facewright:people <user> [--faces=<idOrTitle>] [--unassigned] [--ignored] [--delete-empty]
[--dry-run]` (inspection and light maintenance over `PeopleService`), and `--suggest
--faces=<idOrTitle> [--no-store]` (merge suggestions with elapsed time and peak memory; `--no-store`
writes nothing, for measuring against a database that must not change), `facewright:reopen-unassigned
[--dry-run] [--user=<uid>]` (one-time; see the `cluster_id` invariant below),
`facewright:migrate-faces [--dry-run] [--chunk=<files>] [--reset-cursor]` (Stage 1 backfill,
see "Schema mapping").
`facewright:own-tables --precheck | --migrate | --verify | --seed-ids` (the move into our own tables; `--migrate`
needs processing paused; `--verify` checks ours internally and Recognize's copy through the projection's reconcile;
`docs/own-tables.md`), `facewright:project [--dry-run] [--hydrate] [--user=<uid>] [--limit=<n>]` (report and repair the
copy Memories reads; `--hydrate` is the rollback).

## Invariants — read before touching the tables

### Schema mapping to Recognize's tables

- `x`, `y`, `width`, `height` are **normalized 0–1 fractions** (top-left + size), not pixels.
- **The table's physical column order is `x, y, height, width`** while we write
  `x, y, width, height`. Always name columns explicitly on both sides of any copy; a positional
  `INSERT ... SELECT` silently transposes every box.
- `face_vector` is a JSON **flat** array of floats, 512-dim for our rows, rounded to
  `VECTOR_PRECISION` (6) decimals — float32 data needs no more, and it keeps rows in-page
  (≈4.8 KB) instead of off-page (≈10.7 KB, one dedicated 16 KB InnoDB page per row). Must stay
  `json_decode`-able: Recognize's `FaceDetection` maps it as a JSON field.
  **The embedding of record is `facewright_faces.embedding`**; since Stage E new rows in Recognize's table carry
  `'[]'` (valid JSON, never decoded: the DAV router makes Recognize's `FacePhoto::delete()` unreachable), and older
  rows keep the vector they were written with until Stage F blanks them. `facewright_faces` holds one row per physical
  face in a file (`file_id` + `face_index`, geometry, `det_score`, a packed 512×float32-LE
  embedding via `FaceVectorCodec`) independent of how many users' `recognize_face_detections` rows
  point at it through fan-out; `facewright_detection_faces` links a detection row (`detection_id`
  = its `id`) to its physical face (`face_id`), with `user_id` denormalized for per-user cleanup
  queries. `ClusteringService` and `PeopleService` read embeddings only from `facewright_faces` —
  never `face_vector`.
- `cluster_id` in Recognize's table and in the API is the sentinel form of `FaceState`, the state of a row of
  `facewright_user_faces` (`FaceState::fromSentinel()` / `toSentinel()`): `NULL` pending, `> 0` assigned, `-1` unassigned,
  `-2` unclusterable, `-3` ignored. `js/people.js` reads the sentinels. Only `> 0` and `-1` are written to Recognize's
  table; the others are there only on rows from before Stage E and after `project --hydrate`.
- **Unassigned** means "excluded from clustering until a human assigns it": what deleting a person does to its members,
  what Memories' "unassigned faces" view lists, what "move to person" operates on. Clustering never seeds from it,
  never matches against it, and never assigns it as a side effect; `occ facewright:cluster --reset` is the one thing
  that reopens it, as part of a full reset. Recognize's own (now-disabled) HDBSCAN also wrote `-1` as its noise verdict,
  including on rows it had no business judging; those legacy rows were reopened once by `occ
  facewright:reopen-unassigned`, which remains safe to re-run (it can only touch rows a human has since unassigned).
- **Unclusterable** means "evaluated in a full pass, nothing matched". Such rows stay in the matching pool and can still
  be pulled into a cluster by a later face; they are just not re-attempted as seeds. It is `-2`, not `-1`, because
  Recognize's `sampleRejectedDetectionsByUserId()` feeds `-1` rows into its own clustering and has no predicate matching
  `-2`; Memories inner-joins to `recognize_face_clusters`, so `-2` is invisible there.
- **Ignored** means "not a face, or not someone to group", set only by a human from the People page. Clustering skips it
  like unassigned, and `resetUser()` keeps it (an ignore is a judgement about the detection, not about how it was
  clustered). Memories and Recognize never see it. It is lost when the detection rows are rewritten (forced `occ
  facewright:detect`, `occ facewright:reset`), a known limitation.
- `facewright_cluster_centroids` (one cached centroid per person, `member_count` marks staleness)
  and `facewright_merge_dismissals` (`cluster_a < cluster_b` always) reference
  person ids, which are autoincrement and never reused, so a row left behind is inert. Merge, delete, `resetUser()` and `ResetService`
  clear them; nothing else does.
- `threshold` is left at its DB default, matching Recognize's own insert.

### Our own tables, and Recognize's as a copy

`facewright_clusters` and `facewright_user_faces` (state `FaceState`: pending, assigned, unassigned, unclusterable,
ignored) are the source of truth for people and face assignments (`docs/own-tables.md`, Stage E). Recognize's
`recognize_face_clusters` / `_detections` are a **copy for Memories**, written only by `ProjectionService`.
**Live has run Stage E since 2026-11-06 (bridge 0.10.0); the rollback to Stage D is in that document. What is open next (day-after checks, Stage F not before 2026-11-20, Stage G) is at the top of that document.**

- **Writers write ours, commit, then push.** `FaceStore` is the only writer of the two tables; each method returns the
  ids it changed, inside the caller's transaction. After the commit the caller calls `ProjectionService::push($faceIds,
  $clusterIds)` once with everything it touched (`PeopleService` does this in `writeAndPush`). Never push inside a
  transaction; `push()` logs, flags the copy dirty and returns false instead of throwing there, and a failed push
  never reaches the caller. A new writer that skips the push leaves Memories stale until the hourly job or `occ
  facewright:project` repairs it.
- **The copy is sparse.** Only assigned and unassigned faces are in Recognize's table, as the person id or `-1`;
  pending, unclusterable and ignored faces are not (so we never write the `NULL` that arms Recognize's
  `MaintenanceJob`, and `-2` / `-3` never appear there except through `project --hydrate`, the rollback). Its
  `face_vector` is `'[]'` for rows we create and is never touched on rows that exist. The upsert is `INSERT ...
  SELECT ... ON DUPLICATE KEY UPDATE`; delete-then-insert would race and wipe real vectors. Push order: people that
  exist, then faces, then deleted people last.
- **Ids are shared** between a row of ours and its copy, and our counters are seeded above Recognize's
  (`occ facewright:own-tables --seed-ids`). They still meet: pushing an explicit id moves Recognize's counter past it,
  so its next row lands where our next id will. A visible face overwrites what it finds; on a hidden one it is a
  squatter and reconcile removes it.
- **Reconcile** (`occ facewright:project [--dry-run]`, hourly `ProjectionMaintenanceJob`) is how drift is repaired and
  how Recognize's own handlers are survived: A visible faces missing or different, B hidden-state rows left from before
  the cutover (tolerated until Stage F), C stale copies and squatters, D people missing or stale. It reads a vector
  only for rows with no row of ours. Recognize's `.noimage` deletion job, its access sync (which writes rows of its
  own) and even `occ recognize:reset-face-clusters` are recoverable this way; that is tested in `dev/`.
- **Reads use ours**: clustering, the People page and API, suggestions, face crops, access sync, reset, the commands.
  The one read of Recognize's table is the legacy-member test: a person that a row of Recognize's own with no row of
  ours still points at is never judged empty, so never deleted (`FaceStore::emptyClusterIds`). Merge and delete move
  such legacy rows with the person (`PeopleService::moveLegacyMembers`).
- **A read and the guard on the write that follows it must test the same table**, and a chain's progress must mean
  rows *written*, not matches found.
- The DAV router has no off switch. Rolling back is `occ facewright:project --hydrate` plus the Stage D files, with
  `info.xml`'s version kept at the installed one (a lower one makes Nextcloud demand an upgrade and take the site
  down); see "Rollback" in `docs/own-tables.md`.

### Telling our rows from Recognize's

A row of ours is a row of `facewright_user_faces`, and a row of Recognize's table is ours if it has the same id there;
embeddings live in `facewright_faces`, so clustering cannot meet another dimension. Recognize's table may also hold its
legacy 128-dim rows. **Never compute a distance across dimensions**, never delete from that table by `file_id` without
a dimension filter, and never sweep `WHERE cluster_id IS NULL` there without one.

A row there with no row of ours is told apart by `char_length(face_vector)`, without reading any row we own. The
encodings form disjoint bands: legacy 128-dim ≈ 2,600 chars (never touched), ours at precision 6 ≈ 4,800, ours
pre-compaction ≈ 10,700, and `'[]'` is 2. Above `DetectionService::VECTOR_LENGTH_FLOOR` (3500) or exactly 2 it is a stale
copy of ours, and reconcile removes it. If the encoding ever changes, re-measure the bands *before* changing the floor.
One known degenerate case: an all-zero embedding encodes short and falls below the floor (and fails
`FaceVectorCodec::decode()`'s length check the same way). `DetectionService::ownRowsExpr()` is what this was before
Stage E; it has no reader and goes in Stage F.

**Capping rows in PHP saves no I/O** — the rows have already been read. Restrict in the `SELECT`.
`ClusteringService::selectWantedIds()` therefore projects `id`/`cluster_id`/`face_id` first from
`facewright_user_faces` and `loadDetections()` fetches embeddings from
`facewright_faces` only for the face ids it wants, streaming with `fetch()` rather than
`fetchAll()`.

### Job semantics

- `IJobList::add()` dedupes on **class + JSON argument**. That is why `DetectQueuedJob`'s argument
  is `{fileId}` only (one job however many users' walks reach the file), why `BatchScanJob` can
  re-add itself every tick safely, and why `DetectionMaintenanceJob` can restart a chain without
  checking whether one is running. It does *not* help `ClusterScanJob`, whose argument evolves —
  that one guards against double-starting by inspecting pending jobs' arguments.
- A `QueuedJob` is removed when `run()` returns, whatever happened. So a paused job must re-add
  itself with its original argument (`processing_paused` is honoured this way by every job here),
  and every silent early return must log, or a dropped job is indistinguishable from one never
  enqueued.
- `DetectQueuedJob` re-checks everything at run time (paused, access, enabled users, included
  paths, mimetype, already processed) — the queue can outlive any setting. Enqueuing is
  therefore cheap and safe to do liberally; the job is the gate.
- Failure classification is load-bearing: a 4xx from the ExApp is a verdict on that file
  (permanent → marker with `FACES_FAILED`); transport errors, 5xx and malformed responses are
  retryable, bounded by `MAX_ATTEMPTS`. Marking transient failures permanent would skip every
  file touched during an outage forever. Files rejected by the mimetype gate are never marked, so
  adding a format later picks them up naturally.
- `ClusterScanJob`'s stop condition is "a full pass made no assignment", not "queue empty" —
  faces below `min-faces` never cluster, so the queue is never empty. Only at that point are
  survivors marked unclusterable, by explicit id list.
- `ClusteringService::persistAssignments()` writes only rows still pending or unclusterable, scoped to the user
  and only into clusters that still exist, and deletes clusters the pass created that ended up
  empty. A pass loads the pool, matches for seconds to minutes, then writes: anything a human did to
  those rows meanwhile (a move, a remove, an ignore, a delete or merge of the person) must win.
- Changing a user's thresholds calls `clearUnclusterableMarks()` (unclusterable back to pending) for that user,
  or the second change would appear to do nothing. This can reopen a very large number of rows.

### Multi-user fan-out

Recognize writes one row per user *with access*, and Memories expects that. The primitive is
`IUserMountCache::getMountsForFileId()` — shares, group folders and external storage all live in
`oc_mounts`, so no share-specific code exists (Recognize v12 has none either).
`ICachedMountFileInfo::getPath()` already gives each user's files-root-relative path.

- **Copy, never re-detect.** A file processed for anyone is copied to new users
  (as pending rows of theirs, so their own clustering picks them up). Zero-face
  *markers* are copied too — more than half of a real library is zero-face, and treating "no
  rows" as "not detected" would re-infer all of it on every share.
- **Paths gate scanning, not visibility.** A file is inferred if *any* enabled user with access
  has it under *their* included paths; rows still fan out to everyone with access. The fetch user
  must be one of those interested users (`pickFetchUser($restrictTo)`), because the ExApp
  downloads as that user and an excluded path would fail the download.
- Nothing is retroactive: shrinking a path list or disabling never deletes rows.
- Deletion is synchronous on `BeforeNodeDeletedEvent` (filecache is gone by the time a job would
  run), paged 1,000 files at a time. Deleting a large folder costs proportional latency.
- Names do not travel with a share: copies start unclustered, so recipients get their own
  untitled clusters for the same people.
- Traps in Recognize's equivalent code, deliberately not inherited: deleting by `file_id` with
  no dimension filter; `notIn('user_id', [])` rendering as `NOT IN (NULL)` (matches nothing —
  omit the clause instead); applying a mount root's access set to sub-shared folders inside it.
- `IDBConnection::insertOrUpdate()` does not exist in the public API; use
  `IDBConnection::setValues()` (returns 1 on insert, 0 on update). `IFunctionBuilder` has no
  `distinct()`; use `createFunction()`.

### Per-user settings

`OCP\Config\IUserConfig` (NC ≥ 32). `enabled` is stored as a **string with `FLAG_INDEXED`**
(`setValueBool` has no flags parameter), because `searchUsersByValueString` is how the app finds
enabled users. Array-typed keys cannot be searched by value; use `getValuesByUsers()` for the
admin overview (one query, not N+1). A user with no stored preference is off.

## Coexisting with Recognize

- `faces.enabled` must stay `'true'` (Memories reads it) but must be written **directly via
  `IAppConfig`**, never through Recognize's `SettingsService::setSetting()`, which also schedules
  Recognize's own full classifier crawl.
- `MaintenanceJob` / `ClusterFacesJob` are registered unconditionally, gated by no setting, and
  crash (multi-GB, `memory_limit=-1`) on 512-dim vectors. Writing no `NULL` to their table keeps users out of
  their trigger query, but Recognize's own classifier re-arms clustering whenever it writes
  detections, so job-table hygiene alone is not a fix.
- **The durable fix is Recognize's own ignore marker**: an empty `.noimage` at the root of each
  user's `files/`, honoured by all three of Recognize's enqueue paths. Facewright ignores these
  markers itself.
- **Installing or removing that marker is a mass action, not a flag.** Recognize's
  `FileListener` reacts to the marker's cache entry appearing by queueing an `FsDeletion` for
  **every file under the parent**, executed by deleting detection rows by `file_id` with no
  dimension filter — i.e. it deletes the copy of every face in those files. Removing the marker queues the whole tree for
  classification instead. Never let anything re-index a `.noimage` entry (including a routine
  `occ files:scan` over that tree); if unavoidable, watch `oc_recognize_fs_deletions` and clear it
  before `ProcessFsActionsJob` drains it. Our tables are not touched, so `occ facewright:project` (or the hourly job)
  restores the copy; that is tested on dev only, and Memories shows the faces as gone until it has run.
- Recognize's `IgnoreService` caches in APCu with no TTL, so after adding markers the web
  container needs a restart; cron (CLI) is unaffected.
- **Never run `occ recognize:reset-face-clusters`**: it deletes every user's clusters and sets every `cluster_id` in
  Recognize's table to `NULL`, with no user or dimension filter. Our tables are not touched and `occ facewright:project`
  restores what Memories shows (tested on dev only), but until then every person is gone from Memories and the `NULL`
  rows arm Recognize's clustering.
- After any Recognize upgrade or re-enable, `MaintenanceJob` is back in `oc_jobs` and due at the next cron
  tick. Delete it in the same command chain as the upgrade (`occ background-job:delete` asks for
  confirmation: pipe `y`), together with any `ClusterFacesJob` it already queued, and confirm the markers
  still take effect.

## Deployment and operations

### ExApp (`facewright`)

Deployed via a **manual-install** AppAPI daemon (the docker-install type does a real
`docker pull` and cannot use a locally built image). AppAPI expects us to run the container; it
tunnels in through HaRP's FRP reverse proxy. See `Makefile` (`daemon-register`, `build`, `run`,
`register`, `verify`, `bust-cache`) and `deploy.env.example`.

- Required env: `APP_ID`, `APP_VERSION`, `APP_SECRET`, `NEXTCLOUD_URL`, `APP_PORT` (the FRP
  `remotePort`, still needed under HaRP), `HP_SHARED_KEY` / `HP_FRP_ADDRESS` / `HP_FRP_PORT`.
- HaRP's FRP server requires TLS; mount HaRP's own client cert bundle at `/certs/frp:ro`.
- `APP_PORT` must fall inside HaRP's `frps.toml` `allowPorts` ranges; check the live file.
- HaRP's SPOE agent caches ExApp host/port with no TTL. If registration 503s after changing the
  port or re-registering, run `make bust-cache` before assuming anything else is wrong.
- Run with `--restart unless-stopped`: native crashes in ONNX Runtime/OpenCV leave no Python
  traceback and would otherwise stall detection silently.

### Bridge app (`facewright_bridge`)

Deployed by copying into the instance's `custom_apps` and `occ app:enable`; `git archive HEAD
facewright_bridge | docker exec -i -u www-data <app> tar -C <custom_apps> -x` copies only tracked files
with the right owner. Three mechanics
that are easy to get wrong:

- **Migrations and `info.xml` registrations (background jobs, sections, the navigation entry) need
  a `<version>` bump
  plus `occ upgrade`.** `occ migrations:*` commands are not available; the version bump is what
  makes Nextcloud run the app's pending migrations, exactly as an app-store update would.
- **`occ upgrade` also applies every pending App Store update** (Recognize and Memories included), and
  a Recognize update can change its write paths. Check `occ app:update --showonly` first. For a
  command-only change, copy the files without bumping `<version>` and nothing needs upgrading.
- **JS changes need a version bump too**: the `?v=` cache-buster is `substr(md5($appVersion),0,8)`,
  so browsers keep the old `admin.js` until the version changes. PHP templates and services do
  not need this (opcache revalidates on a timer).
- **New `routes.php` entries need a container restart** to resolve (they 404 otherwise, with
  everything looking correct in source).

Large data rewrites belong in resumable `occ` commands, not migrations — `occ upgrade`'s
maintenance window is the wrong place for gigabytes of `UPDATE`s.

### Database

- `docker-compose.yml` pins the dev database to the production major (MariaDB 10.11), so SQL only
  newer versions accept fails in dev first. Keep them in step.
- A single-table `DELETE` cannot take an alias before MariaDB 11.6 (error 1064). To correlate a
  subquery with the row being deleted, use `$qb->getTableName(...) . '.id'`.
- MariaDB has no in-place downgrade: moving a volume to an older version means dump and reload.
  Dump the one database, not `--all-databases` (the older image creates its own system tables and
  users). `utf8mb4_uca1400_ai_ci` does not exist on 10.11: a dump from 11.x can carry it in trigger
  metadata (Memories' `memories_fcu_trg`) and in `CREATE DATABASE`, and fails to load.
- `oc_recognize_face_detections` dominates the instance's working set. Size InnoDB's buffer pool
  so the table fits, or every clustering pass evicts everyone else's cache.
- Off-page BLOB reads cannot be batched by read-ahead; keeping vectors in-page (the precision
  rounding) is what made the clustering fetch cheap. `occ facewright:compact-vectors` converts
  a pre-existing table.
- `information_schema.TABLES.table_rows` is an estimate; use `COUNT(*)` when it matters.
- MariaDB RSS is not MariaDB's memory use: compare against `Memory_used_initial`, and cap
  glibc arenas (`MALLOC_ARENA_MAX`) before blaming a workload.
- A typical Nextcloud DB user lacks `PROCESS` and `SUPER`: `INNODB_TRX` returns access-denied
  (easy to misread as "no transactions"; use `PROCESSLIST`) and `SET GLOBAL` is unavailable.
- Nextcloud app uninstalls do not drop tables, and `app:remove` leaves `oc_appconfig` /
  `oc_migrations` rows behind. Legacy rows from long-uninstalled apps can be sitting in shared
  tables; that is how 128-dim rows got into this project's data in the first place.

### Filesystem scans

- `occ files:scan` over a large home storage holds transactions open long enough to block
  filecache inserts instance-wide; scan a specific path instead.
- Killed scans leave locks in Redis (`<instanceid>/lockfiles/md5("<storage>::scanner::<path>")`,
  ~1 h TTL); they can be computed and deleted individually.
- `pkill` inside the container may silently fail; verify with `ps` and use `kill -9`.

### Host

After an unclean reboot, Docker may build containers' `resolv.conf` before DHCP populated the
host's nameservers, leaving no upstream DNS. Symptom: every detection fails with
`Could not resolve host`, silently. Compare `docker run --rm alpine cat /etc/resolv.conf` with
the running containers' and restart the affected ones; the durable fix is pinning `dns` in
`/etc/docker/daemon.json`.

## Working practices that have paid for themselves

- Rehearse destructive changes inside a transaction that is rolled back, and print a
  before/after count pair that must reconcile exactly — a mismatch means another session wrote
  concurrently, which has caught a deletion in progress.
- Cross-check a `--dry-run` against direct SQL before the first real write; nested mounts have
  inflated a dry-run count by double-counting.
- Dump the exact id list before deleting by it; delete by that list, not by re-running the
  selecting query.
- Count `POST /detect` in the ExApp's logs as ground truth for "did inference run" — a marker
  alone cannot distinguish inference from a fan-out copy.
- Verify authorization through `ControllerMethodReflector`, not by observing that a page works
  for an admin account.

## Open items

1. **Share/unshare at runtime is built but only exercised by command**, never by a real share
   created in the browser. `PruneService` has never actually deleted. Empty-cluster cleanup after a
   prune, a user-deleted listener, and a resume cursor for `BatchTriggerService`'s O(processed)
   re-walk (memory is bounded by paging now, but every pass still reads the whole library, and
   paging made that walk several times slower) are all still open.
2. **Clustering quality is unvalidated at scale.** The 0.5 / 3 defaults were validated by eye on
   ~740 detections; real libraries are two orders of magnitude larger, and a couple of very large
   clusters look like over-merging. A hand-labelled ground-truth set exists outside the repo.
3. **Admin-side threshold changes re-evaluate nothing for users who set their own**; only the
   personal page triggers `clearUnclusterableMarks()`. `occ facewright:cluster --reset` remains
   the blunt option.
4. **Three known bugs**: `ex_app/lib/main.py` runs `FACE_ANALYSIS.get()` unguarded and
   synchronously on the event loop (a crash reads as retryable, and `/heartbeat` stalls during
   inference); `DetectQueuedJob`'s zero-byte branch marks *every* user with access, not just
   `$pending`; `ProcessedFileService::candidateFileIds()` lacks the `SUPPORTED_MIMETYPES` filter,
   so the "not yet processed" count can never reach zero.
5. **No tests, static analysis, or CI** — and the People page has only been exercised by hand in
   headless Chromium on the compose stack, never against a production-sized library. The pure-logic pieces (`ClusteringService`'s distance
   math, `AccessService::pickFetchUser`, `PathFilterService::isIncluded`) are
   unit-testable without an instance and have zero coverage. Integration checks against the compose stack live in
   `dev/` (see `dev/README.md`; `dev/run-all.sh`). They prove that writers and readers agree, not that the pure logic
   is right.
6. **Row-at-a-time SQL** in `DetectionService::detect()`'s insert loop (now two inserts per user
   per face, plus a per-face read-back query for `facewright_faces.id` — `IDBConnection::
   setValues()` has no id-returning mode), `AccessSyncService::copyToUsers()` and
   `ClusteringService::persistAssignments()`.

Option C — vectors in a table we own, keyed by physical face — is done: see `facewright_faces` /
`facewright_detection_faces` in "Schema mapping" and Stage 1 of
`docs/independence-implementation.md`. The face-identity key is `(file_id, face_index)`, as
recommended.

## Licensing note

Both apps are AGPL-3.0-or-later (root `LICENSE`). `facewright_bridge` never imports or links
Recognize's PHP; it writes into Recognize's tables via Nextcloud's `IDBConnection`/`QueryBuilder`
knowing only the schema, which is interoperability rather than incorporation — a reasonable
reading, not legal advice. AGPL was chosen regardless: Nextcloud itself is AGPLv3 and the App
Store requires an AGPL-compatible license, so matching keeps that door open.

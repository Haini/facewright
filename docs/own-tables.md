# Owning the face data: review and implementation plan

> ## Where things stand (updated 2026-11-06, evening) — read this first
>
> **Stages A to E are done and live.** Live (`<app>`) runs bridge 0.10.0: our tables are the source of
> truth and Recognize's tables are a copy written by `ProjectionService`. The cutover passed every gate and the user
> confirmed in Memories that rename, move and remove-face edits work and show at once. Branch `docs/own-tables`
> holds everything (not merged to `master`). Rollback source: `<backups>/facewright-pre-stage-e/` (see "Rollback").
>
> **What is open, in order** (nothing below is started; each destructive one needs the user's go-ahead):
> 1. **Day-after check (from 2026-11-07).** On live, `docker exec -u www-data <app> php occ facewright:project
>    --dry-run` must show A = 0, C = 0, D = 0 (B stays 44,858 until faces change, legacy 40) and
>    `occ facewright:own-tables --verify` must exit 0. The log (`grep -i facewright /var/www/html/data/nextcloud.log`,
>    ignoring the 20:26 upgrade-window lines of 2026-11-06) must hold nothing of ours. Do this after a clustering run
>    and after any share has happened; if neither has occurred yet, say so rather than calling it clean. Also open the
>    admin page and confirm the "Memories sync" line says up to date (never looked at in a browser).
> 2. **Anything non-zero in A, C or D:** do not repair blindly. Run `project` (it repairs), then find which writer
>    left the drift (the push logs and marks the copy dirty; the hourly `ProjectionMaintenanceJob` also repairs).
>    A repeating drift is a missed writer: fix the writer. Rollback tiers R1 to R3 are below.
> 3. **Stage F** (delete the 44,858 hidden rows, blank vectors, drop transitional code) waits at least two weeks after
>    the cutover, i.e. not before 2026-11-20, and needs the user's explicit go-ahead.
> 4. **Stage G** (the Memories pull request) posts publicly: only with the user's go-ahead.
>
> **Verification tooling** is under `dev/` (`dev/README.md`; `dev/run-all.sh --ui` on the compose stack, which takes a
> lock because the dev DB is shared). Last full run: 15 of 16, the remaining one (rollback drill) fixed afterwards.
> The ground-truth clustering test (`dev/clustering-truth.sh`, 50 people of 15 faces from the Georgia Tech set, which
> is fetched, never committed) passed 15/15.
>
> **Things that bit and will again:** Apache's opcache revalidates every 60 s (`reload_web` after editing source in
> tests); `docker exec -i` is needed for piped stdin; `occ upgrade` applies every pending store update (check
> `occ app:update --showonly`) and Recognize's `MaintenanceJob` can come back after it (delete it in the same chain,
> pipe `y`); a rollback must keep `info.xml`'s version at the installed one.

An executable plan for moving all face and people data into tables we own. Written for a model
session that has not seen this repository before. Part 1 is the review and the design; Part 2 is the
work, stage by stage. Where they differ, Part 2 wins. Where this plan differs from
`docs/independence.md` or `docs/independence-implementation.md`, this plan wins (see "Deviations").

---

# Part 1: Review and design

## Goal

- All face and people data lives in `facewright_*` tables, controlled by us alone.
- **End state:** Memories gains a way to read people from our tables and write through our API, via
  an upstream pull request. No push is needed then.
- **Until then:** a temporary one-way push keeps `oc_recognize_face_detections` and
  `oc_recognize_face_clusters` up to date for Memories. Memories' and Photos' people edits are
  answered by this app (a DAV router in front of Recognize's tree), so they reach the same code as the
  People page. Real Recognize stays installed, its classifier off.
- If upstream refuses, the temporary design stays. It must be good enough to live with.

## Decisions (made with the user, 2026-11-06)

- **Memories PR is the target.** The push is a bridge to it, not the end state.
- **Memories' people edits are routed, not refused** (changed 2026-11-06, after the first version of
  Stage B refused them). A Sabre plugin turns the five requests Memories sends into `PeopleService`
  calls. The cost is a deeper coupling than a refusal has: the path shapes, the `Destination` header and
  the status codes. Anything it does not recognise is still refused, never passed to Recognize.
- **The Stage 2 shim is not planned.** Real Recognize stays passive.

Conventions: `rfd` = `oc_recognize_face_detections`, `rfc` = `oc_recognize_face_clusters`. Paths are
relative to `facewright_bridge/` unless they start with `docs/` or `/mnt/`.

## Current table usage

Verified against the working tree at `1ef8cc6`.

| Table | Holds | Owner | Fate |
|---|---|---|---|
| `facewright_faces` | physical face, geometry, `det_score`, embedding | us | keep |
| `facewright_processed` | per-user idempotency marker | us | keep |
| `facewright_cluster_centroids` | cached centroid per person | us | keep, ids stay valid |
| `facewright_merge_dismissals` | dismissed merge pairs | us | keep, ids stay valid |
| `facewright_detection_faces` | `rfd.id` to `facewright_faces.id` link | us | replaced |
| `rfd` | per-user row, `cluster_id` state, duplicated geometry, `face_vector`, `threshold` | Recognize | becomes a projection |
| `rfc` | people, titles | Recognize | becomes a projection |
| `memories_covers` | a detection id per cluster | Memories | read only by Memories; our ids must stay stable |
| `filecache`, mounts | enumeration and access | core | read-only, stays |
| appconfig `recognize/faces.enabled` | Memories' gate | Recognize | stays until the PR ships |

References to `recognize_face_*` by file (`grep -c`, comments included): `PeopleService` 25,
`ClusteringService` 16, `DetectionService` 8, `AccessSyncService` 8, `ResetService` 4,
`FaceMigrationService` 4, `VectorCompactionService` 3, `Command/ResetFacewrightData` 2,
`Command/ReopenUnassigned` 2, and 1 each in `SuggestionService`, `FacePreviewController` and
`Command/ClusterFaces`. There are none in `js/` or `templates/`. However, `js/people.js:566-661`
reads the sentinels from `face.cluster_id`, so the API must keep emitting them.

## What else writes to `rfd` and `rfc`

Code read from Recognize 12.0.2 and Memories 10.1.0 (the live versions, under
`<apps-dir>/`). Behaviour observed on the dev stack, which runs Recognize 12.0.3 and
Memories 11.0.2 (Stage A.2). **The two Recognize versions differ**, so what live does after a Recognize
update changes:

| | 12.0.2 (live) | 12.0.3 (dev, observed) |
|---|---|---|
| Move | `FsMove` carries owner, added users, target users; `onMove` copies to added users and deletes for everyone else | `FsMove` carries only the node id (a migration drops the three columns); a move on its own changed nothing |
| Access update | uses the mount root's user list for every file in the mount, so a sub-shared folder can lose its rows | resolves access per file (`syncDetectionsForFile`) |
| Copy | `copyDetectionsForFileFromUserToUser` | same, from whichever user already holds rows |
| Delete | `removeDetectionsForFileFromUsersNotInList`, by `file_id`, no ownership filter | same |
| DAV nodes | not scoped to the calling user | scoped; `findByFileIdWithTitle` takes a user id |

| Writer | Effect | Stopped by `.noimage`? |
|---|---|---|
| `Dav/Faces/FaceRoot::setName`, `delete`, `moveInto` | rename, delete person, move face | no |
| `Dav/Faces/FacePhoto::delete` | remove face: `NULL`, plus a whole-cluster vector read | no |
| `Dav/Faces/FacesHome::createDirectory` | create person | no |
| `Service/FsActionService` access update | **observed:** copies every row of a file to each user who gained access, whatever its state (the `-1` and `-3` rows too), with `cluster_id` NULL, `face_vector` copied and **no `facewright_detection_faces` link**; queues `ClusterFacesJob` for the recipient; deletes by `file_id` for users without access | only where the file's storage has a marker (`StorageService::getFilesInMount`) |
| `Service/FsActionService` move | no change on 12.0.3 without a share; 12.0.2 copies and deletes (code only) | `FileListener` has partial ignored-territory checks |
| `Service/FsActionService::onDeletion` | **observed:** after a `.noimage` appeared, one queued deletion removed the file's rows for every user. The links stayed behind and `facewright_faces` survived, so nothing was lost beyond the assignments | no; this is the `.noimage` mass-delete trap |
| `ClusterFacesJob` | HDBSCAN writes; fails on 512-dim rows | armed by NULL rows (`MaintenanceJob`) or directly by the access-update handler |
| `occ recognize:reset-face-clusters`, `recognize:reset-faces`, admin reset | wipe everything | no |

The access-update event does not fire when a share is created. Recognize queues the update when the
recipient's mounts are first registered, which is their first request after the share.
`ProcessFsActionsJob` has no settings gate. Not tested: group folders (the app is not on dev) and
external storage, which have no marker at a user root and are the likely exposure on live.

Consequence for the design: rows Recognize creates are unlinked copies of ours. The reconcile query for
stale rows (E.2) matches them by vector length, which holds for both a real vector (over 3500) and the
blanked `'[]'` (length 2). Removing them is correct, because they have no visible own row.

`docs/independence.md` does not list the `FsActionService` paths.

**Memories' five writes** (`js/memories-main.js`, 10.1.0): `DELETE recognize/{u}/faces/{p}/{id}-{name}`,
`DELETE recognize/{u}/faces/{p}`, `MKCOL recognize/{u}/faces/{p}`, `MOVE` rename on `faces/{p}`, and
`MOVE` of a face from `faces/{p}` or `unassigned-faces`. Merging moves each face in turn.

**Memories' read set** (`lib/ClustersBackend/RecognizeBackend.php`): `rfc.id, user_id, title`;
`rfd.id, cluster_id, file_id, x, y, width, height`. It inner-joins `memories` on `fileid`, and
`rfd.cluster_id = -1` is the unassigned view. Memories never reads `face_vector` or `threshold`.
`FaceDetection` maps `face_vector` as a JSON field, so the column must stay valid JSON.

`memories_covers` stores `rfd.id` in `objectid`. An invalid cover is ignored because `selectCover`
checks that the object still belongs to the cluster, so a stale cover falls back rather than breaks.

## Target data model

**`facewright_clusters`**: one row per person.

| column | type | notes |
|---|---|---|
| `id` | bigint, autoincrement, PK `fw_clusters_pk` | preserved from `rfc.id` |
| `user_id` | string(64), notnull | |
| `title` | string(4000), notnull, default `''` | same length as `rfc.title`; uniqueness stays in code |

Index: (`user_id`) `fw_clusters_user`.

**`facewright_user_faces`**: one row per (user, physical face).

| column | type | notes |
|---|---|---|
| `id` | bigint, autoincrement, PK `fw_ufaces_pk` | preserved from `rfd.id` |
| `user_id` | string(64), notnull | |
| `face_id` | bigint, notnull | `facewright_faces.id` |
| `cluster_id` | bigint, nullable | set if and only if `state` is `ASSIGNED` |
| `state` | smallint, notnull, default 0 | see below |

Indexes: unique (`user_id`, `face_id`) `fw_ufaces_user_face`; (`user_id`, `cluster_id`)
`fw_ufaces_user_cluster`; (`user_id`, `state`) `fw_ufaces_user_state`; (`face_id`) `fw_ufaces_face`.
No foreign keys, like every other table of ours.

**States** (`lib/Service/FaceState.php`, a class of constants plus two mappers):

| constant | value | sentinel today | visible to Memories |
|---|---|---|---|
| `PENDING` | 0 | `NULL` | no |
| `ASSIGNED` | 1 | `> 0` | yes, `cluster_id` |
| `UNASSIGNED` | 2 | `-1` | yes, `-1` |
| `UNCLUSTERABLE` | 3 | `-2` | no |
| `IGNORED` | 4 | `-3` | no |

### One id space

Own ids equal projected ids. So `facewright_cluster_centroids`, `facewright_merge_dismissals`,
`memories_covers`, the OCS API and the People page URLs all stay valid, and no mapping table exists.
Backfilled rows keep their ids. Stage E seeds the own autoincrement counters above `max(rfd.id)` and
`max(rfc.id)`. A foreign row that later occupies an id we allocate is overwritten; by the projection's
rule, unknown rows have no claim on those tables. This is MariaDB-only: PostgreSQL does not advance a
sequence on explicit inserts. Record that as a limitation, consistent with the rest of the project
targeting MariaDB.

## The temporary projection

`ProjectionService` makes `rfd` and `rfc` match our tables:

- **Sparse.** Only `ASSIGNED` and `UNASSIGNED` faces are projected. We then never write a NULL
  `cluster_id`, which removes our part in arming `MaintenanceJob`, and the sentinels `-2` and `-3` never
  appear in Recognize's table.
- **`face_vector` is `'[]'`.** Valid JSON, never decoded by Memories. It is safe only because the write
  guard makes `FacePhoto::delete()`, the one reader that needs a real vector, unreachable.
- **One write shape.** In a transaction per chunk: delete by id list, then `INSERT ... SELECT` from our
  tables for those ids, restricted to visible states. That covers insert, update and removal with one
  code path. A row that left the visible set simply is not re-inserted.
- **In-process, after commit.** Same database, so no queue or outbox is needed. The caller commits
  its own transaction first, then pushes.
- **Failure isolation.** A push failure is logged and must not fail the caller's write: a Recognize
  schema change must not break the People page. A failure sets a flag that the maintenance job acts on.
- **Reconcile.** An hourly job repairs drift: our tables always win. This also covers what the
  `FsActionService` handlers do.

Bonus: `occ recognize:reset-face-clusters` or a `.noimage` mass deletion becomes recoverable by
re-projecting, instead of losing curation or forcing a rescan.

## Known limits of this design

- The router depends on the path shapes, the `Destination` header and the status codes of Memories'
  and Recognize's DAV calls. A release that changes them makes requests stop matching, and they are
  refused with an error. That lasts as long as the bridge, which has no date.
- Memories 11.0.2 offers no way to create a person from its UI, so the create route (`MKCOL`) is only
  exercised by other clients.
- Recognize can still queue `ClusterFacesJob` directly from its `FsActionService` handlers. Reconcile
  removes the rows afterwards but cannot stop the job running.
- Photos' People view uses the same tree, so its edits go through the router too. Not tested.
- Upstream's reaction to the PR is unknown.

---

# Part 2: Implementation

## How to work this plan

1. **Read `CLAUDE.md` first, in full.** The "Invariants" section is not optional. Column order, job
   dedup semantics and `persistAssignments()`'s guard are all touched here.
2. **Work on the compose stack only.** `recognaize-nextcloud-1`, `recognaize-db-1`. The production
   instance (`<app>`, `<db>`, `<apps-dir>/`) is reference material:
   the stages marked **[user runs]** were handed to the user, and the live deploys of Stages C to E were done
   with the user's go-ahead for each. Read-only checks on live (`project --dry-run`, `own-tables --verify`,
   the log) are fine; anything that writes, deploys or upgrades needs the user's explicit go-ahead first.
3. **One commit per numbered step**, imperative subject, a body that says why, ending with the
   attribution line the session gives you.
4. **Stop and report rather than improvise** when a count pair does not reconcile, a `--verify` reports
   a mismatch, or a construct in this plan does not exist in `OCP\DB` on the running version.
5. **Deploy mechanics** (from CLAUDE.md, repeated because every stage needs them):
   - Migrations, new commands and new background jobs need `<version>` bumped in `appinfo/info.xml` plus
     `occ upgrade`.
   - Any `js/*.js` change needs the same bump (the cache-buster is `md5($appVersion)`).
   - A new `appinfo/routes.php` entry needs the web container restarted.
6. **Do not touch** `ex_app/`, `Dockerfile`, `Makefile`, `start.sh` or `requirements.txt`. Do not add a
   test framework. Do not change the clustering algorithm or its thresholds. Do not start
   `docs/people-ui-review.md` or `docs/contact-names.md` work inside this plan.
7. **No destructive step without its gate.** Rehearse inside a rolled-back transaction where the plan
   says so, dump id lists before deleting, and print before/after counts that must reconcile.
8. MariaDB before 11.6 rejects an alias on a single-table `DELETE`, and dev is pinned to 10.11 for that
   reason. Correlate with `$qb->getTableName(...) . '.id'`.
9. **How to test** (learned in Stages A to D; Stage E turns these into scripts under `dev/`, see E.0):
   - Capture the outputs of every read path on the *old* code, prove the capture deterministic (two captures
     identical), change the code, capture again, and diff. Error-free output is not evidence.
   - Look at the output of every test, not its exit code. Three tests in this project passed without testing
     anything (an out-of-range API parameter, a loop that did not split its input, a setup call that had failed).
   - Every test asserts its own precondition: the rows it needs exist, and something actually changed.
   - Plant a failure to prove each checker can fail, then repair it.
   - Dev has no dangling, legacy or oddly shaped rows. Before a change reaches live, list what live has that dev
     does not (the precheck's informational counts) and decide how each would behave.

Each stage is deployable and useful alone. Stop at a stage boundary and report.

---

## Stage A: Prerequisites

**Goal.** A dev stack that matches production, proof of the Recognize writers, and the numbers that
decide the rest.

### A.1 Bring the dev stack to the production versions

Production runs Nextcloud 34.0.5, Memories 10.1.0 and Recognize 12.0.2. Dev runs 33, 8.1.0 and 11.0.1.
Memories 10.1.0 and Recognize 12.0.2 both declare `min-version="34"`, so the base image must move first.

- `docker-compose.yml`: change both `nextcloud:33-apache` images to `nextcloud:34-apache`. Keep
  `mariadb:10.11`.
- `dev-setup.sh`: update the comment that names NC 33. No logic change.
- `docker compose up -d`, then `occ upgrade`, then `occ app:update --all`.
- If the running bridge's data does not survive, `docker compose down -v` and `bash dev-setup.sh` is
  acceptable: the dev database only holds synthetic data.

**Verify:** `occ --version` shows 34; `occ app:list` shows Memories 10.1.0 and Recognize 12.0.2 (a newer
compatible release is acceptable: report it). Then index test photos with `occ memories:index`: the dev
stack has had no Memories-indexed photos, and every Memories check below needs them. The People page
in Memories must list people before you continue.

**Done (2026-11-06).** Dev is on Nextcloud 34.0.5, but the App Store gave **Memories 11.0.2 and
Recognize 12.0.3**, newer than live's 10.1.0 and 12.0.2. The plan's Memories claims were rechecked on
11.0.2 (fixed `BACKENDS` constant, same DAV writes). Recognize 12.0.3 behaves differently from 12.0.2,
see "What else writes". Two caveats:

- The Memories 11.0.2 migration `900002Date20260920233813` failed with "Duplicate key name
  `memories_parent_mc_fid_idx`": the index already existed although the migration was not recorded, so
  its `hasIndex` guard was not enough. Dropping that index on the dev database and re-running
  `occ upgrade` fixed it. Expect the same on any instance that upgrades Memories across that migration
  after an interrupted run.
- Memories 11.0.2 on Nextcloud 34 lists 297 people for the admin user through its Recognize backend
  (`GET /apps/memories/api/clusters/recognize`, 25 indexed files, 5 of them with faces). For that,
  `recognize/faces.enabled` was set to `true` with `occ config:app:set` and the user's `memories`
  `timelinePath` to `/`; both were unset on dev. Checked at API level only, not in a browser.

### A.2 Reproduce the `FsActionService` writes

No code. On the dev stack, as a throwaway experiment, with Recognize enabled and a `.noimage` at each
user's root as in production:

1. Note `rfd` count for a clustered file.
2. Share a folder containing it to a second user; run cron until `oc_recognize_fs_access_updates` is empty.
3. Repeat in a location without a marker: a group folder if available, otherwise a folder owned by a user
   with no marker.
4. Move a file between folders; run cron; compare.

Record, for each case: were rows copied or deleted, and was `ClusterFacesJob` queued
(`occ background-job:list`). Add the results to "What else writes" in this doc, replacing "Not reproduced".
Do not change the plan's direction on the result: reconcile handles all outcomes.

**Done (2026-11-06), on Recognize 12.0.3.** Method: a test file with three clustered rows, a folder
shared to a second user, `occ background-job:execute --force-execute` for each queued Recognize job, and
row counts before and after. Results are in "What else writes". Dev was restored from a dump taken
beforehand. Not covered: 12.0.2's move path (its `fs_moves` columns no longer exist after the 12.0.3
migration, so it cannot run on this database), group folders and external storage.

### A.3 Add `occ facewright:own-tables --precheck`

New `lib/Command/OwnTables.php`, registered in `appinfo/info.xml`, new `lib/Service/OwnTablesMigrationService.php`.
Read-only. Prints the numbers Stage C depends on:

- `rfd` total; rows with a `facewright_detection_faces` link; rows without one, split into
  `char_length(face_vector) > 3500` (unbackfilled, must be 0) and the rest (legacy or degenerate).
- Linked rows whose `l.user_id` differs from `d.user_id` (must be 0).
- **Duplicates that would break the unique index:**
  `SELECT user_id, face_id, COUNT(*) FROM facewright_detection_faces GROUP BY user_id, face_id HAVING COUNT(*) > 1`.
  `FaceMigrationService` grouped backfilled rows by geometry per file with no per-user uniqueness, so
  this cannot be assumed to be empty.
- Linked rows with a positive `cluster_id` missing from `rfc` (dangling).
- `rfc` rows referenced by no linked row, split into titled and untitled.
- Links whose `face_id` is missing from `facewright_faces` (must be 0).

**Verify:** the numbers match direct SQL on dev.

**Done (2026-11-06).** As built, the command prints these names (the four marked `must be 0` fail the
run): `detections_total`, `detections_linked`, `unlinked_long_rows` (must be 0), `unlinked_other_rows`,
`link_user_mismatch` (must be 0), `links_without_detection`, `links_missing_face` (must be 0),
`duplicate_user_face_groups`, `duplicate_user_face_extra_rows` (must be 0), `dangling_cluster_rows`,
`people_total`, `people_without_members_titled`, `people_without_members_untitled`.
`links_without_detection` is new: A.2 showed that a Recognize deletion leaves links behind. On dev every
number matched independent SQL, and a planted duplicate, an orphan link and a link to a missing face
were all caught (then removed). Bridge version is 0.8.1.

### A.4 **[user runs]** Precheck on production, and a backup

Stop here and ask the user to deploy A.3 and run `occ facewright:own-tables --precheck`, and to take a
dump of the face tables (`<backups>/` holds the previous ones). Record the numbers in the commit that
follows. If duplicates exist, stop and report them with their row details: which row wins is the user's
decision.

**Done (2026-11-06).** Backup in `<backups>/facewright-pre-own-tables/`: `face-tables.sql.gz` (the eight
face-related tables, `--single-transaction`) and `bridge-0.8.0.tgz`. The bridge files were copied to live
**without running `occ upgrade`**: `occ app:update --showonly` on live lists a dozen pending store updates,
among them Memories 11.0.2 and Recognize 12.0.3, and `occ upgrade` applies them. So the live copy's
`info.xml` was set back to 0.8.0, which leaves no pending upgrade and registers the command. The next real
deploy (any stage with a migration, C.1 first) needs a version bump and therefore an upgrade: decide then,
with the user, whether to hold the store updates back (`appstoreenabled=false` for the duration is the
thing to try on dev first; it is untested).

The precheck ran in about five seconds and every must-be-zero count was zero, confirmed by independent
SQL: no unbackfilled rows, no user mismatches, no duplicate (user, face) pairs, no links to missing faces.
Informational: a few dozen unlinked rows (Recognize's legacy rows and the degenerate all-zero one) and
fifteen rows assigned to one person who no longer exists; the backfill reopens those as pending. The
exact numbers are in the commit message that records this step. **Stage B can start.**

---

## Stage B: Take over Memories' writes

**Goal.** One write path. Without this, dual-write in Stage C would miss every Memories edit.
Useful alone: "remove face" can no longer be undone by clustering, because it now writes `-1` through
`PeopleService`, the same as the People page, instead of Recognize's `NULL`.

**What changed during the work.** The first version only refused the writes (a 403 guard). Testing it
through Memories' UI showed what that costs: every edit fails, and a merge shows one error toast per
face. So the plugin became a router: the same hook, but the five requests are applied through
`PeopleService`. The guard remains as the fallback for every shape it does not recognise. B.1 below is
the original guard design, kept for its rationale; "As built" further down is what exists.

### B.1 `RecognizeWriteGuardPlugin` (superseded by the router, see "As built")

New `lib/Dav/RecognizeWriteRouterPlugin.php`:

```php
class RecognizeWriteRouterPlugin extends \Sabre\DAV\ServerPlugin {
	private const REFUSED = ['MOVE', 'DELETE', 'MKCOL', 'COPY', 'PUT', 'PROPPATCH'];
	public function __construct(private IAppConfig $appConfig) {}
	public function initialize(Server $server): void {
		$server->on('beforeMethod:*', [$this, 'guard'], 20);
	}
	public function guard(RequestInterface $request, ResponseInterface $response): void {
		// Same path test as Recognize's own PropFindPlugin::beforeMethod(), stricter by one slash.
		if (!str_starts_with($request->getPath(), 'recognize/')) { return; }
		if (!in_array($request->getMethod(), self::REFUSED, true)) { return; }
		if ($this->appConfig->getValueString('facewright_bridge', 'block_recognize_writes', 'true') !== 'true') { return; }
		throw new \Sabre\DAV\Exception\Forbidden('People are managed on the Facewright People page.');
	}
}
```

Priority 20 runs after the auth plugin (10). Reads are never affected.

New `lib/Listener/SabrePluginListener.php` implements `IEventListener` for
`OCA\DAV\Events\SabrePluginAddEvent`, calling `$event->getServer()->addPlugin(...)`. Register it in
`Application::register()` with `registerEventListener`, like the existing listeners. Do not add `<types>`
to `info.xml` unless the verification below fails: `register()` already runs for every enabled app, and if
you do need it, follow the element order in Recognize's `info.xml`.

Bump `<version>`.

**Verify:** from inside the container, with a valid API key or `require_api_key` off on dev:
- `curl -u admin:dev-admin-password -X DELETE http://localhost/remote.php/dav/recognize/admin/faces/<id>` answers 403.
- `PROPFIND` on `recognize/admin/faces` still answers 207.
- `DELETE` on `files/admin/<a test file>` still works.
- `occ config:app:set facewright_bridge block_recognize_writes --value=false` restores Recognize's behaviour.

### B.2 Check Memories' behaviour under refusal

No code. In a browser on the dev stack, try all five Memories writes. Each must fail with an error, leave
`rfd` and `rfc` unchanged and leave Memories usable. Photos and the People list must still render.
Record what the user sees for each one in the commit message, and in "Known limits".

If any action leaves Memories broken rather than showing an error, stop and report.

### B.3 Say so in the UI

- `templates/admin.php`: one line under the Memories toggle saying edits made in Memories are applied
  through this app.
- CLAUDE.md: note in "Why Recognize cannot simply be uninstalled" that writes are routed.

**As built (2026-11-06, dev only).** `lib/Dav/RecognizeWriteRouterPlugin.php`,
`lib/Listener/SabrePluginListener.php` (adds it while Sabre is being set up), registered in
`Application::register()`, plus `PeopleService::faceAssignment()`. Deviations from the plan above:
`<version>` was **not** bumped (nothing in `info.xml`, JS or the schema changed, and a bump forces an
`occ upgrade` on live with its store updates); the plugin routes instead of refusing; `LOCK` is refused
along with `COPY`, `PUT` and `PROPPATCH`.

How it works: `beforeMethod:*` (priority 20, after authentication) refuses `COPY`, `PUT`, `PROPPATCH`,
`LOCK`, and any `MKCOL`/`MOVE`/`DELETE` whose shape it does not know. For the shapes it knows, a
`method:*` handler (priority 90, ahead of Sabre's own at 100) calls `PeopleService`, sets the status and
returns false so Recognize's code never runs. The user id in the path must equal the logged-in user.

| Request | Becomes | Status |
|---|---|---|
| `MKCOL faces/{name}` | create person; a duplicate or a purely numeric name is 403 | 201 |
| `MOVE faces/{p}` to `faces/{new}` | rename; onto an existing name 403, unknown person 404 | 201 |
| `DELETE faces/{p}` | delete person, faces become unassigned | 204 |
| `DELETE faces/{p}/{id}-{file}` | take the face out of the person (unassigned, and it stays) | 204 |
| `MOVE faces/{p}/{id}-{file}` or `unassigned-faces/{id}-{file}` to `faces/{q}/{id}-{file}` | move the face; 404 if it is not where the path says | 201 |
| `DELETE unassigned-faces/{id}-{file}` | refused, as Recognize itself does | 403 |

**Verified on dev.** A 33-check battery against the DAV tree (every operation, each error case, the
database state after each step) passed, and so did cross-user checks with a second account: naming
another user's tree in the path is 403 for `MKCOL`, `DELETE` and `MOVE`, while the account's own tree
works. The normal Files DAV is unaffected, an unauthenticated request is still 401, and with the API key
required and none sent the request is 403 and creates nothing.

**Driven through Memories' real UI** (Chromium on the dev stack, Memories 11.0.2, API key required):

| Memories action | Result |
|---|---|
| Rename person | `MOVE` 201, the page moved to the new name |
| Remove from person | `DELETE` 204, the photo left the person and shows under Unassigned |
| Merge with different person | five face `MOVE`s, all 201; the people merged, no error toasts |
| Remove person | `DELETE` 204 |
| Move to person (from Unassigned faces) | `MOVE` 201, the person grew by one, Unassigned shrank by one |
| Create person | not offered by this Memories version's UI; the route is tested at DAV level only |

Differences from Recognize's own behaviour, all intended: removing a face writes `-1` (unassigned)
instead of `NULL`, so clustering no longer puts it back; a person emptied by a merge stays as an empty
named person, which Memories hides (it only lists people with photos) and the People page lists, as it
always lists named people (`occ facewright:people <user> --delete-empty` removes them).

The setting `block_recognize_writes` is the switch: `false` hands writes back to Recognize. It reaches
the web process within about five seconds, not instantly, because `occ config:app:set` writes from
another process.

**Stage B rollback:** set `block_recognize_writes` to `false`; Recognize handles writes itself again.

**Deployed to live (2026-11-06).** Copied with the version left at 0.8.0 and no `occ upgrade`: the router
has no migration and no `info.xml` change, and an upgrade would also apply the pending App Store updates
(Memories 11.0.2, Recognize 12.0.3, about ten more). Backups in `<backups>/facewright-pre-router/`
(`bridge-pre-router.tgz` and a fresh dump of the face tables). Checked without a login: no new log
errors, `occ` loads, and an unauthenticated DAV request is 401 (a 500 would mean the plugin failed to
build). **Not checked on live:** an authenticated edit. Live runs Memories 10.1.0 and Recognize 12.0.2,
while all router testing was on 11.0.2 and 12.0.3; the request shapes were read from the 10.1.0 bundle and
match, but a real rename in the live Memories UI is the test. Rollback: the switch above, or restore the
tarball.

**Confirmed on live by the user (2026-11-06):** a merge and several renames through Memories 10.1.0 worked.
The web log shows about 2,400 face `MOVE`s answered 201 and one person-level `MOVE` answered 403, probably a
rename onto a name that already exists (refused by design, as Recognize itself did); not confirmed. No
errors from our code in the log, and the precheck afterwards was clean (the merge removed one person).

**Live upgraded (2026-11-06), with the user's go-ahead.** `occ upgrade` took about three minutes and applied the
pending store updates: Memories 10.1.0 to 11.0.2, Recognize 12.0.2 to 12.0.3, the bridge to 0.8.1 (now identical
to the repo, no pin) and eight others. Preflight showed live's Memories had already run the migration that
failed on dev (dev jumped from 8.1.0), so only two small ones were pending. A full database dump from before is in
`<backups>/facewright-pre-upgrade/`. Afterwards: data and precheck unchanged, no new log errors from our code.
Still pending in the store: maps, files_scripts, workflow_media_converter.

**Recognize's `MaintenanceJob` came back and ran before it could be removed.** The upgrade re-adds it, and it was
due at the very next cron tick (about two minutes after the upgrade ended). With pending rows over its size
threshold it queued Recognize's `ClusterFacesJob` for both users. Those ran at the following ticks with no
failure logged and no change to our data; both jobs and the `MaintenanceJob` were then removed with
`occ background-job:delete`, which asks for confirmation (`-n` answers no; pipe `y`). Remove it **in the same
command chain as the upgrade**, not afterwards. Left over: one row in `oc_recognize_fs_moves` from before the
upgrade, which Recognize 12.0.3 would process on the next file action (it syncs that one file's rows; low risk).

---

## Stage C: Expand

**Goal.** Our tables exist, are complete, and stay exact copies, while `rfd` and `rfc` remain the
source of truth. Rollback to any point in this stage: stop reading them.

### C.1 Migration and `FaceState`

New `lib/Migration/Version000007000Date2026MMDD000000.php`, shaped like `Version000006000Date20261020000000`
(`hasTable` guards, no foreign keys). Tables as in "Target data model". Docblock says why, not history.

New `lib/Service/FaceState.php`:

```php
final class FaceState {
	public const PENDING = 0;
	public const ASSIGNED = 1;
	public const UNASSIGNED = 2;
	public const UNCLUSTERABLE = 3;
	public const IGNORED = 4;
	/** @return array{state: int, cluster_id: ?int} */
	public static function fromSentinel(?int $clusterId): array { /* null, >0, -1, -2, -3; anything else throws */ }
	/** What the API, and Memories' tables, call this state. */
	public static function toSentinel(int $state, ?int $clusterId): ?int { /* inverse */ }
}
```

**Verify:** `occ upgrade`; `SHOW CREATE TABLE` for both matches the spec.

### C.2 `FaceStore`

New `lib/Service/FaceStore.php`: the only class that writes `facewright_user_faces` and
`facewright_clusters`. Constructor `IDBConnection $db`. It joins the caller's transaction and opens none.

```php
public function addFaces(string $userId, array $faceIdsByRowId): void   // rowId => faceId, explicit ids, state PENDING
public function createCluster(int $id, string $userId, string $title): void
public function renameCluster(int $id, string $title): void
public function deleteClusters(array $ids): void
public function setState(array $rowIds, int $state, ?int $clusterId): void   // chunks by 1000
public function deleteFaces(array $rowIds): void                        // chunks by 1000
public function resetUser(string $userId): void                         // non-IGNORED back to PENDING, clusters gone
public function deleteAll(): void
```

Invariant to enforce in `setState()`: `$clusterId !== null` exactly when `$state === FaceState::ASSIGNED`,
otherwise throw `\InvalidArgumentException`.

Stage E gives `addFaces` and `createCluster` an allocating form. These methods stay; the `rfd` statements
next to them go. That is why this class is worth writing rather than scattering mirror SQL.

### C.3 Dual-write from every bridge writer

Inside the same transaction as the existing statement, in this exact order: `rfd`/`rfc` statement first,
then `FaceStore`. Add a transaction where one does not exist but only around a single logical write.

| Writer | Add |
|---|---|
| `DetectionService::detect()`, after each detection insert | `addFaces($userId, [$detectionId => $faceId])` |
| `AccessSyncService::copyToUsers()`, after each link insert | same; rows with no donor link are skipped, as they are for links today |
| `AccessSyncService::pruneExceptUsers()` | `deleteFaces($chunk)` next to the link delete |
| `ClusteringService::createCluster()` | `FaceStore::createCluster($id, $userId, '')` |
| `ClusteringService::markUnclusterable()` | `setState($batch, UNCLUSTERABLE, null)`, but see below |
| `ClusteringService::clearUnclusterableMarks()` | select the ids first, then `setState(ids, PENDING, null)` |
| `ClusteringService::resetUser()` | `FaceStore::resetUser()` |
| `ClusteringService::persistAssignments()` | `setState([$id], ASSIGNED, $clusterId)` **only when that row's `executeStatement()` returned 1**; the guard must decide for both tables |
| `persistAssignments()`, delete of empty created clusters | select which of `$createdClusterIds` still exist afterwards; `deleteClusters()` the rest |
| `PeopleService::createCluster()`, `renameCluster()` | `createCluster()`, `renameCluster()` |
| `PeopleService::mergeMany()` | select member ids first; `setState(ids, ASSIGNED, $keepId)`; `deleteClusters($mergeIds)` |
| `PeopleService::deleteMany()` | select member ids first; `setState(ids, UNASSIGNED, null)`; `deleteClusters($clusterIds)` |
| `PeopleService::setFacesCluster()` | select which ids match the guard first, update `rfd`, `setState()` those ids; state derives from `$to` via `fromSentinel()` |
| `PeopleService::deleteEmptyUntitledClusters()`, `deleteEmptyClusters()` | select the ids first, delete both |
| `ResetService::resetFacewrightData()` | `FaceStore::deleteAll()` |
| `Command/ReopenUnassigned` | select ids, `setState(ids, PENDING, null)` |

`markUnclusterable()` uses an unconditional update by id list today. Keep that, but note a row a human
touched during the pass would be overwritten by it in both tables equally: the mirror stays exact, and
fixing that race is out of scope.

`FaceMigrationService` and `VectorCompactionService` need no change: they never change state.

**Do not touch** `ClusteringService::persistAssignments()`'s `WHERE` guard.

### C.4 Backfill: `occ facewright:own-tables --migrate`

`OwnTablesMigrationService`, resumable, modelled on `FaceMigrationService` and `Command/MigrateFaces`.
Cursor in appconfig `facewright_bridge/own_tables_after_id`, deleted on completion. Options: `--dry-run`,
`--chunk=<rows>` (default 5000), `--reset-cursor`.

Run with processing paused, and refuse to start otherwise (`processing_paused` must be `'true'`): a row
changing between a chunk's read and write would otherwise be stale.

1. **Clusters.** Copy all of `rfc`, including rows with no linked member, with `setValues('facewright_clusters',
   ['id' => $id], ['user_id' => ..., 'title' => ...])`. Keeping every row preserves empty titled people,
   and the legacy question is decided in F.3.
2. **Faces.** Page `rfd` joined to the link table, keyset on `d.id`, selecting only `d.id, d.user_id,
   d.cluster_id, l.face_id`. **Never select `face_vector`.** For each row, map with
   `FaceState::fromSentinel()`. A positive `cluster_id` missing from `rfc` becomes `PENDING` and is
   counted as `dangling`. Write with `setValues('facewright_user_faces', ['id' => $id], [...])`, so that
   a re-run corrects a stale row. One transaction per chunk.
3. Print per-chunk and total counts. Refuse to start if `--precheck`'s must-be-zero numbers are not zero.

Rows with no link are not ours and are not copied.

### C.5 `occ facewright:own-tables --verify`

Read-only, exits 1 on any mismatch. Print each number on its own line.

- `COUNT(*)` of linked `rfd` rows equals `COUNT(*)` of `facewright_user_faces`.
- Per state: `cluster_id IS NULL`, `> 0`, `= -1`, `= -2`, `= -3` on linked rows equals each state's count.
- Row mismatches, by SQL rather than PHP:

  ```sql
  SELECT COUNT(*) FROM rfd d
  INNER JOIN facewright_detection_faces l ON l.detection_id = d.id
  LEFT JOIN facewright_user_faces u ON u.id = d.id
  WHERE u.id IS NULL OR u.user_id <> d.user_id OR u.face_id <> l.face_id
     OR NOT (u.cluster_id <=> IF(d.cluster_id > 0, d.cluster_id, NULL))
  ```

  Dangling rows from C.4 show up here; report them as a separate, expected number.
- `facewright_user_faces` rows with no `rfd` row: 0.
- `rfc` and `facewright_clusters`: same count, no row differing in `user_id` or `title`.
- Invariant: no row where `(state = 1) <> (cluster_id IS NOT NULL)`.

Build raw SQL with `$qb->getTableName()` and `IDBConnection::executeQuery`, as
`PeopleService::countClusters()` does.

**Stage C done when:** on dev, after real use of the People page, a clustering run (`occ facewright:cluster
<user>`), a share (`occ facewright:backfill-access`) and a file delete, `--verify` still exits 0. Dual-write
is what is being proven here, so exercise every row of the C.3 table. A `--verify` failure after use
means a write path was missed: find it, do not re-run the backfill to hide it.

### As built (2026-11-06, dev only)

Files: `lib/Migration/Version000007000Date20261106120000.php`, `lib/Service/FaceState.php`,
`lib/Service/FaceStore.php`, `OwnTablesMigrationService` and `Command/OwnTables` extended with `--migrate`,
`--verify`, `--dry-run`, `--chunk`, `--reset-cursor`; the writers listed in C.3 changed. Bridge version 0.9.0.
The tables match the spec above (`SHOW CREATE TABLE` checked).

Deviations from the plan, and why:

1. **`FaceStore` mirrors from the Recognize rows instead of taking `setState(ids, state, cluster)`.** A writer
   says which ids it touched (`mirrorFaces`, `mirrorClusters`); the copy is rewritten from what the Recognize
   row says after the change. That makes a guard that skipped a row, or a concurrent edit that won, decide for
   both tables at once, so no writer reasons about its own `WHERE` twice, and `persistAssignments()`'s guard
   stays untouched (it only records which rows it really updated). It also covers deletions: a row gone from
   Recognize's table is removed from ours. Mirroring updates existing rows only, so a face the backfill has not
   reached yet, or one of Recognize's legacy rows, stays absent. `FaceStore::resetUser()` is the one statement
   form, because a reset can cover a hundred thousand faces.
2. **One transaction around each logical write, not "where one is missing".** `DetectionService::detect()`
   writes a whole file's rows inside one (the ExApp call stays outside; the processed marker stays after),
   `AccessSyncService` copies per user and prunes per chunk in one, `ClusteringService` wraps the write phase
   of `persistAssignments()` as a whole.
3. **`ResetService` removes exactly the rows and people it deleted** (`deleteFaces`, `mirrorClusters`) rather
   than calling `deleteAll()`: the people table also holds copies of Recognize's own legacy people, which a
   reset leaves alone.
4. **The backfill is set-based SQL** (`INSERT ... SELECT ... ON DUPLICATE KEY UPDATE` per id range), not
   `setValues()` per row: two orders of magnitude fewer statements on a real library. It reads only id, user,
   cluster and the link, never the vector. **MySQL/MariaDB only**; the command says so on another platform.
5. **A new must-be-zero count in the precheck, `unknown_sentinel_rows`** (a `cluster_id` of 0 or below -3 has no
   state to map to). `--migrate` refuses to start unless processing is paused and every must-be-zero count is 0.
6. **`--verify` reports dangling rows separately.** A face assigned to a person that no longer exists is copied as
   pending by the backfill and as assigned by a later change; both are right, so such rows are excluded from the
   mismatch count and must merely exist.
7. If `occ facewright:migrate-faces` links more rows after the backfill, run `--migrate` again (it is safe to
   repeat): the new links make those rows "ours", and the copy does not have them.

**How it was verified on dev.** After the backfill (2,797 faces, 311 people) `--verify` exits 0. The checker itself
was broken four ways (a wrong state, a deleted copy, a tampered name, a stray copy) and caught all four.
`--verify` was then run after every writer: the People API (create, rename, move faces to a person and to a new
person, detach, ignore, unignore, move or detach one face, merge, delete person, delete empty people), the
clustering writers (`occ facewright:cluster` assigning and creating people, a second pass marking faces
unclusterable, `clearUnclusterableMarks()`, `--reset` for a small and a large user), `reopen-unassigned`,
detection of a new file with a real face, `copyToUsers` and `pruneExceptUsers` called directly (a share cycle
alone did not reach them, because the recipient's mount stays cached until their next request), deleting the
file, and `occ facewright:reset`. The 33-check DAV battery from Stage B also passed with dual-write active, then
a restore of the original data and a fresh backfill left dev clean. Every step exited 0.

Not covered: concurrency (two writers on the same rows) and a library of production size. Both are what the
second `--verify` a few days after the live migration is for.

### C.6 Stage C on production

Needs the user's go-ahead: it applies a migration, which means a version bump and `occ upgrade`.

1. Deploy the files; run `occ app:update --showonly` and decide what else `occ upgrade` will apply (live is
   current apart from maps, files_scripts and workflow_media_converter). No Recognize update is pending, so
   the `MaintenanceJob` problem from the last upgrade does not apply, but check `oc_jobs` afterwards anyway.
2. `occ upgrade`: creates the two tables. Dual-write starts at that moment, into empty tables; that is
   harmless (mirroring updates existing rows only, creating and deleting people works).
3. `occ facewright:own-tables --precheck`: the must-be-zero counts, now including `unknown_sentinel_rows`.
4. Pause processing on the admin page, then `occ facewright:own-tables --migrate`. It copies in id ranges and
   verifies at the end. On a library of about 200,000 faces expect a few minutes; it is resumable (the cursor is
   kept in appconfig `own_tables_after_id`) and safe to repeat.
5. `--verify` must exit 0. Then unpause.
6. `--verify` again a few days later, after normal use. Stage D starts only once that passes: production use,
   with concurrent writers, is the real test of dual-write.

Rollback at any point: stop reading the new tables (Stage C changes nothing that reads them). To remove them,
restore the pre-upgrade dump or drop the two tables; Recognize's tables were never modified by the backfill.

**Done on live (2026-11-06), with the user's go-ahead.** Backup first: `<backups>/facewright-pre-stage-c/` (a full
database dump and the previous bridge). Processing was paused, the files deployed and `occ upgrade` run in one
chain: about a minute, creating the two tables (the three other pending store apps were not applied, since two are
disabled or not applicable; no Recognize job appeared). The precheck was clean, and `--migrate` ran in about 40
seconds, copying every linked face and every person, then verified itself: every count equal, no mismatch.
Processing was unpaused, and `--verify` after a further minute of live traffic was still clean, with no new
errors from our code in the log. **Still due:** a second `--verify` after a few days of normal use (concurrent
writers, real clustering runs, shares and deletions). Stage D starts only after that passes.

**Second live `--verify` (2026-11-06, about two hours after the migration, earlier than the few days planned; the
user judged it sufficient).** Before it: the user's own operations on the live data, 88 newly detected faces (24
files) written to both tables by normal detection, and a clustering run forced with `occ facewright:cluster-scan`
(the same chunked jobs the hourly trigger starts): 75 of the new faces were assigned, 13 marked unclusterable, and
two new people created. `--verify` afterwards: exit 0, every check ok, people equal in both tables (4,680), no
queued clustering jobs left, no errors from our code in the log. This covers the detection, People-page, DAV and
clustering writers on real data. Not covered: sharing and unsharing on live (no such events in that window) and
a long run of concurrent writers; those remain what dev tested.

---

## Stage D: Switch reads

**Goal.** Nothing reads `rfd` or `rfc` except the writers that still mirror to them. One service per step.
Each step keeps its method signatures and the JSON the API returns. Rollback: revert the commit.

Common rules:
- Replace `rfd d` plus the link join with `facewright_user_faces u` joined to `facewright_faces f` on
  `f.id = u.face_id`. `file_id` and geometry come from `f`.
- Replace `ownRowsExpr()` with nothing: every row in `facewright_user_faces` is ours.
- Replace a sentinel filter with a `state` filter.
- Anywhere an array result exposes `cluster_id`, emit `FaceState::toSentinel()`. `js/people.js` depends on it.

### D.1 `ClusteringService`

- `selectWantedIds()`: `SELECT id, cluster_id, state, face_id FROM facewright_user_faces WHERE user_id = ?
  AND state IN (PENDING, ASSIGNED, UNCLUSTERABLE) ORDER BY id`. The `continue` for `UNASSIGNED`/`IGNORED`
  goes away, because the `WHERE` does it. **Keep the in-memory representation:** convert to `null`,
  `UNCLUSTERABLE` or the positive id, so `loadDetections()` and `assignClusters()` are unchanged.
- `countUnclusteredByUser()`: `WHERE state = PENDING`.
- `clearUnclusterableMarks()`: select ids from `facewright_user_faces`. Keep the `rfd` update.

**Verify:** export member sets before and after, as in `independence-implementation.md` step 1.7:
`occ facewright:cluster --reset <user>` must give identical member lists up to cluster-id renumbering.
Confirm with `SELECT` that a `-1` and a `-3` row stay put through the run.

### D.2 `PeopleService`

- `clusterListQuery()`: `facewright_clusters c` LEFT JOIN `facewright_user_faces u` ON `u.cluster_id = c.id`.
  Counts now exclude legacy rows, which is correct.
- `coversFor()`: `u` joined to `f`. Note that `docs/people-ui-review.md` has an open item on this query's
  cost; do not fix it here.
- `getCluster()`, `resolveCluster()`, `assertValidTitle()`: `facewright_clusters`.
- `listFacesByClusterId()`: replace it with `listFacesWhere()`, taking a state and optional cluster id.
  `countFaces()` keeps taking a real cluster id or a sentinel, mapping through `fromSentinel()`.
- `facesForFile()`: `u`, `f`, LEFT JOIN `facewright_clusters`. Emit the sentinel in `cluster_id`.
- `sourceClusters()`, member-id selects: `facewright_user_faces`.

**Verify:** every People page view in headless Chromium, as in `docs/people-ui-implementation.md`. Compare
`occ facewright:people <user>` output before and after: identical apart from people containing legacy rows.

### D.3 `SuggestionService` and `FacePreviewController`

Same rules. `FacePreviewController::preview()` selects from `u` joined to `f`, scoped to `u.user_id`.
Authorization there is the `user_id` condition, so keep it in the `WHERE`.

**Verify:** a crop loads for the owner and 404s for another user. `occ facewright:people <user> --suggest
--faces=<id> --no-store` gives the same list as before.

### D.4 `AccessSyncService`, `DetectionService::usersWithRowsFor()`, `ResetService`

- `AccessSyncService::findDonor()`, `countOwnRowsForUsers()`, `pruneExceptUsers()` id selection: from `u`,
  joined to `f` to filter by `file_id`.
- `copyToUsers()`: the donor rows come from `u` and `f`, but keep `rfd`'s `face_vector` read until Stage E
  because the `rfd` insert still needs it.
- `usersWithRowsFor()` in both services: from `u`. The "ours or legacy" comment on
  `AccessSyncService::usersWithRowsFor()` no longer applies; update it.
- `DetectionService::usersWithRowsFor()`: from `u`. Its long docblock on the legacy-row incident still
  explains why legacy rows must not count; shorten it, keep the reason.
- `ResetService::findOwnRows()`, `Command/ResetFacewrightData::countOwnRows()`,
  `Command/ClusterFaces::printClusterSizes()`, `Command/ReopenUnassigned`: from `u`.

**Verify:** share and unshare on dev, `occ facewright:backfill-access --dry-run` agrees with direct SQL,
then for real. `occ facewright:reset --dry-run` counts agree with `--verify`'s. `--verify` exits 0.

**Stage D done when:** `grep -rn "recognize_face" lib/` lists only statements that write, plus
`copyToUsers()`'s `face_vector` read, the migration commands and comments. (Exact list, with the guards that
read Recognize's rows inside write statements: see "As built" below.)

### As built (2026-11-06, dev only)

Four commits, one per step: D.1 `ClusteringService`, D.2 `PeopleService`, D.3 `SuggestionService` and
`FacePreviewController`, D.4 `AccessSyncService`, `DetectionService::usersWithRowsFor()`, `ResetService`, the
reset and cluster commands, `ReopenUnassigned`. Method signatures and API JSON are unchanged; the sentinels
(-1, -2, -3) still appear wherever a UI reads them, through `FaceState::toSentinel()`.

What still reads Recognize's tables, and why (the plan's done-criterion, made exact):

- `AccessSyncService::copyToUsers()` reads the face **vector**: the copy is still written to Recognize's
  table until Stage E.
- The two "delete empty people" statements in `PeopleService` keep a member test on Recognize's rows. They
  delete from Recognize's table, and a person whose only members are legacy rows (which have no row of ours)
  is not ours to delete. This goes away with the legacy decision in F.3. (Deviation from the plan.)
- `ClusteringService::persistAssignments()` still reads which of its target people exist, and its cleanup of
  empty people still tests membership in Recognize's table. Both guard writes to Recognize's table, which the
  plan says not to touch. (Deviation.)
- `DetectionService::ownRowsExpr()` remains defined, with no reader; it goes in F.2.

Behaviour that changes, all intended: Recognize's legacy rows no longer appear in any count or list (they have
no row of ours); a face assigned to a person that no longer exists is pending (the backfill's rule); and
`copyToUsers()` / `pruneExceptUsers()` no longer touch a detection row that has no link, since such a row is not
ours (the precheck guarantees there are none to lose on live: `unlinked_long_rows` is 0).

**How it was verified on dev.** The risk of this stage is a read that silently disagrees with the old one, so the
method was a before/after comparison, not error-watching:

- A harness captured 46 read outputs on the old code: the People API (list with covers, search, paging, minimum
  size, unassigned, ignored, one person, its faces, boxes on six files, suggestions), the same for a second user
  and the cross-user requests that must fail, face crops for the owner and for another user (status, ETag and a
  hash of the image), the People CLI views, and the reset, backfill and reopen dry-runs. A second capture proved it
  deterministic; the final code, all four steps together, produces identical output.
- SuggestionService was checked at the byte level: the centroid cache was cleared and every centroid recomputed
  from the embeddings (302 of them), then hashed; identical before and after.
- A second harness called each D.4 reader directly (including the private ones) on every file that has faces
  plus two that do not, with rows for two users on four files: 595 results, identical.
- D.1 specifically: reset and recluster gives identical member sets (admin 306 people, bob 2), `-1` and `-3` rows
  stay put through a clustering run, and the unclustered count and clear-marks read agree with the old behaviour
  with real pending rows present.
- Pagination and the merge dry-run count were compared with direct SQL; crops were requested across users
  (19 of 19 refused) and a legacy row is refused.
- The write suites from Stage C (People API, DAV battery, clustering, reopen, reset, detect, share copy, prune,
  delete) all pass with `--verify` clean after every step.

Mistakes in my own harness that were caught by looking at the output, not the exit code, and are worth keeping
in mind: a `limit` above the API's maximum of 500 made one baseline call an error in both captures; a loop that
did not split a comma list sent malformed requests (every status `000`), so a "pass" proved nothing; and a
"stay put" check ran with no `-1` rows because the setup call had failed. Each was redone properly.

Not covered on dev: a library of production size, and concurrent writers during the reads.

### Stage D on live (2026-11-06)

Deployed after the second live `--verify` passed: the ten changed files copied, **no version bump and no `occ
upgrade`** (Stage D has no migration, so no store updates and no Recognize job to clean up), after a backup of the
previous bridge. Processing was paused for a few minutes so a before/after capture compared the same data. The
capture was read-only: the People CLI views for both users (lists, unassigned, ignored, the empty-people dry run,
the faces of four people), the reset and reopen dry runs, and a direct-call harness over 302 files, 150 of them
held by more than one user.

Result: every list and view identical, and all 2,114 per-file results identical. Three differences, each
explained before the capture or confirmed by SQL afterwards: the reset's cluster count and the cluster-size
printout lost the id of a deleted person that a few faces still pointed at; the unclustered count rose by
exactly those faces (pending in ours); and the unclusterable group shrank by the number of Recognize's legacy rows
(the old printout counted them, ours correctly does not). `--verify` afterwards: clean.

**Found afterwards: a clustering chain that never ends.** The faces just mentioned (pending in ours, but still
carrying the id of a deleted person in Recognize's table) made the clustering job loop on live: a run every five
minutes, for hours. Cause: D.1 moved the clustering *read* to our table, but `persistAssignments()`'s write guard
still tests Recognize's row (`cluster_id IS NULL OR = -2`). The guard rejected the write, the faces stayed pending,
and `passProgress` counted the in-memory matches as progress, so the chain restarted forever. No data was wrong and
no ids were burned; the cost was a job at about 300 MB every five minutes. Dev has no such rows, so no dev test saw
it, and the live precheck had listed them as informational. Two lessons: a read and the guard on the write that
follows it must test the same table, and a chain's progress must mean rows *written*, not matches found.

Fixed (E.0): those rows were reopened to `NULL` in Recognize's table (id list dumped first, restore point kept) and
`passProgress` now counts rows written. The chain ended on live and `--verify` is clean. The regression is
`dev/chain-loop-test.sh dangling`; it fails on the code from before the fix (the chain still loops after 12 runs).

---

## Stage E: Push instead of mirror

**Status: built and verified on dev (2026-11-06); NOT deployed to live. Live still runs Stage D until the cutover below, which needs the user's go-ahead and a quiet window.** See "As built" at the end of this stage for what differs from the plan.

**Goal.** Our tables are the source of truth; Recognize's `rfd` and `rfc` become a copy that a projection writes.
**This is the point of no easy return:** after it, an older bridge would read stale data, so it has its own
rollback design (below) and a cutover that is gated before processing resumes.

**Decisions (with the user, 2026-11-06).**
- **Single cutover** on live, not a separate observe-only phase. The safety a shadow phase would give is kept by
  running the projection's dry run while processing is still paused (step 3 of the runbook).
- **Hidden rows stay in Recognize's table until Stage F.** Pending, unclusterable and ignored faces are no longer
  written there, but tens of thousands already are; they are not deleted in the cutover. A row leaves when its
  face next changes, and the rest in F.1 with a dumped id list.
- **The verification scripts are committed under `dev/`** (E.0).

**What changed from the first draft of this stage.** Each item is a problem found by doing Stages A to D.

1. **`FaceStore::mirrorFaces()` deletes our row when Recognize's row is missing.** Harmless while Recognize's
   table is complete. After Stage E (hidden states absent from it) it would delete our rows for faces touched after
   their Recognize row is gone. It is also a hazard today: Recognize's own access sync deletes our rows by file, and
   a later write of ours on that face would then destroy our copy. The mirror methods are removed in E, and **no
   mirror-era code may run once hidden rows are absent.** That is why projection and writers change in one deploy.
2. **The projection upserts.** Delete-then-insert races when two pushes touch the same ids (duplicate key), and
   would overwrite every real vector with `'[]'` on each update. Use `INSERT ... SELECT ... ON DUPLICATE KEY UPDATE`
   that leaves `face_vector` and `threshold` alone; new rows get `'[]'`. Delete only ids that are no longer visible.
3. **Reconcile must not read vectors of rows we own.** Measuring `CHAR_LENGTH(face_vector)` on every Recognize row
   without a visible copy would read the off-page vectors of every hidden row each hour (hundreds of MB on a real
   library), evicting the buffer pool. Candidates are found by id only; length is measured only for rows with *no row
   of ours at all*.
4. **The Stage C `--verify` stops being valid.** It asserts Recognize's table equals ours row for row; after E it is
   a visible subset. It is rewritten (E.5).
5. **Reads and the guards on the writes that follow them must test the same table** (the clustering loop above). In E
   both move to ours together, which removes the class of bug.
6. **Rollback was not designed.** Added below: three tiers and a `--hydrate` mode, rehearsed on dev.
7. **Push order matters to Memories.** People that exist first, then faces, deleted people last, so no face ever
   points at a person that is gone and none shows without its person.
8. **The delete-empty statements still need Recognize's member test**, so a person whose only members are legacy
   rows is never deleted. They keep reading Recognize's rows until the legacy decision (F.3); everything else stops.
9. **Recognize's own writers get more room.** Its access sync copies rows for users who "have none"; once hidden
   states are absent from its table, more users look like they have none. Those copies are stale rows, and reconcile
   removes them. Recognize's `MaintenanceJob`, which `occ upgrade` re-adds and which runs at the next cron tick, is
   deleted in the same command chain as any upgrade.
10. **Verification discipline** (see "How to work this plan", item 9) is part of the stage, not an afterthought.

### E.0 Preconditions

- **The clustering fix** (Stage D record; done): reopen the stranded rows in Recognize's table, and make `passProgress`
  count rows written (`persistAssignments()` already returns the count; `clusterUser()`, `clusterUserChunk()` and
  `ClusterScanJob` must use it). Dev regression: faces pending in ours that carry a dead person id in Recognize's;
  before the fix the chain must loop (assert it passes more than a bound), after it it must stop.
- **`dev/`**: done. The scripts and `dev/README.md` are committed; `dev/run-all.sh --ui` runs every suite.
  Still to add for Stage E: the projection and reconcile checks.
- Stage D running on live for a few days with `--verify` clean.

### E.1 `ProjectionService`

`lib/Service/ProjectionService.php`, MariaDB only (the same platform check as `OwnTablesMigrationService`).
`push(array $faceIds, array $clusterIds)` and a hydrate variant.

- **People first:** upsert the rows of `facewright_clusters` into `rfc` for ids that exist.
- **Faces:** per chunk of 500, in its own transaction, upsert the visible ones (`state IN (ASSIGNED, UNASSIGNED)`;
  `cluster_id` is the person for ASSIGNED and `-1` for UNASSIGNED) and delete ids that are no longer visible.
  Name every column on both sides: Recognize's physical order is `x, y, height, width`.
- **Deleted people last.**
- Never called inside an open transaction (assert it); catches `\Throwable`, logs, sets the dirty flag, and **never
  rethrows**: a Recognize schema change must not break our own pages. `available()` probes the columns once per process.
- **Hydrate mode** writes all five states with their sentinels (`NULL`, `-2`, `-3`) and deletes nothing. It exists
  for rollback only.

### E.2 Reconcile, `occ facewright:project`, and an hourly job

`occ facewright:project [--user=<uid>] [--dry-run] [--hydrate] [--limit=<n>]` and `ProjectionMaintenanceJob`
(a new background job: needs a version bump, hence an `occ upgrade`). Categories, all paged by id:

- **A. Missing or wrong visible faces** (user, file, cluster, geometry compared): must be 0.
- **B. Hidden-state remnants:** Recognize rows whose face is pending, unclusterable or ignored in ours. Reported and
  tolerated until F.1. Found without reading vectors.
- **C. Unknown stale rows:** rows with no row of ours at all that look like copies of ours (vector length 2 or above
  3,500). Must be 0 after a repair. Recognize's legacy rows (about 2,600 characters) are reported separately and
  never touched.
- **D. People:** missing or wrong, or present in Recognize's table with no row of ours and no face referencing them.

Exit status 1 on A, C or D. The job honours `processing_paused`, is capped per run, and clears the dirty flag only
if no newer failure arrived while it ran.

### E.3 `FaceStore` becomes the primary writer

Remove `mirrorFaces()` and `mirrorClusters()`. Add `addPendingFace(user, faceId): int` (the id comes from our own
counter), `allocateCluster(user, title): int`, state changes that take their guard as a parameter, `renameCluster`,
`deleteClusters`, `deleteFaces`, `resetUser`. Each returns the ids it changed. `facewright_detection_faces` is still
written until F.2, because rollback needs it. Seed the counters above `max(rfd.id)` and `max(rfc.id)` plus a margin
(`occ facewright:own-tables --seed-ids`, MariaDB only), so a new id never lands on an existing Recognize row.

### E.4 Switch the writers

All in the same deploy as E.1 to E.3. A caller writes ours inside its transaction, commits, then calls `push()` once
with everything it touched.

| Writer | Change |
|---|---|
| `DetectionService::storeFaces`, `AccessSyncService::copyRowsToUser` | no Recognize insert and no vector read; `addPendingFace` and the link; no push (pending is hidden) |
| `AccessSyncService::pruneExceptUsers` | delete ours (links, and faces when nobody keeps the file), commit, push |
| `ClusteringService::createCluster`, `persistAssignments` | `createCluster` uses `allocateCluster` (the person is pushed with the faces it ends up holding); `persistAssignments` guard on ours: `WHERE id = ? AND user_id = ? AND state IN (PENDING, UNCLUSTERABLE)`, target person in `facewright_clusters`; `$written` counted on ours; emptied created people judged on ours; then push people, then faces |
| `ClusteringService::markUnclusterable`, `clearUnclusterableMarks`, `resetUser` | ours, then push (this is how remnants disappear) |
| `PeopleService` mutators, and through it the DAV router | `$onlyFrom` and `$onlyAssigned` become `state` predicates; the ids "now at the target" are read back from ours; split `...InTransaction()` variants where one mutator calls another; push after commit |
| `ResetService` | delete ours, then delete the Recognize rows and people it collected, directly: a full reset must not depend on the projection |
| `Command/ReopenUnassigned` | state to PENDING, push |
| `PeopleService` delete-empty statements (`deleteEmptyClusters`, the empty-person cleanup inside `setFacesCluster`) | the decision keeps Recognize's member test (F.3), so a legacy-only person is never chosen; the delete itself then goes through ours (`deleteClusters`) and is pushed |

Remove the router's `block_recognize_writes` switch. Once vectors can be `'[]'`, Recognize's own "remove face"
would fail, and handing writes back to it would lose them.

### E.5 `--verify` rewritten

Ours internally (the state and cluster invariant, uniqueness, links, people) plus categories A, C and D above; B is
informational. The row-for-row mirror lines are removed.

### E.6 Admin status

"Memories sync: up to date" or the time and text of the last error, read from appconfig, rendered on the server.

### Dev acceptance

Compose stack; every test asserts its precondition and is run by the `dev/` scripts.

1. **Baseline:** the read capture and the direct-call harness identical before and after (reads already come from
   ours since D).
2. **Memories as the oracle:** capture Memories' own API (the people list, a person's photos, unassigned) on Stage D
   code, then on Stage E code with unchanged data: identical. After each change below, assert Memories shows it at
   once, and that it differs from before.
3. **Writer matrix:** the People API, the DAV battery, the clustering writers, detection with a real face, share
   copy, prune, delete, reset, reopen. After every step `project --dry-run` shows A, C and D at 0 and B as expected.
4. **Drift repair:** plant faults (delete projected rows, retitle a person, change a cluster id, insert a stale copy
   with a long vector, touch a legacy row). The dry run reports exactly those; `project` repairs them; the legacy
   row is untouched; a clean run exits 0.
5. **The mass-delete trap:** a `.noimage` marker in a folder with faces and Recognize's deletion job: assert its rows
   are gone and Memories lost the people; `project` restores them and Memories returns to the captured output. The
   same recovery applies to Recognize's own reset commands, which wipe its tables without a user or dimension filter.
6. **Recognize copy cleanup:** a share plus Recognize's access-update job creates unlinked copies (assert they
   exist); reconcile removes them and leaves legacy rows alone.
7. **Failure isolation:** point the projection at a table that does not exist (in the bind-mounted source; never
   alter the real table). People writes still succeed, the admin page shows the error, and after restoring the name
   `project` repairs everything.
8. **Concurrency:** two parallel loops of overlapping renames, moves and detaches on the same faces; afterwards A, C
   and D are 0 (the case delete-then-insert would fail).
9. **Clustering regression:** the dangling-face fixture from E.0.
10. **Rollback drill** (below).

### As built (2026-11-06, dev only)

Commits `4dcb839` (E.1 and E.2), `b8093d8` (E.3 and E.4), `1deda14` (job, status, no router switch), `e790c0d`
(squatters), plus the `dev/` suites. Differences from the text above, each found by a test:

- **`push()` inside a transaction does not throw.** It logs, flags the copy dirty and returns false. A listener (a file
  being deleted) must not fail because of it, and the repair job does the copy.
- **Squatters.** Pushing an explicit id moves Recognize's counter past it, so its next row (its access sync writes
  rows of its own) lands exactly where our next id will; a larger seed margin cannot prevent that (it is 1,000).
  A visible face overwrites the row it finds (the upsert); on a hidden face it is category C and is removed.
- **Emptied people** are judged on ours plus Recognize's legacy rows (`FaceStore::emptyClusterIds`), not on a table that
  lags until the push. Merge and delete also move legacy rows with the person (`moveLegacyMembers`), as Recognize's own
  code would have.
- **`markUnclusterable` now guards on state** (pending or unclusterable); before, a face a human had moved meanwhile
  could be overwritten by the verdict.
- **Rollback R2 needed two fixes** (above). **Opcache:** the web process revalidates compiled PHP every 60 s, so any
  test that edits a source file restarts Apache (`reload_web` in `dev/lib.sh`); one concurrency run was contaminated by
  a stale broken copy before this was understood.
- **`recognize:reset-face-clusters`, which the docs say undoes all curation, is recoverable**: after `occ
  facewright:project`, Memories shows exactly what it showed before (`dev/project-recognize-trap.sh`, dev only).

Verified on dev (all suites in `dev/run-all.sh`): reads (49 outputs), the direct-call harness and Memories' own API
identical to the Stage D baselines on unchanged data; People (40 checks), clustering writers (16), DAV battery (44),
file writers (21), Memories UI flows (16), drift repair (24), failure isolation (15), concurrency (96 requests, copy
agrees; fails when the upsert is degraded), Recognize's destructive actions (21), Recognize's copies and squatters
(26), rollback drill (25), and clustering against ground truth (50 people, 750 faces, pure and complete).

Not yet done: the live cutover itself, and the admin page's new line has been rendered but not looked at in a browser.

### Stage E on live (2026-11-06)

Cut over with the runbook below, in this order, each step checked before the next:

1. Processing paused; nothing running. A full dump of the one database, a tarball of the live bridge files, and the
   counts and checksums of Recognize's two tables were saved to `<backups>/facewright-pre-stage-e/` (live files were
   byte-identical to the Stage D tip `d620eff`, so the tarball is the R2 rollback source). Stage D's `--verify` was clean.
2. Files deployed from `git archive HEAD`, version 0.10.0, `occ upgrade`. It updated only the bridge: the three pending
   store updates (none of them Recognize, Memories or AppAPI) were not applied, and Recognize's `MaintenanceJob` did not
   come back. `--seed-ids` raised both counters.
3. **Go/no-go, still paused:** `project --dry-run` showed A, C and D at 0, B equal to the number of hidden-state rows
   recorded in step 1, the legacy rows untouched and exit 0; the new `--verify` exited 0; and the checksums of Recognize's
   tables (without the vector column) were identical to those recorded before the deploy.
4. Unpaused. After the next cron tick the hourly job had run and the copy reported itself up to date; the dry run was
   unchanged and the log had no entries of ours.

The person-visible check passed (the user renamed, moved and removed faces in Memories; each showed at once; the dry
run afterwards still had A = C = D = 0, `--verify` exit 0, nothing of ours in the log after the unpause).

Still to do: a clean dry run after a day that includes a clustering run and any shares, the admin page's "Memories
sync" line seen in a browser, and then at least two weeks before Stage F (not before 2026-11-20).

### Rollback

- **R1, before unpausing at the cutover:** restore the previous files. Nothing ran under the new code and no data
  changed.
- **R2, after use:** `occ facewright:project --hydrate` writes every state back into Recognize's table; restore the
  Stage D files **with `info.xml`'s `<version>` set to the installed one** (`occ config:app:get facewright_bridge
  installed_version`); run the Stage D checks; unpause. Nothing is lost. Hidden rows come back as `NULL`, `-2` and `-3`
  with `'[]'` vectors, which Stage D code tolerates. Two things the dev drill found: the restored files' own version is
  the OLD one, and a mismatch makes Nextcloud demand an upgrade (no `occ` commands, web 503), while `occ upgrade`
  would apply every pending store update; and Nextcloud may drop the hourly job from `oc_jobs` while its class is
  missing (when cron walks the job list), so **rolling forward again** needs `occ app:disable facewright_bridge && occ app:enable facewright_bridge`
  (and Recognize's `MaintenanceJob` deleted in the same chain, as after any enable).
- **R3:** restore the full database dump taken before the cutover. Loses edits made since; last resort.

Drill on dev: cut over, use it, hydrate, revert, then run the Stage C and D suites and the Stage C `--verify`.

### Live cutover runbook

Needs the user's go-ahead and a quiet window they choose. Preconditions: dev acceptance green, Stage D on live for
a few days with `--verify` clean, the E.0 fix deployed, and `occ app:update --showonly` reviewed (the version bump
means `occ upgrade` applies whatever is pending).

1. Full database dump, bridge tarball, and recorded counts (Recognize's table by value, ours by state, covers,
   people). Pause processing and wait for in-flight jobs.
2. Deploy the files, bump the version, `occ upgrade`, delete Recognize's `MaintenanceJob` if present (one command
   chain, `y` piped with `docker exec -i`), `occ facewright:own-tables --seed-ids`. The bridge version is 0.10.0.
3. **Go/no-go, still paused:** `project --dry-run` must show A, C and D at 0, B equal to the hidden-state rows that
   were in Recognize's table (counted in step 1), and the legacy rows untouched; the new `--verify` exits 0. Any
   other number: R1 and stop. This is the shadow phase's check, made at the cutover.
4. Unpause. First hour: a dry run every 15 minutes; the user renames, moves and removes a face in Memories and sees
   it at once; the log and the admin status are clean.
5. After a day that includes a clustering run and any shares: the dry run is clean. Stage F waits at least two weeks.

### The `dev/` scripts

Committed with a README that says what each checks and how to run it on the compose stack: the read capture, the
direct-call harness over the access-sync readers, the 33-check DAV battery, the People, clustering, file and direct
writer suites, the member-set comparison, and the Playwright driver for Memories' UI. Two fragile spots found while
using them are fixed on the way in (ids are taken from SQL and split explicitly, because the shell does not split
variables; and counts are printed, not just pass or fail). They use only the dev stack's existing passwords. The
Stage E checks above are added to the same set.

---

## Stage F: Contract

**Goal.** Remove what is no longer needed. Destructive, so everything here waits until Stage E has run on
production for at least two weeks with `project --dry-run` clean. Ask the user before starting.

### F.1 Empty the hidden rows, blank the vectors

Stage E leaves the hidden-state rows (pending, unclusterable, ignored) in Recognize's table; reconcile reports them
as category B and tolerates them. Here they are removed in bulk: dump the id list to a file, delete by that list in
chunks, and print `before - listed = after`, which must reconcile exactly. Never do this while any mirror-era code
is deployed (E, item 1).

New option `occ facewright:own-tables --blank-vectors [--dry-run] [--chunk=<n>]`: resumable by keyset on
`id`; `UPDATE rfd SET face_vector = '[]' WHERE id IN (...)`, selecting ids with a matching row in
`facewright_user_faces`. Print `SUM(CHAR_LENGTH(face_vector))` before and after.

Large rewrites belong in commands, not migrations. Disk is reclaimed only by rebuilding the table, which
is an `ALTER TABLE ... FORCE` in a maintenance window. Leave that to the user.

**Verify:** `rfd` holds only `cluster_id > 0` or `-1` rows, apart from legacy rows; Memories unchanged.

### F.2 Delete the transitional code

Delete `DetectionService::ownRowsExpr()`, `encodeVector()`, `VECTOR_PRECISION`; move `VECTOR_LENGTH_FLOOR`
to `ProjectionReconcileService` as a private constant with its reason. Delete `VectorCompactionService`,
`Command/CompactVectors`, `FaceMigrationService`, `Command/MigrateFaces`, `Command/ReopenUnassigned` and
their `info.xml` entries. Delete `ClusteringService::UNASSIGNED`, `IGNORED` and their docblocks where
`FaceState` now says it; keep the in-memory `UNCLUSTERABLE` marker only if D.1 still needs it.

New migration: drop `facewright_detection_faces`, only after `grep -rn detection_faces lib/` is empty apart
from that migration. Update `ResetService` first. The links table is kept until here on purpose: rollback to
Stage D code needs it (E.3).

### F.3 Legacy rows: **user decision**

Report `--precheck`'s numbers for legacy rows and for clusters with no own member, split titled and untitled.
Do not delete anything without an explicit answer. If the user approves: dump the id list to a file, rehearse
inside a rolled-back transaction, delete by that list, and print `before − listed = after`. The member tests that
the two delete-empty statements in `PeopleService` make against Recognize's rows go away with this decision.

### F.4 Docs

- `CLAUDE.md`: "Schema mapping" and "Telling our rows from Recognize's" are rewritten around the two tables and
  the projection; sentinels move to `FaceState`; "Coexisting with Recognize" keeps only what still applies,
  and states that a wipe is now recoverable with `occ facewright:project`; `occ` list; `.noimage` handling; Open
  items 4 and 6 updated.
- `README.md`: no Memories note is needed; editing keeps working.
- `docs/independence.md`: mark what is done.

---

## Stage G: Memories pull request

**Do not start this without the user.** It is a proposal to another project, and it should start with a
conversation. Writing code or posting anything publicly needs the user's explicit go-ahead.

### G.1 Prepare the proposal

A new `docs/memories-people-backend.md` in this repo:

- **Today's blockers, verified on 10.1.0:** `lib/ClustersBackend/Manager.php` holds a constant `BACKENDS`
  map, with no way to add one at runtime. `OtherController` and `SystemConfig` expose `recognize_enabled` and
  `facerecognition_enabled`. The frontend has separate routes and hardcoded write calls per backend.
- **Precedent:** `FaceRecognitionBackend` already has a plain HTTP write API rather than DAV, so a
  generic contract is a generalization of something Memories already does.
- **Proposed shape:** a server-side registration point for third-party people backends; a capabilities
  response telling the frontend which backend is active; write operations (rename, delete, create, move, remove)
  dispatched through a backend-declared API.
- **Our side:** the OCS API at `/ocs/v2.php/apps/facewright_bridge/api/v1/` as the reference.
- **What we are asking:** whether maintainers would accept this, and in which shape, before any code is written.

### G.2 Discussion, then PR

The user opens the discussion. Implement only the shape agreed there. Target versions and timing are unknown.

### G.3 Our backend

Once a Memories release has the hook: a new class in `facewright_bridge` reading `facewright_clusters`,
`facewright_user_faces` and `facewright_faces`, mirroring `RecognizeBackend` (`transformDayQuery`,
`getClustersInternal`, `getPhotos`, covers with `facewright_user_faces` as the object table). Ids stay valid
because of "One id space", so `memories_covers` keeps working once `clustertype` is migrated.

---

## Stage H: Retire the bridge

Only after G.3 runs on production.

1. Stop calling `ProjectionService`; remove it, `ProjectionReconcileService`, the job, the command and
   `RecognizeWriteRouterPlugin`.
2. Decide with the user whether Recognize can be uninstalled. Photos' People view stops working if it is.
   If yes: `.noimage` markers become irrelevant, CLAUDE.md's coexistence section goes, and `rfd`/`rfc` can be
   dropped only after a dump.
3. If upstream refused: skip this stage. The projection stays, and this doc's "temporary" wording changes.

---

## Deviations

From `docs/independence.md`:

1. **Stage 2's shim is not planned.** Recognize stays installed and passive, and its DAV tree serves reads only.
2. **M7 is out of date.** The server side is not pluggable in 10.1.0: `Manager::BACKENDS` is a constant.
3. **`-1` semantics are kept for Memories only.** Internally this is a state.
4. **Stage 3(a) becomes the primary target**, not an optional offer.
5. **The doc's Finding 2 (remove face does not stick)** is fixed by refusing Memories' path, not by a shim.
6. **Its version references** (Memories 8.1.0, `ClusterMergeService`) are stale: live is 10.1.0, and
   that service was deleted. Stage 0's `ClusterMergeService` references are historical.

From `docs/independence-implementation.md`:

1. **Deviation 2 is reversed.** Assignment state moves out of `rfd`, and the `state`-column idea returns,
   without `rejected_cluster_id`, which is still not needed.
2. **Deviation 3 changes.** `face_vector` is blanked to `'[]'`, not dropped: the column belongs to real
   Recognize, which stays. That doc rejected `'[]'` because of `FacePhoto::delete()`; the write guard makes
   that path unreachable.

---

## Finding from the E.0 scripts: parallel DAV moves deadlock (fixed)

Memories sends one MOVE per face, in parallel, when a person is merged. Three of eleven returned 500 on dev
(`Deadlock found when trying to get lock` in `FaceStore::mirrorClusters`, reached from
`RecognizeWriteRouterPlugin::moveFace`): all of them write the same cluster row, and the faces they carried stayed
where they were, so the merge was silently incomplete. Stage B code had the same shape, so live was exposed.

Fixed by `RecognizeWriteRouterPlugin::retryOnDeadlock()`: the whole operation is run again (up to 6 tries, random
pause) when the database reports a deadlock or lock-wait timeout. Safe because the plugin is the outermost caller and
each operation is one transaction. `dev/memories-ui/flows.py` flow 4 is the regression (11 faces merged, 16 expected;
with the retry switched off it ends at 13). **Stage E:** the projection's upsert of the same person row needs the
same retry.

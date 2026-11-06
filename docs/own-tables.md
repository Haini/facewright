# Owning the face data: review and implementation plan

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
  `oc_recognize_face_clusters` up to date for Memories. Memories' people edits are refused, so the
  People page is the only write path. Real Recognize stays installed, its classifier off.
- If upstream refuses, the temporary design stays. It must be good enough to live with.

## Decisions (made with the user, 2026-11-06)

- **Memories PR is the target.** The push is a bridge to it, not the end state.
- **Memories is read-only for people during the bridge.** Refusing the writes needs a small Sabre
  plugin. It depends only on the `recognize/` DAV path prefix. Its cost is that Memories' rename,
  delete and move actions show errors.
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

- Memories' people actions show errors for as long as the bridge lasts, and that has no date.
- Recognize can still queue `ClusterFacesJob` directly from its `FsActionService` handlers. Reconcile
  removes the rows afterwards but cannot stop the job running.
- Photos' People view depends on Recognize's tree. It keeps reading and its writes are refused too.
- Upstream's reaction to the PR is unknown.

---

# Part 2: Implementation

## How to work this plan

1. **Read `CLAUDE.md` first, in full.** The "Invariants" section is not optional. Column order, job
   dedup semantics and `persistAssignments()`'s guard are all touched here.
2. **Work on the compose stack only.** `recognaize-nextcloud-1`, `recognaize-db-1`. The production
   instance (`<app>`, `<db>`, `<apps-dir>/`) is read-only reference
   material. Do not run `occ` or SQL against it. The two exceptions are marked **[user runs]**: hand
   those to the user and stop.
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

## Stage B: Refuse Memories' writes

**Goal.** One write path. Without this, dual-write in Stage C would miss every Memories edit.
Useful alone: "remove face" can no longer be undone by clustering, because it can only be done from
the People page, which writes `-1`.

### B.1 `RecognizeWriteGuardPlugin`

New `lib/Dav/RecognizeWriteGuardPlugin.php`:

```php
class RecognizeWriteGuardPlugin extends \Sabre\DAV\ServerPlugin {
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

- `templates/admin.php`: one line under the Memories toggle saying people are edited on the People page,
  and that Memories' people actions are disabled.
- CLAUDE.md: note in "Why Recognize cannot simply be uninstalled" that writes are refused.

**Done on dev (2026-11-06).** As built: `lib/Dav/RecognizeWriteGuardPlugin.php`,
`lib/Listener/SabrePluginListener.php`, registered in `Application::register()`. Two deviations from the
plan: `<version>` was **not** bumped (nothing in `info.xml`, JS or the schema changed, and a bump forces an
`occ upgrade` on live with its store updates), and `LOCK` is refused as well as the six listed methods.

Checked against the dev DAV tree: `PROPFIND` 207; `MKCOL`, `DELETE`, `MOVE`, `PUT` under `recognize/`
403 with the tables unchanged; the face-level `DELETE` and `MOVE` 403; the normal Files DAV unaffected;
an unauthenticated request still 401 (authentication runs first). With the switch off the same `MKCOL`
created a person (201). The switch reaches the web process within about five seconds, not instantly:
`occ config:app:set` writes from another process, so a test run straight after it can see the old value.

**B.2, driven through Memories' real UI** (Chromium on the dev stack, Memories 11.0.2). In every case
the data was unchanged afterwards and Memories stayed usable:

| Memories action | Request | What the user sees |
|---|---|---|
| Rename person | `MOVE faces/{p}` 403 | toast "Failed to rename P2 to ...", dialog stays open |
| Remove person | `DELETE faces/{p}` 403 | toast "Failed to delete P2.", dialog stays open |
| Merge with different person | one `MOVE` per face, all 403 | **one toast per face** ("Error while moving 200014-fw_rot.jpg"), dialog stays open |
| Remove from person | `DELETE faces/{p}/{id}-{name}` 403 | toast "Failed to remove fw_modal.jpg from face." |
| Move to person, create person | not driven in the UI | needs the viewer preference "Mark person in preview", which is not in the Settings dialog; the same requests were refused at DAV level (merge sends the identical face `MOVE`) |

The merge result is the notable cost: a person with hundreds of faces produces hundreds of failed
requests and toasts. Nothing server-side can shorten that, since Memories ignores the response body. If
it turns out to be too disruptive, the fallback in "Known limits" (route the requests into our own code
instead of refusing them) is the answer.

**Stage B rollback:** the appconfig switch above.

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

### C.6 **[user runs]** Stage C on production

Hand the user: deploy; pause processing on the admin page; `occ facewright:own-tables --migrate`;
`--verify`; unpause; `--verify` again a few days later. Stage D starts only once the second `--verify`
passes: production use is the real test of dual-write.

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
`copyToUsers()`'s `face_vector` read, the migration commands and comments.

---

## Stage E: Push instead of mirror

**Goal.** Our tables are the source of truth; `rfd` and `rfc` are written only by `ProjectionService`.
**This is the point of no easy return:** after it, an older bridge would read stale data. Before step E.4,
the user must have a fresh backup.

### E.1 `ProjectionService`

New `lib/Service/ProjectionService.php`. Constructor `IDBConnection $db`, `IAppConfig $appConfig`,
`LoggerInterface $logger`.

```php
public function pushFaces(array $rowIds): void     // chunks of 500
public function pushClusters(array $clusterIds): void
public function available(): bool
public function markDirty(string $reason): void
```

`pushFaces()`, per chunk, in its own transaction:

```sql
DELETE FROM rfd WHERE id IN (...);
INSERT INTO rfd (id, user_id, file_id, x, y, width, height, face_vector, cluster_id)
SELECT u.id, u.user_id, f.file_id, f.x, f.y, f.width, f.height, '[]',
       CASE WHEN u.state = 1 THEN u.cluster_id ELSE -1 END
FROM facewright_user_faces u
INNER JOIN facewright_faces f ON f.id = u.face_id
WHERE u.id IN (...) AND u.state IN (1, 2);
```

Every column is named on both sides: the table's physical order is `x, y, height, width`. Ids are cast with
`intval` and inlined, since `INSERT ... SELECT` cannot be built with the query builder. Use the constants,
not literals, when composing the string. `threshold` stays at its default.

`pushClusters()` works the same way, with `id, title, user_id`, and no state filter.

Both public push methods:
- return at once if `$rowIds` is empty;
- catch `\Throwable`, roll back, log at error level, and call `markDirty()`. **They never rethrow**;
- must not be called inside a caller's open transaction (see E.3). Assert it:
  `if ($this->db->inTransaction()) { throw new \LogicException(...); }` before the `try`, because this is a
  programming error, not a runtime failure.

`available()` runs `SELECT id, user_id, file_id, x, y, width, height, face_vector, cluster_id FROM rfd
WHERE 1 = 0` and the equivalent on `rfc`, cached per process. When false, pushes skip and mark dirty, so
a renamed column degrades Memories' view rather than our own pages.

`markDirty()` sets appconfig `projection_dirty` to the current timestamp and `projection_last_error`.

### E.2 Reconcile: `ProjectionReconcileService`, job and command

`reconcile(?string $userId, bool $dryRun, int $limit): array` runs three queries, each paged by keyset id:

- **Missing or wrong:**

  ```sql
  SELECT u.id FROM facewright_user_faces u
  INNER JOIN facewright_faces f ON f.id = u.face_id
  LEFT JOIN rfd d ON d.id = u.id
  WHERE u.state IN (1, 2) AND u.id > ?
    AND (d.id IS NULL OR d.user_id <> u.user_id OR d.file_id <> f.file_id
         OR d.cluster_id <> CASE WHEN u.state = 1 THEN u.cluster_id ELSE -1 END)
  ORDER BY u.id LIMIT ?
  ```

- **Stale.** `rfd` rows with no visible own row that are recognizably ours or copies of ours:

  ```sql
  SELECT d.id FROM rfd d
  LEFT JOIN facewright_user_faces u ON u.id = d.id AND u.state IN (1, 2)
  WHERE u.id IS NULL AND d.id > ?
    AND (CHAR_LENGTH(d.face_vector) = 2 OR CHAR_LENGTH(d.face_vector) > 3500)
  ORDER BY d.id LIMIT ?
  ```

  The length test keeps legacy 128-dim rows out (they measure about 2,600) and is deliberate.
  `DetectionService::VECTOR_LENGTH_FLOOR` holds the 3500 until F.2 moves it here.
- **Clusters.** Both directions and title differences, returning ids. An `rfc` row with no own row is
  deleted only if no `rfd` row still references it.

Feed the ids to `pushFaces()` and `pushClusters()`: a push of an id with no visible own row deletes it.
Log counts at warning level when non-zero, because drift means something else wrote.

`lib/BackgroundJob/ProjectionMaintenanceJob.php` extends `TimedJob`, `setInterval(60 * 60)`, registered in
`info.xml`. It honours `processing_paused`, and caps each run with a `limit` of 20,000 rows. A `TimedJob`
needs no re-add when paused: it simply runs again next hour. After a run that finished under the cap,
clear `projection_dirty`, comparing the timestamp it read at the start so a newer failure is not lost.

`occ facewright:project [--user=<uid>] [--dry-run] [--limit=<n>]`: dry-run prints the three counts and
the first 20 ids of each. Without `--limit` it loops until clean.

### E.3 Allocate ids ourselves

Add `FaceStore::addPendingFaces(string $userId, array $faceIds): void`, which inserts rows with no explicit
id, so the autoincrement allocates them. Add `FaceStore::allocateCluster(string $userId, string $title): int`,
which returns `getLastInsertId()`. Use plain inserts: `DetectionService` already filters its targets through
`usersWithRowsFor()`, and the unique index on (`user_id`, `face_id`) turns a logic error into a visible failure
rather than a duplicate row. Stage E.5 removes the explicit-id forms from C.2 once nothing calls them.

### E.4 Seed the counters: `occ facewright:own-tables --seed-ids`

Reads `max(id)` from `rfd` and from `facewright_user_faces`, adds 1,000, and runs
`ALTER TABLE <prefix>facewright_user_faces AUTO_INCREMENT = <n>`. Same for `rfc` and `facewright_clusters`.
The margin guards against rows inserted between the read and the alter. MariaDB syntax: refuse on any other
platform with a clear message. Prints old and new values. Safe to re-run.

### E.5 Switch the writers

One step per service, removing the `rfd`/`rfc` statement and the `facewright_detection_faces` statement,
keeping the `FaceStore` call:

- **`DetectionService::detect()`:** stop writing `rfd` and links; `addPendingFaces()`. No push: pending rows
  are not projected. `encodeVector()` goes in F.2. The row-before-marker invariant stays.
- **`AccessSyncService::copyToUsers()`:** the same; drop `face_vector`.
- **`AccessSyncService::pruneExceptUsers()`:** delete own rows, commit, `pushFaces($idsToDelete)`. Keep
  the deletion of `facewright_faces` when `$keep === []`. Rows are still in the id list so `rfd` follows.
- **`ClusteringService`:** `createCluster()` uses `FaceStore` and returns its id. `persistAssignments()`
  keeps its exact guard, now on `facewright_user_faces`:
  `WHERE id = ? AND user_id = ? AND state IN (PENDING, UNCLUSTERABLE)`, and checks the target cluster in
  `facewright_clusters`. After the loop it calls `pushClusters($targets + $createdClusterIds)`, then
  `pushFaces($writtenIds)`. Order matters: clusters first, so Memories' inner join never sees a face
  with no cluster.
- **`ClusteringService::markUnclusterable()`, `clearUnclusterableMarks()`:** `FaceStore` only, no push.
- **`ClusteringService::resetUser()`:** select the user's visible row ids and cluster ids, `FaceStore::resetUser()`,
  then `pushFaces()`, `pushClusters()`. Keeps `IGNORED`, as today.
- **`PeopleService`:** each public mutator commits, then pushes. Split out private `...InTransaction()`
  forms where one public method calls another inside its transaction: `moveFacesToNewPerson()` is the known
  case, and the `inTransaction()` assertion catches any other. `setFacesCluster()` collects `$sources`
  across batches, then `pushFaces($detectionIds)`, `pushClusters($sources)` after commit.
- **`ResetService`:** delete own rows, then delete the `rfd` and `rfc` rows it collected, directly, in chunks.
  This is the one remaining direct `rfd` write, because a full reset must not depend on the projection.
- **`Command/ReopenUnassigned`:** state update, `pushFaces(ids)`.

Then remove the appconfig switch `block_recognize_writes` from `RecognizeWriteGuardPlugin`. From here on,
allowing Recognize's writes would lose them and would reach `FacePhoto::delete()` with an empty vector.

### E.6 Admin status

`AdminSettings` and `templates/admin.php`: show "Memories sync: up to date" or the time and text of
`projection_last_error`, read from appconfig. Server-rendered, no JS.

**Stage E verify (compose stack):**

1. `occ facewright:own-tables --verify` before starting; snapshot member lists as in D.1.
2. People page: rename, merge, delete person, move, remove, ignore, restore, create. After each, Memories
   shows the result on reload, with no delay.
3. Upload a photo. The row exists in `facewright_user_faces`, not in `rfd`. After clustering, it exists in both.
4. Share to a second user. Rows appear pending for them, and in `rfd` once clustered.
5. Delete a file. It is gone from both.
6. `occ facewright:project --dry-run`: at this point it may list stale hidden rows (F.1 removes them).
   Nothing else.
7. Simulate a Recognize schema change on dev: temporarily point `ProjectionService` at a table name that
   does not exist, in the bind-mounted source. Do not alter the real table: MariaDB DDL cannot be rolled back.
   The People page must keep working and the admin page must show the error. After restoring the name,
   `occ facewright:project` must repair everything.
8. Delete 10 mirrored rows by hand, and run `UPDATE rfc SET title = 'x'` on one row. `occ facewright:project`
   restores both.

---

## Stage F: Contract

**Goal.** Remove what is no longer needed. Destructive, so everything here waits until Stage E has run on
production for at least two weeks with `project --dry-run` clean. Ask the user before starting.

### F.1 Empty the hidden rows, blank the vectors

`occ facewright:project` removes stale hidden rows, because a push deletes any id with no visible own row.

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
from that migration. Update `ResetService` first.

### F.3 Legacy rows: **user decision**

Report `--precheck`'s numbers for legacy rows and for clusters with no own member, split titled and untitled.
Do not delete anything without an explicit answer. If the user approves: dump the id list to a file, rehearse
inside a rolled-back transaction, delete by that list, and print `before − listed = after`.

### F.4 Docs

- `CLAUDE.md`: "Schema mapping" and "Telling our rows from Recognize's" are rewritten around the two tables and
  the projection; sentinels move to `FaceState`; "Coexisting with Recognize" keeps only what still applies,
  and states that a wipe is now recoverable with `occ facewright:project`; `occ` list; `.noimage` handling; Open
  items 4 and 6 updated.
- `README.md`: "Current limitations" gains the read-only Memories note.
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
   `RecognizeWriteGuardPlugin`.
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

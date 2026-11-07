# Owning the face data: review and implementation plan

> ## Where things stand (updated 2026-11-07) — read this first
>
> **Stages A to E are done and live.** Live runs bridge 0.10.0: our tables are the source of
> truth and Recognize's tables are a copy written by `ProjectionService`. The cutover passed every gate and the user
> confirmed in Memories that rename, move and remove-face edits work and show at once. The work is on `master`, not
> pushed. The rollback source is a full dump and the Stage D bridge, taken at the cutover (see "Rollback").
>
> **What is open, in order** (nothing below is started; each destructive step needs the user's go-ahead):
> 1. **Day-after check.** A clean dry run alone proves little, because the hourly `ProjectionMaintenanceJob` repairs drift
>    before anyone looks. The evidence is what it logged. On the live instance (the exact commands and expected numbers
>    are in the operator's private notes, which is where every name and path of that instance lives): `occ
>    facewright:project --dry-run`, `occ facewright:own-tables --verify`, `occ config:app:get facewright_bridge
>    projection_last_error`, and the lines of `nextcloud.log` with `"app":"facewright_bridge"`.
>    Expected: A = C = D = 0, `--verify` exits 0, B no higher than recorded at the cutover (it falls as faces change, it
>    never rises), legacy rows unchanged, and no `projection_last_error` value (the command exits 1 when the key is unset).
>    In the log, read every `repaired N difference(s) ... (A x, C y, D z)` warning and any error. These lines are warnings
>    since 2026-11-06, so an earlier repair left no trace. C after a share is expected: Recognize's own access sync writes
>    rows, and that is what reconcile removes. A or D needs an explanation: a failed push logs an error, Recognize's
>    handlers can delete rows; with neither, a writer is missing its push.
>    **Run on 2026-11-07: clean, but not yet the full check.** No file had been detected, no face created and no share made
>    since the cutover, so a clustering run on new faces and a share have still not happened on live under Stage E. Repeat
>    after those. Also open the admin page and confirm the "Memories sync" line says up to date (never seen in a browser).
> 2. **If A, C or D is not 0:** save the dry-run output first, then find the cause, and only then run `occ
>    facewright:project` (the hourly job may repair it first, so check the log too). Drift that comes back is a missed
>    writer: fix the writer. Rollback tiers are under "Rollback".
> 3. **Stage F** (delete the hidden rows, blank vectors, drop transitional code) waits at least two weeks after the
>    cutover, so not before 2026-11-20, and needs the user's explicit go-ahead.
> 4. **Stage G** (the Memories pull request) posts publicly: only with the user's go-ahead.
>
> **Verification tooling** is under `dev/` (`dev/README.md`; `dev/run-all.sh --ui` on the compose stack, which takes a
> lock because the dev DB is shared). The last full run passed 17 of 17 suites (2026-11-07). The ground-truth
> clustering test (`dev/clustering-truth.sh`, 50 people of 15 faces from the Georgia Tech set, which is fetched, never
> committed) passed 15 of 15.
>
> **Things that bit and will again:** Apache's opcache revalidates every 60 s (`reload_web` after editing source in
> tests); `docker exec -i` is needed for piped stdin; `occ upgrade` applies every pending store update (check
> `occ app:update --showonly`) and Recognize's `MaintenanceJob` can come back after it (delete it in the same chain,
> pipe `y`); a rollback must keep `info.xml`'s version at the installed one.

The plan for moving all face and people data into tables we own, written for a model session that has not seen this
repository before. Part 1 is the review and the design; Part 2 is the work, stage by stage: Stages A to E are condensed
to what was built, found and verified (the original step text is in git history, before this line), F to H are still
the plan. Where the parts differ, Part 2 wins. Where this plan differs from
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

As it stood before this work (`1ef8cc6`); the last column is where each table ended up or will.

| Table | Holds | Owner | Fate |
|---|---|---|---|
| `facewright_faces` | physical face, geometry, `det_score`, embedding | us | keep |
| `facewright_processed` | per-user idempotency marker | us | keep |
| `facewright_cluster_centroids` | cached centroid per person | us | keep, ids stay valid |
| `facewright_merge_dismissals` | dismissed merge pairs | us | keep, ids stay valid |
| `facewright_detection_faces` | `rfd.id` to `facewright_faces.id` link | us | still written, dropped in F.2 |
| `rfd` | per-user row, `cluster_id` state, duplicated geometry, `face_vector`, `threshold` | Recognize | a copy since Stage E |
| `rfc` | people, titles | Recognize | a copy since Stage E |
| `memories_covers` | a detection id per cluster | Memories | read only by Memories; our ids must stay stable |
| `filecache`, mounts | enumeration and access | core | read-only, stays |
| appconfig `recognize/faces.enabled` | Memories' gate | Recognize | stays until the PR ships |

`js/people.js` reads the sentinels from `face.cluster_id`, so the API must keep emitting them.

## What else writes to `rfd` and `rfc`

Code read from Recognize 12.0.2 and Memories 10.1.0, what live ran at the time (under
in the instance's `custom_apps`). Behaviour observed on the dev stack on Recognize 12.0.3 and Memories 11.0.2
(Stage A.2), which live has run since the upgrade recorded under Stage B. The two Recognize versions differ:

| | 12.0.2 (read, gone from live) | 12.0.3 (live and dev, observed on dev) |
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
stale rows (Stage E, category C) matches them by vector length, which holds for both a real vector (over 3500) and the
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
`max(rfc.id)`. Pushing an explicit id moves Recognize's counter past it, so the two still meet: a foreign
row on an id we allocate is overwritten if that face is visible and removed by reconcile if it is hidden (Stage E,
"squatters"). By the projection's rule, unknown rows have no claim on those tables. This is MariaDB-only: PostgreSQL does not advance a
sequence on explicit inserts. Record that as a limitation, consistent with the rest of the project
targeting MariaDB.

## The temporary projection

`ProjectionService` makes `rfd` and `rfc` match our tables:

- **Sparse.** Only `ASSIGNED` and `UNASSIGNED` faces are projected. We then never write a NULL
  `cluster_id`, which removes our part in arming `MaintenanceJob`, and never write the sentinels `-2` and `-3`. Rows
  already there from before the cutover stay until F.1; `--hydrate`, the rollback, writes all of them back.
- **`face_vector`.** `'[]'` on rows the projection creates, never touched on rows that exist (F.1 blanks those).
  Valid JSON, never decoded by Memories. Safe only because the DAV router makes `FacePhoto::delete()`, the one reader
  that needs a real vector, unreachable.
- **One write shape.** Per chunk, `INSERT ... SELECT ... ON DUPLICATE KEY UPDATE` for the visible ids and a delete for
  ids no longer visible; people that exist first, deleted people last. The first design, delete-then-insert, was
  dropped: it races between two pushes and would wipe real vectors.
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
   instance (its names and paths are in the operator's private notes) is reference material:
   the Stage A precheck and its backup were handed to the user, and the live deploys of Stages C to E were done
   with the user's go-ahead for each. Read-only checks on live (`project --dry-run`, `own-tables --verify`,
   the log) are fine; anything that writes, deploys or upgrades needs the user's explicit go-ahead first.
3. **One commit per numbered step**, imperative subject, a body that says why, ending with the
   attribution line the session gives you.
4. **Stop and report rather than improvise** when a count pair does not reconcile, a `--verify` reports
   a mismatch, or a construct in this plan does not exist in `OCP\DB` on the running version.
5. **Deploy mechanics** (from CLAUDE.md, repeated because every stage needs them):
   - Migrations and new background jobs need `<version>` bumped in `appinfo/info.xml` plus `occ upgrade`. A new
     command does not (it is read from `info.xml` at run time; Stage A.4 shipped one that way).
   - Any `js/*.js` change needs the same bump (the cache-buster is `md5($appVersion)`).
   - A new `appinfo/routes.php` entry needs the web container restarted.
6. **Do not touch** `ex_app/`, `Dockerfile`, `Makefile`, `start.sh` or `requirements.txt`. Do not add a
   test framework. Do not change the clustering algorithm or its thresholds. Do not start
   `docs/people-ui-review.md` or `docs/contact-names.md` work inside this plan.
7. **No destructive step without its gate.** Rehearse inside a rolled-back transaction where the plan
   says so, dump id lists before deleting, and print before/after counts that must reconcile.
8. MariaDB before 11.6 rejects an alias on a single-table `DELETE`, and dev is pinned to 10.11 for that
   reason. Correlate with `$qb->getTableName(...) . '.id'`.
9. **How to test** (learned in Stages A to D; they are scripts under `dev/` now):
   - Capture the outputs of every read path on the *old* code, prove the capture deterministic (two captures
     identical), change the code, capture again, and diff. Error-free output is not evidence.
   - Look at the output of every test, not its exit code. Three tests in this project passed without testing
     anything (an out-of-range API parameter, a loop that did not split its input, a setup call that had failed).
   - Every test asserts its own precondition: the rows it needs exist, and something actually changed.
   - Plant a failure to prove each checker can fail, then repair it.
   - Compare time as well as output, on production-sized data. Identical output hid the Stage D regression below.
   - Dev has no dangling, legacy or oddly shaped rows. Before a change reaches live, list what live has that dev
     does not (the precheck's informational counts) and decide how each would behave.

Each stage is deployable and useful alone. Stop at a stage boundary and report.

---

## Stage A: Prerequisites

**Goal.** A dev stack that matches production, proof of the Recognize writers, and the numbers that
decide the rest.

### A.1 Dev stack at the production versions

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

### A.2 Recognize's `FsActionService` writes reproduced

**Done (2026-11-06), on Recognize 12.0.3.** Method: a test file with three clustered rows, a folder
shared to a second user, `occ background-job:execute --force-execute` for each queued Recognize job, and
row counts before and after. Results are in "What else writes". Dev was restored from a dump taken
beforehand. Not covered: 12.0.2's move path (its `fs_moves` columns no longer exist after the 12.0.3
migration, so it cannot run on this database), group folders and external storage.

### A.3 `occ facewright:own-tables --precheck`

Read-only: `lib/Command/OwnTables.php`, `lib/Service/OwnTablesMigrationService.php`.

**Done (2026-11-06).** As built, the command prints these names (the four marked `must be 0` fail the
run): `detections_total`, `detections_linked`, `unlinked_long_rows` (must be 0), `unlinked_other_rows`,
`link_user_mismatch` (must be 0), `links_without_detection`, `links_missing_face` (must be 0),
`duplicate_user_face_groups`, `duplicate_user_face_extra_rows` (must be 0), `dangling_cluster_rows`,
`people_total`, `people_without_members_titled`, `people_without_members_untitled`.
`links_without_detection` is new: A.2 showed that a Recognize deletion leaves links behind. On dev every
number matched independent SQL, and a planted duplicate, an orphan link and a link to a missing face
were all caught (then removed). Bridge version is 0.8.1.

### A.4 Precheck on production, and a backup

**Done (2026-11-06).** A backup was taken first: `face-tables.sql.gz` (the eight
face-related tables, `--single-transaction`) and `bridge-0.8.0.tgz`. The bridge files were copied to live
**without running `occ upgrade`**: `occ app:update --showonly` on live lists a dozen pending store updates,
among them Memories 11.0.2 and Recognize 12.0.3, and `occ upgrade` applies them. So the live copy's
`info.xml` was set back to 0.8.0, which leaves no pending upgrade and registers the command. The next real
deploy (any stage with a migration, Stage C first) needs a version bump and therefore an upgrade: decide then,
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

**What changed during the work.** The first version only refused the writes (a 403 guard). Testing it through Memories'
UI showed what that costs: every edit fails, and a merge shows one error toast per face. So the plugin became a
router: the same hook, but the five requests are applied through `PeopleService`, and refusal remains the fallback for
every shape it does not recognise.

**As built (2026-11-06).** `lib/Dav/RecognizeWriteRouterPlugin.php`,
`lib/Listener/SabrePluginListener.php` (adds it while Sabre is being set up, on `SabrePluginAddEvent`), registered in
`Application::register()`, plus `PeopleService::faceAssignment()`. `<version>` was **not** bumped: nothing in
`info.xml`, JS or the schema changed, and a bump forces an `occ upgrade` on live with its store updates.

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

The setting `block_recognize_writes` was the switch until Stage E removed it (see "The writers" there): `false` handed
writes back to Recognize.

**Deployed to live (2026-11-06).** Copied with the version left at 0.8.0 and no `occ upgrade`: the router
has no migration and no `info.xml` change, and an upgrade would also apply the pending App Store updates
(Memories 11.0.2, Recognize 12.0.3, about ten more). Backups were taken first
(`bridge-pre-router.tgz` and a fresh dump of the face tables). Checked without a login: no new log
errors, `occ` loads, and an unauthenticated DAV request is 401 (a 500 would mean the plugin failed to
build). **Not checked on live:** an authenticated edit. Live runs Memories 10.1.0 and Recognize 12.0.2,
while all router testing was on 11.0.2 and 12.0.3; the request shapes were read from the 10.1.0 bundle and
match, but a real rename in the live Memories UI is the test. Rollback at the time: the switch, or restore the tarball.

**Confirmed on live by the user (2026-11-06):** a merge and several renames through Memories 10.1.0 worked.
The web log shows about 2,400 face `MOVE`s answered 201 and one person-level `MOVE` answered 403, probably a
rename onto a name that already exists (refused by design, as Recognize itself did); not confirmed. No
errors from our code in the log, and the precheck afterwards was clean (the merge removed one person).

**Live upgraded (2026-11-06), with the user's go-ahead.** `occ upgrade` took about three minutes and applied the
pending store updates: Memories 10.1.0 to 11.0.2, Recognize 12.0.2 to 12.0.3, the bridge to 0.8.1 (now identical
to the repo, no pin) and eight others. Preflight showed live's Memories had already run the migration that
failed on dev (dev jumped from 8.1.0), so only two small ones were pending. A full database dump was taken before. Afterwards: data and precheck unchanged, no new log errors from our code.
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

### As built (2026-11-06)

`FaceStore`'s mirroring and the row-for-row `--verify` below were replaced in Stage E; this is what ran until then.

Files: `lib/Migration/Version000007000Date20261106120000.php`, `lib/Service/FaceState.php`,
`lib/Service/FaceStore.php`, `OwnTablesMigrationService` and `Command/OwnTables` extended with `--migrate`,
`--verify`, `--dry-run`, `--chunk`, `--reset-cursor`; every bridge writer dual-writes. Bridge version 0.9.0.
The tables match "Target data model" (`SHOW CREATE TABLE` checked).

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
7. If `occ facewright:migrate-faces` linked more rows after the backfill, `--migrate` was run again. True until Stage E
   only: both commands now refuse once our tables hold faces, because they would overwrite the source of truth.

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

### Stage C on live

**Done on live (2026-11-06), with the user's go-ahead.** Backup first (a full
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

**Goal.** Nothing reads `rfd` or `rfc` except the writers that still mirror to them. One service per step, method
signatures and API JSON unchanged, sentinels emitted through `FaceState::toSentinel()` because `js/people.js` needs them.

### As built (2026-11-06)

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

Fixed: those rows were reopened to `NULL` in Recognize's table (id list dumped first, restore point kept) and
`passProgress` now counts rows written. The chain ended on live and `--verify` is clean. The regression is
`dev/chain-loop-test.sh dangling`; it fails on the code from before the fix (the chain still loops after 12 runs).

### Finding from the code review: the People overview slowed down by two orders of magnitude (fixed)

Stage D moved `PeopleService::clusterListQuery()` from Recognize's table, which has an index on `cluster_id`, to
`facewright_user_faces`, whose index is `(user_id, cluster_id)`, and the join named only `cluster_id`. On live the query
took 26.4 s for a user with several thousand people and ran twice per page. With `u.user_id = c.user_id` in the join it takes 0.19 s,
and `occ facewright:people` went from 26.9 s to 0.57 s and from 9.7 s to 0.46 s. `FaceStore::emptyClusterIds()` had the same
shape (27 ms against 0.15 ms per candidate). Every capture stayed identical throughout, because they compare output, and
dev is too small to feel it. The user noticed it first. Fixed and deployed 2026-11-06.

### Findings from the same review: traps left by the direction change (fixed)

Stage E reversed which table is the truth, but two older commands still assumed the opposite. `own-tables --migrate` copied
Recognize's rows over ours under nothing but a paused flag: planted on dev, it turned an assigned face into a pending one,
and after one of Recognize's resets it would do that to every face. `migrate-faces` would adopt Recognize's copies. Both
now refuse once our table holds faces. The unique `(user_id, face_id)` index, new in Stage C, also made `copyToUsers()` throw
for a user who already had the rows (a crash between rows and marker), so every retry failed and the marker never came
back. All proven failing first in `dev/own-traps.sh`.

### Finding from the `dev/` scripts: parallel DAV moves deadlock (fixed)

Memories sends one MOVE per face, in parallel, when a person is merged. Three of eleven returned 500 on dev
(`Deadlock found when trying to get lock` in `FaceStore::mirrorClusters`, reached from
`RecognizeWriteRouterPlugin::moveFace`): all of them write the same cluster row, and the faces they carried stayed
where they were, so the merge was silently incomplete. Stage B code had the same shape, so live was exposed.

Fixed by `RecognizeWriteRouterPlugin::retryOnDeadlock()`: the whole operation is run again (up to 6 tries, random
pause) when the database reports a deadlock or lock-wait timeout. Safe because the plugin is the outermost caller and
each operation is one transaction. `dev/memories-ui/flows.py` flow 4 is the regression (11 faces merged, 16 expected;
with the retry switched off it ends at 13). The projection's upsert of the same person row got the same retry in
Stage E (`ProjectionService::withRetry()`).

---

## Stage E: Push instead of mirror

**Status: live since 2026-11-06.** See "As built" for what differs from the plan and "Stage E on live" for the cutover.

**Goal.** Our tables are the source of truth; Recognize's `rfd` and `rfc` become a copy that a projection writes.
**This is the point of no easy return:** after it, an older bridge would read stale data, so it has its own
rollback design (below) and a cutover that is gated before processing resumes.

**Decisions (with the user, 2026-11-06).**
- **Single cutover** on live, not a separate observe-only phase. The safety a shadow phase would give is kept by
  running the projection's dry run while processing is still paused (step 3 of the runbook).
- **Hidden rows stay in Recognize's table until Stage F.** Pending, unclusterable and ignored faces are no longer
  written there, but tens of thousands already are; they are not deleted in the cutover. A row leaves when its
  face next changes, and the rest in F.1 with a dumped id list.
- **The verification scripts are committed under `dev/`.**

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
   a visible subset. It is rewritten: ours internally, plus the categories below.
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

### What exists

`ProjectionService` (`push`, `hydrate`, `reconcile`, status), `occ facewright:project`, the hourly
`ProjectionMaintenanceJob` (capped per run, honours `processing_paused`, clears the dirty flag only if nothing newer
failed), `FaceStore` as the primary writer, `--verify` rewritten, `--seed-ids`, and the admin page's "Memories sync"
line. `CLAUDE.md`, "Our own tables, and Recognize's as a copy", states the rules they follow. The rule for a writer:
write ours inside the transaction, commit, then one `push()` with everything touched.

### Reconcile's categories

- **A. Missing or wrong visible faces** (user, file, cluster, geometry compared): must be 0.
- **B. Hidden-state remnants:** Recognize rows whose face is pending, unclusterable or ignored in ours. Reported and
  tolerated until F.1. Found without reading vectors.
- **C. Unknown stale rows:** rows with no row of ours at all that look like copies of ours (vector length 2 or above
  3,500). Must be 0 after a repair. Recognize's legacy rows (about 2,600 characters) are reported separately and
  never touched.
- **D. People:** missing or wrong, or present in Recognize's table with no row of ours and no face referencing them.

Exit status 1 on A, C or D.

### The writers

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

The router's `block_recognize_writes` switch is gone: once vectors can be `'[]'`, Recognize's own "remove face" would
fail, and handing writes back to it would lose them.

### As built (2026-11-06)

Commits `37ebbb4` (projection and reconcile), `8285ebd` (writers), `2ad7d5d` (job, status, no router switch), `c03cbeb`
(squatters), plus the `dev/` suites. Differences from the first design, each found by a test:

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
- **Rollback R2 needed two fixes** (see "Rollback"). **Opcache:** the web process revalidates compiled PHP every 60 s, so any
  test that edits a source file restarts Apache (`reload_web` in `dev/lib.sh`); one concurrency run was contaminated by
  a stale broken copy before this was understood.
- **`recognize:reset-face-clusters`, which the docs say undoes all curation, is recoverable**: after `occ
  facewright:project`, Memories shows exactly what it showed before (`dev/project-recognize-trap.sh`, dev only).

Verified on dev (all suites in `dev/run-all.sh`): reads (49 outputs), the direct-call harness and Memories' own API
identical to the Stage D baselines on unchanged data; People (40 checks), clustering writers (16), DAV battery (44),
file writers (21), Memories UI flows (16), drift repair (24), failure isolation (15), concurrency (96 requests, copy
agrees; fails when the upsert is degraded), Recognize's destructive actions (21), Recognize's copies and squatters
(26), rollback drill (23, on its own after the fix; see the top of this document), and clustering against ground truth (50 people, 750 faces, pure and complete).

The admin page's new line has been rendered but not looked at in a browser.

### Stage E on live (2026-11-06)

Cut over with the runbook below, in this order, each step checked before the next:

1. Processing paused; nothing running. A full dump of the one database, a tarball of the live bridge files, and the
   counts and checksums of Recognize's two tables were saved outside the repo (live files were
   byte-identical to the Stage D tip `3c0cba4`, so the tarball is the R2 rollback source). Stage D's `--verify` was clean.
   **One precondition was not met as written:** the runbook asks for Stage D on live for a few days, and it ran for
   hours; the user chose to go ahead. Share and unshare have therefore still not been seen on live, under Stage D or E.
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

Afterwards the job's `repaired` line was raised from info to warning, because live logs at Warning and a repair left no
trace: one file copied, no version bump, no `occ upgrade`.

Then the review fixes went to live on 2026-11-07, in two file copies (ten files, then seven, the second closing the `--migrate` trap), no version bump, no `occ upgrade`, after `dev/run-all.sh
--ui` passed 17 and then 18 of 18: `persistAssignments()` writes through `FaceStore`, the
job counts what it really deleted, clears `projection_last_error` once clean and skips its recount when it found nothing,
and the deadlock retry is shared (`DeadlockRetry`). The comment-only changes went with them, so live matches the repo.

Still to do: the day-after check at the top of this document, and then at least two weeks before Stage F.

### Rollback

- **R1, before unpausing at the cutover:** restore the previous files. Expired at the unpause on 2026-11-06.
- **R2, after use:** pause processing and wait for running jobs. Check `occ facewright:project --dry-run` is clean and
  run `occ facewright:project` if not, because hydrate deletes nothing and a stale row would stay. Then `occ
  facewright:project --hydrate` writes every state back into Recognize's table. Restore the Stage D files **with
  `info.xml`'s `<version>` set to the installed one** (`occ config:app:get facewright_bridge installed_version`), run
  the Stage D checks, unpause. Nothing is lost. Rows hydrate creates carry `'[]'` vectors, which Stage D code tolerates;
  rows that exist keep theirs, and pending rows come back as `NULL`, so make sure `MaintenanceJob` is not in `oc_jobs`.
  Two things the dev drill found: the restored files' own version is the OLD one, and a mismatch makes Nextcloud demand
  an upgrade (no `occ` commands, web 503), while `occ upgrade` would apply every pending store update; and Nextcloud may
  drop the hourly job from `oc_jobs` while its class is missing (when cron walks the job list), so **rolling forward
  again** needs `occ app:disable facewright_bridge && occ app:enable facewright_bridge` (and Recognize's
  `MaintenanceJob` deleted in the same chain, as after any enable). R2 stops being possible at F.2.
- **R3:** restore the full database dump taken before the cutover. Loses edits made since; last resort.

Drill on dev: cut over, use it, hydrate, revert, then run the Stage C and D suites and the Stage C `--verify`.

### Live cutover runbook

Needs the user's go-ahead and a quiet window they choose. Preconditions: dev acceptance green, Stage D on live for
a few days with `--verify` clean, the clustering fix deployed, and `occ app:update --showonly` reviewed (the version bump
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

`dev/README.md` says what each script checks and how to run it on the compose stack.

---

## Stage F: Contract

**Goal.** Remove what is no longer needed. Destructive, so everything here waits until Stage E has run on
production for at least two weeks (not before 2026-11-20) with the checks at the top of this document clean, repeated
after clustering runs and shares, and every repair the job logged explained. Ask the user before starting, and take a fresh full dump first:
the pre-cutover one will be too old to restore.

**Order: F.1, F.3, F.2, F.4.** F.3's numbers need the link table that F.2 drops.

### F.1 Empty the hidden rows, blank the vectors

Stage E leaves the hidden-state rows (pending, unclusterable, ignored) in Recognize's table; reconcile reports them
as category B and tolerates them. Here they are removed in bulk: dump the id list to a file, delete by that list in
chunks, and print `before - listed = after`, which must reconcile exactly. No command exists for this yet: add one to
`OwnTablesMigrationService`, using the same selection as reconcile's B. Never do this while any mirror-era code is
deployed (Stage E, "What changed", item 1).

New option `occ facewright:own-tables --blank-vectors [--dry-run] [--chunk=<n>]`: resumable by keyset on
`id`; `UPDATE rfd SET face_vector = '[]' WHERE id IN (...)`, selecting ids with a matching row in
`facewright_user_faces`. Print `SUM(CHAR_LENGTH(face_vector))` before and after.

Large rewrites belong in commands, not migrations. Disk is reclaimed only by rebuilding the table, which
is an `ALTER TABLE ... FORCE` in a maintenance window. Leave that to the user.

**Verify:** `rfd` holds only `cluster_id > 0` or `-1` rows, apart from legacy rows; Memories unchanged.

### F.2 Delete the transitional code

Ends rollback tier R2: Stage D code needs the links table.

Delete `DetectionService::ownRowsExpr()`, `encodeVector()`, `VECTOR_PRECISION`. Move `VECTOR_LENGTH_FLOOR` to
`ProjectionService` as a private constant with its reason, once `OwnTablesMigrationService` no longer uses it. Delete
`VectorCompactionService`, `Command/CompactVectors`, `FaceMigrationService`, `Command/MigrateFaces`,
`Command/ReopenUnassigned` and their `info.xml` entries. Move the sentinel values from `ClusteringService` into
`FaceState` (`ClusteringService::UNASSIGNED`, `UNCLUSTERABLE`, `IGNORED`; `FaceState` and `ProjectionService` use them
today), keeping an in-memory `UNCLUSTERABLE` marker only if clustering still needs it. Reduce
`OwnTablesMigrationService` and `Command/OwnTables` to what is still used: `--verify` without the link checks, and
`--precheck`, `--migrate` and `--seed-ids` removed. Remove `project --hydrate`, which has no use once rollback is gone.

New migration: drop `facewright_detection_faces`, only after `grep -rn detection_faces lib/` is empty apart
from that migration. Stop the writes in `DetectionService` and `AccessSyncService` and the reads and deletes in
`ResetService` first. The links table is kept until here on purpose: rollback to Stage D code needs it.

### F.3 Legacy rows: **user decision**

Before F.2. Report the legacy count from `project --dry-run` and `--precheck`'s numbers for clusters with no own member,
split titled and untitled. Do not delete anything without an explicit answer. If the user approves: dump the id list to
a file, rehearse inside a rolled-back transaction, delete by that list, and print `before − listed = after`. Then the
one read of Recognize's table goes too: the legacy test in `FaceStore::emptyClusterIds()` (which `deleteEmptyUntitled()`
uses) and `PeopleService::moveLegacyMembers()`.

### F.4 Docs

- `CLAUDE.md`: drop `ownRowsExpr()`, the vector bands, `face_vector`'s remaining rules and the commands F.2 removes;
  the `occ` list; Open item 6.
- `README.md`: no Memories note is needed; editing keeps working.
- `docs/independence.md`: mark what is done.

---

## Stage G: Memories pull request

**Do not start this without the user.** It is a proposal to another project, and it should start with a
conversation. Writing code or posting anything publicly needs the user's explicit go-ahead.

### G.1 Prepare the proposal

A new `docs/memories-people-backend.md` in this repo:

- **Today's blockers, verified on 10.1.0 and, for `BACKENDS`, on 11.0.2; recheck the rest on the current release:** `lib/ClustersBackend/Manager.php` holds a constant `BACKENDS`
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

1. Stop calling `ProjectionService`; remove it, the job, the command and
   `RecognizeWriteRouterPlugin`.
2. Decide with the user whether Recognize can be uninstalled. Photos' People view stops working if it is.
   If yes: `.noimage` markers become irrelevant, CLAUDE.md's coexistence section goes, and `rfd`/`rfc` can be
   dropped only after a dump.
3. If upstream refused: skip this stage. The projection stays, and this doc's "temporary" wording changes.

---

## Deviations

From `docs/independence.md`:

1. **Stage 2's shim is not planned.** Recognize stays installed and passive; our router answers writes on its DAV tree.
2. **M7 is out of date.** The server side is not pluggable: `Manager::BACKENDS` is a constant (10.1.0 and 11.0.2).
3. **`-1` semantics are kept for Memories only.** Internally this is a state.
4. **Stage 3(a) becomes the primary target**, not an optional offer.
5. **The doc's Finding 2 (remove face does not stick)** is fixed by the DAV router, not by a shim.
6. **Its version references** (Memories 8.1.0, `ClusterMergeService`) are stale: live is 11.0.2, and
   that service was deleted. Stage 0's `ClusterMergeService` references are historical.

From `docs/independence-implementation.md`:

1. **Deviation 2 is reversed.** Assignment state moves out of `rfd`, and the `state`-column idea returns,
   without `rejected_cluster_id`, which is still not needed.
2. **Deviation 3 changes.** `face_vector` is blanked to `'[]'`, not dropped: the column belongs to real
   Recognize, which stays. That doc rejected `'[]'` because of `FacePhoto::delete()`; the DAV router makes
   that path unreachable.

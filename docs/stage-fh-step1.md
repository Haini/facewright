# Stages F and H, step 1: bridge 0.13.0 (implementation plan)

Written for a model session that has not seen this repository before. It covers **step 1** of the Stage F/H sequence
(`docs/memories-fork.md`, "Decision after M11", Option 2): one bridge release, 0.13.0, that removes every piece of
code that only served Recognize as a reader or as a way back. Nothing here touches the live instance, and nothing is
pushed.

The user made every decision below (2026-11-21 and 2026-11-27, recorded in `docs/cleanup.md` section 0). Do not
reopen them. If the code contradicts this document, stop that step, write down what you found under its "Done"
heading, and stop; do not work around it.

## What 0.13.0 is, in one paragraph

The bridge writes one copy for Memories: the `own` tables (`oc_facewright_bridge_face_detections` / `_clusters`),
which the fork of Memories reads. It no longer writes, reads or repairs Recognize's tables
(`oc_recognize_face_detections` / `_clusters`, "rfd" / "rfc"), no longer answers writes on Recognize's DAV root, and no
longer has a rollback path to Recognize or to the Stage D code. The one-time migration commands go. The link table
`facewright_detection_faces` is no longer written or read, but **stays in the schema**: 0.14.0 drops it after a week on
live (step 4 of the sequence, not this document). Recognize stays installed and disabled; removing it is step 5.

## Decisions (do not reopen)

1. **One target.** `ProjectionService` writes only `own`. The app config `projection_targets` is no longer read. Do
   not delete it anywhere (on live it stays `own`, which keeps a rollback to 0.12.6 correct).
2. **No `recognize` DAV root.** The router answers writes only under `facewright_bridge/`. It is renamed
   `PeopleWritePlugin` (`lib/Dav/PeopleWritePlugin.php`).
3. **Legacy rows (F.3): dropped, not carried.** The legacy test in `FaceStore::emptyClusterIds()` and
   `PeopleService::moveLegacyMembers()` go. A person whose only members were Recognize's legacy rows now counts as
   empty; that is accepted.
4. **F.1 is skipped** (no blanking, no table rebuild). Recognize's tables are left exactly as they are: frozen, and
   dropped in step 5.
5. **The link table**: no writes, no reads from 0.13.0 on; the table stays until 0.14.0.
6. **Kept, out of scope**: `occ facewright:merge-clusters` and the admin merge form; the `Coexisting with Recognize`
   section of `CLAUDE.md` (it goes in step 5, with the app). `lib/Migration/` is history and is not edited.
7. **The "Show the People page" admin toggle goes.** It writes Recognize's `faces.enabled`, which only stock Memories
   reads (the fork ignores it when the provider is not `recognize`).

## How to work this document

- **Read `CLAUDE.md` first**, in full, then `dev/README.md`. The rules that matter most here:
  - `routes.php`, `info.xml` and JS changes need a `<version>` bump in `facewright_bridge/appinfo/info.xml` plus `occ
    upgrade`; route changes also need a container restart (`docker restart recognaize-nextcloud-1`). Otherwise you
    see 404s, old JS or stale command lists while the source looks right.
  - The bridge is bind-mounted into the dev container (`docker-compose.yml`), so PHP edits are live (opcache
    revalidates on a timer; `reload_web` in `dev/lib.sh` makes it immediate).
- **Dev only.** The compose stack in this repo is where you work. **Never touch the live instance** (any container
  not named `recognaize-*`). Never run `make build` / `make run`: on this host they hit the live ExApp.
- **No PHP or Node on the host.** Lint in the dev container: `docker exec recognaize-nextcloud-1 php -l <path under
  /var/www/html/custom_apps/facewright_bridge/>`.
- **Nothing is pushed.** Commit locally, **one commit per step**, with the attribution lines your session gives you.
  Commit before running `dev/run-all.sh`.
- **Do not leave background processes** (`tail -f`, `sleep` loops, watchers) running when you finish a step.
- **Check outputs, not exit codes.** Where a check can fail on the old code, show that it does (a planted fault, or
  the old code). Record under each step's "Done" heading what you ran and what came out, briefly.
- `dev/run-all.sh --ui` takes a while (it restores a snapshot before every suite). Run it at the end of S1, S3 and
  S4, not after every edit; run single suites in between.

---

## S0. Dev baseline in fork mode, and a snapshot of our own tables (bridge code unchanged)

Why first: the dev snapshot is restored by rebuilding our tables from Recognize's with `occ facewright:own-tables
--migrate`, which S3 deletes. And the dev baseline is still stock mode (Recognize enabled, provider unset, target
`recognize`), while live has been fork mode since 2026-11-21. Both change here, **on the 0.12.6 code**, so that S1 can
prove the suites on the old code before any code is removed.

1. **Keep the old snapshot.** `cp dev/.work/snapshots/original.sql dev/.work/snapshots/original-stock.sql`. Do not
   delete any file in `dev/.work/snapshots/`.
2. **Restore it with the old script** (`dev/snapshot.sh restore original`, unchanged) so the stack holds known data.
3. **Switch the stack to the live shape**, in this order:
   - `occ config:app:set facewright_bridge projection_targets --value=own`
   - `occ facewright:project` (fills the own tables; must end "now match ours")
   - `occ config:system:set memories.recognize.provider --value=facewright_bridge`
   - `occ app:disable recognize`, then `reload_web`. Delete any Recognize `MaintenanceJob` / `ClusterFacesJob` rows
     left in `oc_jobs` (pattern: `with_recognize_off` in `dev/run-all.sh`).
4. **New `dev/snapshot.sh`.** A snapshot holds **our** tables, not Recognize's:
   `oc_facewright_faces oc_facewright_user_faces oc_facewright_clusters oc_facewright_cluster_stats
   oc_facewright_processed oc_facewright_cluster_centroids oc_facewright_merge_dismissals oc_memories_covers`
   (not the link table, not Recognize's, not the own copy). `mariadb-dump` writes `DROP TABLE`/`CREATE TABLE` with the
   `AUTO_INCREMENT` value, so the id counters come back too. `restore`: load the dump, `TRUNCATE` the two own copy
   tables, delete `ClusterScanJob` rows from `oc_jobs`, delete the `projection_dirty_seq` / `projection_clean_seq` /
   `projection_last_error` keys, `occ facewright:project` (fills the copy), then `occ facewright:own-tables --verify`
   as now. Remove `--migrate`, `--seed-ids`, the pause and `own_tables_after_id` from it. Update the usage comment.
   - **Guard:** `restore` refuses a snapshot file that contains `oc_recognize_face_detections` (an old-format file),
     with a message naming `original-stock.sql` as the old format.
5. **Save** the new `original` with the new script (`dev/snapshot.sh save original`), then **restore** it.
6. **`dev/run-all.sh`:** the baseline is now what `with_own`, `with_provider` and `with_recognize_off` used to set up.
   Do not change the suite list yet (S1 does); only make sure the run starts from the new baseline. A suite that
   needs Recognize enabled must enable it itself (S1 handles the one that does).
7. **`dev/README.md`:** the baseline (fork mode, Recognize disabled, `projection_targets=own`, provider set) and the
   snapshot format, in a few lines.

**Verify:**
- `dev/snapshot.sh restore original` prints "verify clean"; `occ facewright:project --dry-run` reports 0 in every
  class for `own`.
- Row counts of `oc_facewright_user_faces`, `oc_facewright_clusters` and `oc_facewright_bridge_face_detections` are
  identical after two restores in a row, and equal to the counts after step 3.
- Restoring `original-stock.sql` with the new script is refused with the message from step 4.
- `occ app:list` shows `recognize` disabled; `occ config:system:get memories.recognize.provider` prints
  `facewright_bridge`.

**Done:** (2026-11-28, 0.12.6 code)
- Kept `original.sql` as `original-stock.sql`; restored it with the old script (311 people, 2797 faces); set `projection_targets=own`,
  `occ facewright:project` (2791 faces, 311 people repaired, "now match ours"), provider `facewright_bridge`, `app:disable recognize`,
  `reload_web`; no Recognize job rows left in `oc_jobs`. Counts after that: user_faces 2797, clusters 311, own detections 2791.
- New `dev/snapshot.sh` (our eight tables, restore refills the copy by `occ facewright:project`, guard for the old format);
  saved `original` with it. `run-all.sh` got a baseline check (exit 2 unless provider, target and Recognize-disabled hold); the
  wrappers and suite list are untouched (S1). `dev/README.md` got a "Baseline and snapshots" section.
- Verify: `restore original` twice printed "verify clean" both times, counts 2797 / 311 / 2791 each time (equal to after step 3);
  `project --dry-run` 0/0/0/0 for `own`; `restore original-stock` printed the refusal naming `original-stock.sql`, rc 2;
  `occ app:list` lists recognize under Disabled; provider prints `facewright_bridge`.

---

## S1. The dev suites check the own copy (bridge code still 0.12.6)

Every suite that asserts what Memories sees, or sets up state, through Recognize's tables or tree is moved to the own
copy and our tables. Suites that exist only for the Recognize side are deleted. All of this is green **on the 0.12.6
code** before S2 removes anything: that is the proof that the suites test behaviour, not the code being removed.

Find the work with `grep -ln 'recognize_face\|rfd\|rfc\|TREE\|api_key\|PROVIDER\|with_own\|with_provider\|recognize_off' dev -r`.

Rules for converting a suite:
- **"What Memories shows"** (a face is in a person, a person exists or has a title): read
  `oc_facewright_bridge_face_detections` / `oc_facewright_bridge_face_clusters`. The copy is sparse like Recognize's
  was (only assigned faces, as the person id, and unassigned ones, as `-1`), so a check like "hidden: not in
  Recognize's table" becomes "not in the own copy".
- **"Our state"** (pending, unclusterable, ignored): read `oc_facewright_user_faces.state` (`FaceState` constants).
- **Setting up state** by writing Recognize's table (`chain-loop-test.sh`, `clustering-truth.sh`): write our tables
  instead. Where the point of the test was "the copy holds a wrong value", plant that wrong value in the own copy
  (as `project-drift.sh` does) and expect reconcile or the next push to correct it. Keep each test's intent; rewrite
  its header comment to say what it tests now.
- **Checksums / "nothing was written" lists** (`cluster-compare.sh`, `cluster_inmemory.php`): replace Recognize's two
  tables with the two own copy tables.
- `lib.sh`: `own_oracle` compared the two targets with each other; with one target it has nothing to compare. Delete
  `own_oracle`, `own_oracle_counts` and `own_configured`, and their call in `verify_step`. Delete `api_key_off` /
  `api_key_on` (Recognize's API key).
- `battery.sh`: the tree under test is `facewright_bridge` only (no `TREE` choice, no `api_key`). Keep the
  cross-tree checks as "a Destination in another DAV root is refused" with `recognize/` as that other root: they must
  still answer 403. Keep the `unassigned-faces` 405 check unconditional.
- `memories-ui/flows.py`: the provider is `facewright_bridge` only; remove the `PROVIDER` choice and the `TABLES` map
  (keep the own tables).
- `memories-ui/admin_provider.py` is the one suite that needs Recognize **enabled** (it lists both providers and starts
  with the provider unset). In `run-all.sh`, run it inside a wrapper `with_stock_memories` that remembers the
  provider value, deletes it, enables Recognize, runs the suite, disables Recognize, deletes Recognize's
  `MaintenanceJob` / `ClusterFacesJob` rows, restores the provider value and calls `reload_web`. Note in its comment
  that step 5 (Recognize uninstalled) changes this suite.

Suites to **delete** (and their `run-all.sh` lines): `project-recognize-trap.sh`, `project-recognize-copies.sh`,
`rollback-drill.sh` (Stage D; the tag `pre-stage-f` keeps it), `dav-battery` on the `recognize` tree,
`dav-battery-recognize-own`, the stock `memories-ui` flow, and the `-own` duplicates that the baseline makes identical
to their plain versions (`writers-people-own`, `writers-cluster-own`, `writers-files-own`, `persist-guard-own`,
`project-concurrency-own`, `dav-battery-own`, `memories-ui-bridge` vs `memories-ui-bridge-off`: keep one of each,
under the plain name). Delete the wrappers `with_own`, `with_provider`, `with_recognize_off`. `own-traps.sh`: delete
the traps that plant rows in Recognize's table; if nothing is left, delete the suite. `project-own.sh`: drop the
checks about the other target ("the other target was written", Recognize's checksums); what remains is the
projection suite.

Leave the projection's own tests (`project-drift.sh`, `project-failure.sh`, `project-concurrency.sh`) working against
the own copy. `project-failure.sh` plants a fault by editing "the first statement" of `upsertFaces()`; make it edit
`upsertFacesOwn()` now, and in S2 (where that method is renamed) follow the rename.

**Verify:**
- `dev/run-all.sh --ui` on the 0.12.6 code: every suite passes. Record the count (it was 42 before; it is smaller now)
  and list the suites deleted.
- **Planted fault, must fail:** `occ config:app:set facewright_bridge projection_targets --value=recognize`, then run
  `writers-people.sh` and `battery.sh` (each after a snapshot restore): both must fail on checks that read the own
  copy. Set it back to `own` and restore.
- `grep -rn 'recognize_face' dev` finds only `snapshot.sh`'s guard, the `with_stock_memories` wrapper if it needs one,
  and comments that say why (list what is left).

**Done:**

---

## S2. One target, one DAV root (bridge 0.13.0)

Bump `facewright_bridge/appinfo/info.xml` to **0.13.0** here (routes change), `occ upgrade`, restart the container.

1. **`ProjectionService`:**
   - Remove `TARGET_RECOGNIZE`, `TARGET_OWN`, `KEY_TARGETS`, `TARGET_TABLES`, `targets()`, `isTarget()`, `label()`,
     the per-target maps and every `$target` parameter. The tables are the own pair, as private constants.
     `available()` keeps its probe (MariaDB, our tables, the own tables with the columns the statements name).
   - `upsertFaces()` (the Recognize one, with `face_vector` and `threshold`) goes; `upsertFacesOwn()` becomes
     `upsertFaces()`. Fix `dev/project-failure.sh` to match.
   - `hydrate()` goes.
   - `reconcile()`: A, C, D only, as the `own` branch does today. No B, no legacy count, no squatter pass, no
     `STALE_EMPTY_LENGTH`, no vector read, no `notReferenced()` (the own branch already returns `''`). The report's keys
     `B` and `legacy` go; check every reader of the report (`Project`, `ProjectionMaintenanceJob`,
     `OwnTablesMigrationService::verify()`, `dev/`).
   - Add `clear(): void`: deletes every row of the two own tables (used by `ResetService`, S3). Never throws to its
     caller, like `push()`: on failure it logs and marks dirty.
   - Rewrite the class docblock: one copy, the own tables, for the fork of Memories; the sparse rule; the upsert shape;
     push order; outside transactions; never throws. Drop the Recognize-specific paragraphs (MaintenanceJob, `face_vector`).
2. **`Command/Project`:** no `--hydrate`, no `--target`. Output names the own tables. Exit codes as today.
3. **`ProjectionMaintenanceJob`:** one reconcile, no loop over targets.
4. **`OwnTablesMigrationService::verify()`:** the projection check is one reconcile of the own copy, with the check
   names `A_visible_faces_wrong`, `C_stale_rows`, `D_people_missing`, `D_people_stale` (all `zero`). No
   `B_hidden_remnants`, no `legacy_rows_untouched`. (The link checks go in S3.)
5. **The DAV router:** `ROOTS` is `['facewright_bridge']`; rename the class and file to `PeopleWritePlugin`, update
   `SabrePluginListener`, `Application`, `Dav/Tree/*` comments. Keep the same-tree rule for `Destination` (a
   destination under any other root is 403). Rewrite the docblock: it applies the writes Memories sends to this app's
   tree; drop Recognize, Photos and `--hydrate`.
6. **The admin toggle:** remove `PeopleSettings#setEnabled` (route `/people-enabled`), the controller method, the
   template block (the "Show the People page" checkbox and the two paragraphs above it), its `bindToggle` line in
   `js/admin.js`, and `enabled` / the `recognize` app config read in `AdminSettings`. Keep the section heading if the
   remaining admin page needs it; the intro sentence says what the app does for Memories, in one line, without
   Recognize. Fix the class docblock of `PeopleSettingsController` (it explains the toggle).
7. **Strings that name Recognize as where writes go** (admin template, `Project` description): reword to the own
   copy / the fork of Memories.

**Verify:**
- `php -l` on every changed PHP file. `occ app:list` shows `facewright_bridge: 0.13.0`.
- `grep -rn "recognize" facewright_bridge/lib facewright_bridge/templates facewright_bridge/js facewright_bridge/appinfo
  -i | grep -v 'lib/Migration/'`: list what is left. Allowed after S2: S3's targets (legacy reads, `ResetService`,
  comments in services S3 rewrites) and plain-language comments. Not allowed: any table name or DAV root `recognize`
  in code that runs.
- `occ facewright:project --dry-run` 0 in every class; `occ facewright:project --target=own` and `--hydrate` are
  refused as unknown options.
- `occ facewright:own-tables --verify` exits 0 and prints no `B_` / `legacy` line.
- Unauthenticated `PUT /apps/facewright_bridge/people-enabled` answers 404 or 405 (after the restart), and the admin
  settings page renders (`curl` as admin, or Playwright) without the checkbox.
- DAV: `MKCOL .../remote.php/dav/recognize/<admin>/faces/X` as admin does not create a person in our tables (Recognize
  is disabled, so the tree is gone: record the status code), and `battery.sh` passes.
- **Planted fault:** put `'recognize'` back into `ROOTS` and run `battery.sh`: the cross-root check must still pass
  (the same-tree rule), but record whether a write under `recognize/` is now applied; remove the fault. Then make
  `upsertFaces()` write nothing (return early) and run `writers-people.sh`: it must fail; remove the fault.
- `dev/run-all.sh --ui` (commit first): every suite of S1 passes.

**Done:**

---

## S3. Dead code, the sentinels, the link table and the legacy reads (still 0.13.0)

1. **Delete** `Service/VectorCompactionService`, `Command/CompactVectors`, `Service/FaceMigrationService`,
   `Command/MigrateFaces`, `Command/ReopenUnassigned` and their `<command>` lines in `info.xml`.
2. **`DetectionService`:** delete `VECTOR_PRECISION`, `VECTOR_LENGTH_FLOOR` (and its long comment of length bands),
   `encodeVector()`, `ownRowsExpr()`. Stop the insert into `facewright_detection_faces`. Read what `detect()` does with
   the link insert's surroundings (the per-user loop) before removing it; nothing else may change there.
3. **`AccessSyncService`:** stop the link insert (around line 397) and the link delete (around line 446). The
   `deleteFaces()` and `push()` calls stay. Reword the comment about Recognize's own prune ("would delete this
   instance's legacy rows") to what the code guards against without Recognize, or drop it.
4. **`ResetService`:** no statement on Recognize's tables or the link table. After deleting ours, call
   `ProjectionService::clear()` (outside any transaction). `deleteOrphanedFaces()` tests "no row of
   `facewright_user_faces` has this `face_id`" instead of the link table. Docblocks: drop "Recognize's legacy data
   untouched", here and in `Command/ResetFacewrightData` (description included).
5. **Sentinels into `FaceState`:** move `ClusteringService::UNASSIGNED` (-1), `UNCLUSTERABLE` (-2), `IGNORED` (-3) to
   `FaceState` as `SENTINEL_UNASSIGNED`, `SENTINEL_UNCLUSTERABLE`, `SENTINEL_IGNORED`; update every caller
   (`grep -rn 'ClusteringService::\(UNASSIGNED\|UNCLUSTERABLE\|IGNORED\)' facewright_bridge/lib`). The long comments
   above them in `ClusteringService` explain Recognize's predicates and its HDBSCAN; replace them by one short comment
   each in `FaceState`, saying what the state means here and that the API and `js/people.js` read the number. If
   `ClusteringService` needs an in-memory "unclusterable" marker, it uses the `FaceState` constant.
6. **Legacy reads (F.3):** `FaceStore::emptyClusterIds()` loses the `NOT EXISTS` over Recognize's table;
   `PeopleService::moveLegacyMembers()` goes, and so does the `+ $legacy` in what merge and delete report. Fix the
   docblocks that mention it (`FaceStore` class docblock, `emptyClusterIds`, `PeopleService` around lines 300, 389,
   626, 689).
7. **`OwnTablesMigrationService` and `Command/OwnTables`:** keep `verify()` (without `copies_without_link`,
   `links_without_copy`, `link_user_or_face_mismatch`) and whatever `--recount` uses; delete `precheck()`,
   `migrateClusters()`, `migrateFaceRange()`, `seedIds()`, the cursor methods, `MUST_BE_ZERO`, `SEED_MARGIN` and every
   private helper only they used. Rename the class to `OwnTablesCheckService` (update its users). The command keeps
   its name and only `--verify` and `--recount`; its docblock lists those two.
8. **Comments that explain Recognize** in files you touched anyway (`ClusteringService` around lines 48 and 592,
   `FaceVectorCodec`, `ProcessedFileService`, `FacePreviewController`, `ClusterScanJob` line 87): reword where they
   claim something about Recognize's tables that is no longer read or written. Do not rewrite comments in files you
   do not otherwise touch, apart from these.

`occ list facewright` must not show the three removed commands. If it still does, restart the container; if it
still does after that, `occ upgrade` with the version unchanged does nothing, so record what you saw and stop.

**Verify:**
- `php -l` on every changed file; `occ list facewright` shows exactly: detect, scan, cluster, cluster-scan,
  cluster-compare, embedding-checksums, merge-clusters, backfill-access, init-user-settings, reset, people, own-tables,
  project.
- These greps over `facewright_bridge/lib` (excluding `lib/Migration/`) are **empty**: `recognize_face`,
  `detection_faces`, `VECTOR_LENGTH_FLOOR`, `VECTOR_PRECISION`, `ownRowsExpr`, `encodeVector`, `moveLegacyMembers`,
  `ClusteringService::UNASSIGNED`, `ClusteringService::UNCLUSTERABLE`, `ClusteringService::IGNORED`, `hydrate`,
  `TARGET_`. Show the commands and their (empty) output.
- **The link table is not written:** record `SELECT COUNT(*), MAX(detection_id) FROM oc_facewright_detection_faces`,
  run `writers-files.sh` (it detects, shares and unshares), and show both values unchanged while
  `oc_facewright_user_faces` changed.
- **Reset:** on a restored snapshot, `occ facewright:reset --yes`: both own copy tables are empty, our tables are
  empty, `oc_recognize_face_*` row counts are **unchanged** (record before/after), `project --dry-run` is clean.
  Restore the snapshot.
- **Legacy:** plant a Recognize row pointing at an untitled person of ours that has no face of ours
  (`INSERT` into `oc_recognize_face_detections` with a new id, `'[]'` vector), call `occ facewright:people <user>
  --delete-empty`: the person is deleted now (on 0.12.6 it was kept: show that with `git stash` or by reasoning from
  the removed clause, and say which). Restore the snapshot.
- `occ facewright:own-tables --verify` exits 0; the three link checks are gone from its output.
- `dev/run-all.sh --ui` (commit first): every suite passes.

**Done:**

---

## S4. Docs

Make the docs describe 0.13.0. Keep each change to what the code change makes untrue; do not narrate the history.

- **`CLAUDE.md`:**
  - Architecture: fork mode is the only mode. Stock mode, its three dependencies and "why Recognize must be enabled"
    go; one sentence says stock Memories (and Photos' People view) no longer work with this app, and that the way back
    is the tag `pre-stage-f` (`docs/cleanup.md` section 0).
  - "Writes from Memories": one root, the new class name.
  - Components: `ProjectionService` (one copy), `lib/Dav/`, `ResetService`; the "Show the People page" toggle is not in
    the admin section any more; `FaceState` holds the sentinels; the `occ` list loses the removed commands and options.
  - Invariants: "Schema mapping" loses the vector rules (`face_vector`, precision, the bands) except what still
    holds for the own tables (normalised fractions; the own tables have no `face_vector` and their column order is
    whatever their migration made: check `Version000009000Date20261120000000.php` before writing the sentence).
    `facewright_detection_faces` is "no longer written or read; dropped in 0.14.0". "Our own tables, and Recognize's as
    a copy" becomes "Our own tables, and the copy for Memories": no hidden-state remnants, no squatters, no legacy
    test, no `--hydrate`, ids are ours alone (drop the "counters seeded above Recognize's" paragraph). "Telling our rows
    from Recognize's" goes, apart from one line: Recognize's tables are frozen and nothing of ours reads or writes them.
  - "Coexisting with Recognize": keep it (step 5 removes it), but put one line at its top: **0.13.0 has no router for
    Recognize's DAV tree, so Recognize must not be enabled again while it runs** (its own handlers would get Photos'
    writes); the rest applies only on the way back from the tag.
  - Open item 6 (row-at-a-time SQL): drop the link insert from it.
- **`README.md`:** requirements: the fork of Memories is required; Recognize is not.
- **`docs/own-tables.md`:** "Where things stand" (top): 0.13.0 built on dev, not deployed. Mark F.1 skipped (decided
  2026-11-27), F.2 done except the link-table migration (0.14.0), F.3 done (dropped), H.1 done. One line each.
- **`docs/cleanup.md`:** tick 4.1 and the parts of 4.2 that are done; "State this assumes" stays as it is (live).
- **`docs/memories-fork.md`, Option 2:** step 1 built on dev (this document).
- **`dev/README.md`:** the suites removed and why, in one paragraph.
- **`docs/README.md`:** a row for this document.

**Verify:** `grep -n 'hydrate\|--target\|seed-ids\|precheck\|compact-vectors\|migrate-faces\|reopen-unassigned\|people-enabled\|stock mode' CLAUDE.md README.md`
finds only historical mentions that say they are gone. Run `dev/run-all.sh --ui` once more after the docs commit only
if a doc change touched `dev/`.

**Done:**

---

## For the user: deploying 0.13.0 to live (not part of the implementation)

Each line with the go-ahead; the operator's private notes have the names.

1. `occ app:update --showonly` (only the three disabled apps should be listed; `occ upgrade` applies anything else).
2. A fresh database dump and a tar of the live bridge folder (0.12.6).
3. `git archive` of `facewright_bridge`, `occ upgrade`, container restart (routes changed).
4. Checks: `project --dry-run` 0; `own-tables --verify` **exits 0** (the first time since Recognize was disabled:
   0.12.6 failed it on Recognize's frozen table); `occ list facewright` as in S3; the removed route answers 404/405
   unauthenticated; no log lines after the upgrade; one People-page action and one Memories rename by the user.
5. Leave `projection_targets=own` in place.

**Rollback** (until 0.14.0): the 0.12.6 files with `<version>` in `info.xml` edited to 0.13.0 (a lower version takes the
site down), container restart. With `projection_targets=own` still set, 0.12.6 writes the own copy again. Its
`--verify` then reports `copies_without_link` for every face created under 0.13.0; that is expected, and nothing but
`--verify` and a full `occ facewright:reset` read the link table.

After a week on live: step 3 of the sequence (the dev rehearsal of the way back from the tag, `docs/cleanup.md`
section 0), then 0.14.0 (drop the link table).

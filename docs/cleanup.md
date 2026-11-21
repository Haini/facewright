# Cleanup after the Memories fork

An inventory of what is left to tidy now that live runs the fork of Memories against our own copy (`docs/memories-fork.md`,
M11) and Recognize is disabled. Assessed 2026-11-21 against `master` at `beb5488`. Each item says where, what, and whether
it waits on something. Done so far: the decision and the tags (section 0), the archive (3.3).

**State this assumes:** live runs bridge 0.12.1, the pinned fork of Memories 11.1.4 with
`memories.recognize.provider=facewright_bridge`, `projection_targets=own`, `clustering_engine=exapp`, and Recognize 12.0.3
installed but disabled (its tables frozen since 2026-11-21).

## How to work this list

- Rules from `CLAUDE.md` apply unchanged: dev first, every live action with the user's go-ahead and a fresh dump, live
  names and paths only in the operator's private notes, pushes only with a go-ahead.
- Section 0 records the decision (Option 2). Items marked **[F/H]** belong to Stages F and H and follow their order;
  items marked **[either option]** were independent of the decision and can be done any time.
- Tick items off here when done, with what was measured, and fold anything durable into `CLAUDE.md` or the relevant doc.

---

## 0. The decision: Option 2, with a tag as the way back (decided 2026-11-21)

`docs/memories-fork.md`, "Decision after M11", had two options: keep the Recognize-side machinery as a way back to
stock Memories (Option 1), or remove it with Stages F and H (Option 2). **The user chose Option 2.** The way back is
not a data rollback: it is the code at the tag `pre-stage-f` on a database rebuilt from scratch, with detection run
again over the whole library. Losing the curation (names, merges, moves, ignores) on that path is accepted.

- **Tags (pushed 2026-11-21):** `pre-stage-f` on this repo (`beb5488`, bridge 0.12.1: the `recognize` target,
  `--hydrate`, reconcile B/squatters/legacy, the router's `recognize` root, the Stage D rollback code), and
  `facewright-v11.1.4` on the fork (`2adf50b6`). The older local tag `stable` (`cf5c4ad`, 2026-09-15) is unrelated.
- **What this changes in the plan:** Option 1's items drop out (no warm standby, no rollback-from-disabled-Recognize
  drill, no separate Option 1 code path). Stages F and H run in the order of "Decision after M11", Option 2, each live step with its own
  go-ahead and a fresh dump. A dump stays the way back for anything in between.

Still to answer with the user, at the step that needs it:
1. F.1 (blank the vectors, rebuild Recognize's table): pointless if H drops the tables anyway; skip unless the tables are
   kept frozen for a while.
2. Recognize: uninstall, or leave disabled with its tables frozen or dropped (Photos' People view is gone either way).
3. F.3: the ~40 legacy rows (show the titled/untitled split first). With Recognize's tables going, this reduces to
   whether any of their titles are worth carrying over by hand.

### Back to Recognize from the tag (runbook, not rehearsed)

The way back after Stages F and H. Everything of ours is rebuilt; nothing is restored. Take a dump first anyway.

1. **Memories to stock.** `occ config:system:delete memories.recognize.provider` while the fork is still installed,
   then swap in the store build of the same version (or remove the folder's `.git/` and let the store update it).
2. **Recognize back, before it can react to anything.** While it is disabled its `FileListener` is not registered, so:
   make sure the `.noimage` marker is at the root of every enabled user's `files/` *before* enabling it. If its tables
   were dropped, its migrations must run again: remove the app, delete its `oc_migrations` rows, install it. Otherwise
   empty `oc_recognize_face_detections` and `_clusters`. Then `occ app:enable recognize`, and in the same chain delete
   its `MaintenanceJob` and any `ClusterFacesJob` (pipe `y`), and write `faces.enabled=true` through `IAppConfig`
   (`occ config:app:set recognize faces.enabled --value=true`), never through its settings service.
3. **The bridge at the tag, on empty tables.** `occ app:disable facewright_bridge`; drop every `oc_facewright_*` table
   (the `own` copy `oc_facewright_bridge_face_*` included) and delete the app's `oc_migrations` rows, keeping users'
   preferences (enabled flag, included paths) and the app config. Deploy `git archive pre-stage-f facewright_bridge`
   with `<version>` in `info.xml` raised **above the installed one** (a lower one makes Nextcloud demand an upgrade and
   take the site down). `occ upgrade` (it also applies pending store updates: check `occ app:update --showonly`
   first) runs every migration on the empty schema. Set `projection_targets=recognize`.
4. **The ExApp.** If F/H changed it, run the image built from the tag. Delete its embedding cache (the SQLite file
   under `FACEWRIGHT_DATA_DIR`): face ids start over, and while checksums would catch stale entries, nothing in it is
   worth keeping.
5. **Rebuild.** `occ facewright:scan` (or the admin page's backlog scan); clustering follows on its own. Detection over
   the full library on CPU takes hours to days. People come back untitled.

Unverified until rehearsed: whether 0.12.1's migrations run cleanly on a schema where the tables they create are
gone but later Nextcloud versions are in place, and step 2's order with the markers. **Rehearse this on dev once, before
the first one-way live step of Stage H** (dropping Recognize's tables or `facewright_detection_faces`), and record the
result here.

---

## 1. Live checks still owed (read-only, no go-ahead needed for reading)

These close out tracks that are done in code but whose evidence was never recorded.

1. **ExApp clustering, S7** (`docs/exapp-clustering.md`): the first real `exapp` chain and "a week without fallbacks"
   (due from 2026-11-16) were never recorded. Read the log for `POST /cluster` in the ExApp and for the warning
   "continuing this chain in PHP"; record the result in S7's "Done" and close CLAUDE.md open item 7.
2. **Stage E day-after check, the full version** (`docs/own-tables.md`, "Where things stand", item 1): it was clean on
   2026-11-07 but before any detection, new face or share. Repeat now for the `own` target (`project --dry-run`,
   `own-tables --verify`, `projection_last_error`, the `repaired ...` warnings in the log).
3. **The admin page's "Memories sync" line** has never been looked at in a browser.
4. **Recognize's jobs**: confirm `oc_jobs` still has no `MaintenanceJob` / `ClusterFacesJob` (it was disabled, but any
   `occ upgrade` that touches it re-adds them).
5. **The deployed fork build**: confirm its sha256 is in the private notes (two builds of the same commit differ).

---

## 2. Repository hygiene **[either option]**

1. **Unpushed commit** `beb5488` (docs of the open decision). Pushed 2026-11-21 (`171634f`), with both tags.
2. **Stray root-owned directory `php-php2shell.ini/`** at the repo root (empty, owned by root, 2026-11-03). Almost
   certainly a Docker bind mount of a file that did not exist, which Docker then created as a directory. Find which
   compose or `docker run` line referenced it, fix that, then `sudo rmdir` it.
3. **Stray `.work/` at the repo root** (empty `snapshots/`, `tokens/admin`, `tokens/bob`, 2026-11-10). The dev
   tooling uses `dev/.work/`; this one looks like a run from the wrong directory. Check nothing reads it, delete it.
   It is gitignored, so this is local only.
4. **`docs/memories-fork.patch`** duplicates the fork's commit `bbb4f38c`. Decide: keep it as the reviewable diff
   (then it must be refreshed on every M12 rebase, add that to M12), or replace it with a link to the commit.

---

## 3. Documentation

The docs were written as handoffs, step by step, and their status blocks have accreted. Stale status lines are the main
risk: a cold session acts on them.

### 3.1 Stale statements to fix now **[either option]**

| Where | Says | True since |
|---|---|---|
| `CLAUDE.md:69` | the fork is "Built and verified on the dev stack; not on live yet." | live 2026-11-20 |
| `CLAUDE.md`, "Architecture" and "Why Recognize cannot simply be uninstalled" | Recognize "stays installed and enabled", Memories depends on it being enabled | with the fork and provider set, Recognize is disabled; this is now the stock-Memories story only |
| `CLAUDE.md`, "Coexisting with Recognize" | written for an enabled Recognize | still needed for a rollback, but should say it applies only while Recognize is enabled |
| `CLAUDE.md`, open item 7 | "Open: observing the first real chain and a week without fallbacks" | close after 1.1 |
| `docs/own-tables.md:44` | Stage G "**Not on live.**" | live 2026-11-20 |
| `docs/own-tables.md`, "Where things stand" | ExApp track "Open: S6 … and S7"; last full suite run "28 of 28" | S6/S7 done 2026-11-09; run-all is 40 of 40 (M9) |
| `docs/memories-fork.md:15` | "`2adf50b6` is not [pushed], it waits for the user's go-ahead" | the same section's end says it is pushed |
| `docs/exapp-clustering.md`, "Where things stand" | paragraphs in mixed order (C1, C2, C3, C5/S1-S7, then C4), "Next: S7" next to "S7: switched" | rewrite as one current paragraph |
| `README.md`, "Current limitations" | "Memories still needs Recognize's tables" | not with the fork; say both modes |
| `README.md`, intro | "built as a companion app rather than a fork", "Status: early public alpha … single-user-tested", disclaimer typos (`delploy`, `recognizes`) and "directly build upon recognizes tables" | the data is in our tables since Stage E |

### 3.2 Shorten the status blocks **[either option]**

`docs/own-tables.md` "Where things stand" is ~50 lines of which most is 2026-11-07 history it itself declares stale.
Replace each doc's "Where things stand" with a short current-state paragraph and a pointer; the history is in git.

### 3.3 Archive finished plans — done 2026-11-21

`docs/archive/` (with its own `README.md` saying why each doc is there) holds `independence.md`,
`independence-implementation.md`, `people-ui-implementation.md` and `face-detection-schema.html`; every path reference
in docs and code comments points there now. `docs/README.md` is the index. Left:

- `people-ui-review.md` and `contact-names.md`: add a status line at the top ("not worked yet", "not started").
- `exapp-clustering.md`: trim to design and open questions once 1.1 is recorded.
- `memories-fork.md`: maybe split M12 out as a standalone runbook.
- A current replacement for the schema explainer (`facewright_*` tables and the `own` copy), after Stage H, when the
  schema has settled.

### 3.4 `CLAUDE.md` size **[F/H]**

47 KB, 591 lines. Much of it (vector bands, `ownRowsExpr`, `face_vector` rules, `.noimage`, `MaintenanceJob`,
"Telling our rows from Recognize's", the transitional commands in the `occ` list) exists because of Recognize's tables.
It goes with Stage F.4 and H, as the code goes. What a reader of the tag needs is in the tag's own `CLAUDE.md`; the
current one keeps a short pointer to `pre-stage-f` and the runbook in section 0.

---

## 4. Code removal

### 4.1 One-time code, removable first

Migration and backfill code whose job is done on live. With the tag as the way back, nothing of the running code has to
support a rollback any more, so these can go in the first F/H release (0.13.0) together with 4.2's first step.

- `Command/MigrateFaces` + `Service/FaceMigrationService` (Stage 1 backfill, done 2026-10-18).
- `Command/ReopenUnassigned` (one-time reopen of legacy `-1` rows).
- `Command/CompactVectors` + `Service/VectorCompactionService` (pre-compaction rows; new rows are `'[]'`).
- `own-tables --precheck / --migrate / --seed-ids` (the Stage E move; `--migrate` already refuses once tables hold faces).
- `Command/MergeClusters`: check whether it is still reachable and useful next to `PeopleService::mergeMany`.

Each removal also touches `info.xml` (command registration, so a version bump), the `occ` list in `CLAUDE.md`, and any
`dev/` suite that calls it.

### 4.2 Stages F and H **[F/H]**

As listed in `docs/memories-fork.md` "Decision after M11", Option 2, and `docs/own-tables.md` F.2 / H.1:
the `recognize` projection target, `project --hydrate`, reconcile categories B / squatters / legacy, the router's
`recognize` root (then rename `RecognizeWriteRouterPlugin`), `DetectionService::ownRowsExpr()`, `encodeVector()`,
`VECTOR_PRECISION`, `VECTOR_LENGTH_FLOOR`, sentinels into `FaceState`, the legacy-member reads
(`FaceStore::emptyClusterIds`, `PeopleService::moveLegacyMembers`), then the migration dropping
`facewright_detection_faces`, then `dev/rollback-drill.sh` (Stage D) and the dev suites that exercise the `recognize`
target or Recognize's tree (`project-recognize-*`, the `TREE=recognize` battery, the stock-provider UI flows). Order and
soak times are in that section. Before the first one-way live step: the dev rehearsal of section 0's runbook.

---

## 5. Dev tooling and tests

1. **Every script in `dev/` is referenced** (by `run-all.sh`, another script, or `dev/README.md`); nothing is orphaned.
   `clustering-sets.sh` and `fork-swap.sh` are run by hand only. The C2/C4 comparison helpers (`cluster_inmemory.*`,
   `compare_direct.py`, `exapp_match_selftest.py`) are worth keeping while thresholds are tuned (open question in
   `exapp-clustering.md`).
2. **[F/H]** A dev rehearsal of section 0's runbook (the tag on a rebuilt database), once, before Stage H's one-way
   steps. A one-off, not a `run-all.sh` suite: it needs a full re-detection of the dev library.
3. **[either option]** No unit tests, static analysis or CI (CLAUDE.md open item 5). Minimal useful step: PHPUnit for the
   pure logic (`ClusteringService` distance math, `AccessService::pickFetchUser`, `PathFilterService::isIncluded`,
   `FaceState`, `FaceVectorCodec`) and `php -l` + Psalm over `lib/`, plus `pytest` for `ex_app/lib/clustering.py`.

---

## 6. Known bugs (code) **[either option]**

### 6.1 CLAUDE.md open item 4 — all three still present (checked 2026-11-21)

1. `ex_app/lib/main.py:70,96` — `detect` is `async def` and calls `FACE_ANALYSIS.get(img)` synchronously and unguarded:
   inference blocks the event loop (so `/heartbeat` stalls), and an exception surfaces as a 500, i.e. retryable. Fix:
   plain `def` (like `/cluster`) or `run_in_threadpool`, with a `try` that maps a decode-level failure to 4xx.
2. `facewright_bridge/lib/BackgroundJob/DetectQueuedJob.php:144` — the zero-byte branch marks
   `array_keys($access)` (every user with access) instead of `$pending`, which is computed only afterwards. Move the
   check after `$pending` and mark only those.
3. `ProcessedFileService::candidateFileIds()` (`:232`) — `PathFilterService::images()` filters `image/%`, not
   `DetectionService::SUPPORTED_MIMETYPES`, so unsupported images keep the "not yet processed" count above zero.

### 6.2 `docs/people-ui-review.md` — apparently unworked

No commit references its items, and spot checks show item 7 still open (16 `userId === null` checks in
`PeopleApiController`). Work it as written (P1 first: late responses rendering into another view, the merge offer on
rename, stale centroids, legacy-tile rendering); items 8 and 12 need the user first. Item 4 (legacy 128-dim tiles)
becomes moot under Option 2.

---

## 7. Live instance hygiene (each with a go-ahead)

1. **Backups**: several generations accumulated (pre-0.7.0, pre-0.8.0, pre-S6, pre-history-scrub, pre-fork,
   pre-recognize-off, and an image tagged for S6). Decide a retention: keep the newest full dump from before each
   F/H step and the store build of Memories 11.1.4 (step 1 of the runbook), drop the rest once H is done.
2. **`.noimage` markers** in users' `files/`: irrelevant while Recognize is disabled, and step 2 of the runbook needs
   them before Recognize is ever enabled again. Simplest: leave them where they are (empty files, Facewright ignores
   them). Removing them is harmless only while Recognize is disabled or gone.
3. **Recognize's frozen tables** (~1.1 GB, in the buffer pool's way only if read): dropped in H, after a dump.

---

## 8. Not cleanup, but still open (carried, for completeness)

Kept here so this list is the one place to look; the source of truth stays where named.

- **M12**: rebase the fork on each Memories release; the only way security fixes arrive. Watch upstream releases.
- CLAUDE.md open items 1 (runtime share/unshare never exercised from the browser; `PruneService` never deleted;
  `BatchTriggerService` resume cursor), 2 (clustering quality at scale, the ground-truth set), 3 (admin threshold
  changes do not reach users with their own), 6 (row-at-a-time SQL).
- `exapp-clustering.md`, "Open questions for later": the 20-per-person cap, threshold tuning.
- `suggest_max_distance` (0.6) is still uncalibrated.
- `contact-names.md`: the whole feature, not started.

## Suggested order

1. Section 1 (read-only, closes two tracks) and 2.1–2.3; tags pushed.
2. 3.1 and 3.2 (stale status).
3. 6.1 (three small bugs, one bridge release + one ExApp image).
4. Stages F and H: 4.1 with the first step of 4.2 (bridge 0.13.0), soak, the dev rehearsal (5.2), then the one-way
   steps; 3.4 and 7 along the way.
5. 6.2, 5.3 as time allows.

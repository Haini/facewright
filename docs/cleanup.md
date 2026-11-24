# Cleanup after the Memories fork

An inventory of what is left to tidy now that live runs the fork of Memories against our own copy (`docs/memories-fork.md`,
M11) and Recognize is disabled. Assessed 2026-11-21 against `master` at `beb5488`. Each item says where, what, and whether
it waits on something. Done so far: the decision and the tags (section 0), the live checks (section 1, apart from 1.3), the stray folders (2.2, 2.3), the stale statements and status blocks (3.1, 3.2), the archive (3.3), the three bugs of 6.1 (live).

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

**Run 2026-11-21 (evening), read-only.** Live: bridge 0.12.1, Memories 11.1.4 (fork), Recognize 12.0.3 disabled,
`projection_targets=own`, `clustering_engine=exapp`, provider `facewright_bridge`, `processing_paused=false`.

1. **ExApp clustering, S7 — passed.** The ExApp container has run since 2026-11-15 with 0 restarts; its log (back to
   2026-11-09) has 27 `POST /cluster`, 62 `cache/check`, 6 `cache/put`, 5 `cache/forget`, all 200, and 456
   `POST /detect` 200 (one 404). The Nextcloud log has no "continuing this chain in PHP" warning at all, and no
   warning or error of ours after 2026-11-09 (the last is a single ExApp timeout at 09:13 that day, during the S6/S7
   work, with no fallback after it). No face is pending (states: 152,750 assigned, 3,578 unassigned, 45,048
   unclusterable, 3 ignored). Recorded in S7's "Done"; CLAUDE.md open item 7 is closed.
2. **Day-after check for `own` — passed, with one expected failure in `--verify`.** `project --dry-run` for `own`:
   A 0, C 0, D 0/0. `projection_last_error` unset. No `repaired ...` warning in the log since it begins (2026-10-21).
   `own-tables --verify`: every check of ours and of `own` is 0 (201,379 faces, 4,679 people), **but it exits 1 on
   `C_stale_copies = 2`**: two rows in Recognize's frozen table (`10507` Consti, `98894` Julia, both file `68229`) whose
   rows of ours are gone because the file was deleted to the trash bin after the freeze. That is correct behaviour of
   ours; `--verify` always checks Recognize's table, configured as a target or not, so it will keep failing more as
   faces change. **To do:** in 0.13.0 (H.1) the `recognize` checks go; until then read C there as the frozen copy's
   drift. (B 44,712, legacy 40, as expected.)
3. **The admin page's "Memories sync" line** — not checked (needs a browser and a login; for the user).
4. **Recognize's jobs — passed.** No Recognize class in `oc_jobs`; `oc_recognize_fs_deletions` is empty. Ours: the
   three hourly maintenance jobs, nothing else queued.
5. **The deployed fork build — passed.** The sha256 of the deployed tarball (`d540449f…d225c`, the same as dev's M4
   build) is stored next to it with the pre-fork backup and matches the file; live's `custom_apps/memories` has the
   `.git/` pin, no `signature.json`, and `recognizeProvider` in the four patched `lib/` files.
6. **Found while checking: the documented log filter finds nothing.** Our log lines carry `"app":"no app in context"`,
   not `"app":"facewright_bridge"`, so the grep in the day-after check (`docs/own-tables.md`, item 1) has matched none
   of them since at least 2026-10-21. Search the message text instead (every message of ours starts with
   `facewright:`; Nextcloud's own lines about our jobs name `FacewrightBridge`): `grep -i facewright nextcloud.log`.
   Fixed in `docs/own-tables.md` and the operator's private notes; S7's earlier "no bridge log entries" is corrected.

---

## 2. Repository hygiene **[either option]**

1. **Unpushed commit** `beb5488` (docs of the open decision). Pushed 2026-11-21 (`171634f`), with both tags.
2. **Stray root-owned directory `php-php2shell.ini/`** — removed 2026-11-21 (empty; no container, compose file or
   script referenced it any more, so its origin, probably a one-off `docker run -v` of a missing file, stays unknown).
3. **Stray `.work/` at the repo root** — removed 2026-11-21 (two dev app-password tokens from 2026-11-20, a run with
   `WORK_DIR` pointing there; `dev/lib.sh` defaults to `dev/.work`).
4. **`docs/memories-fork.patch`** duplicates the fork's commit `bbb4f38c`. Decide: keep it as the reviewable diff
   (then it must be refreshed on every M12 rebase, add that to M12), or replace it with a link to the commit.

---

## 3. Documentation

The docs were written as handoffs, step by step, and their status blocks have accreted. Stale status lines are the main
risk: a cold session acts on them.

### 3.1 Stale statements — done 2026-11-21

Fixed: `CLAUDE.md`'s intro and "Architecture" now describe the two modes (fork mode, live; stock mode, until Stages F
and H, kept at the tag `pre-stage-f`), with "Writes from Memories" and "Stock mode: why Recognize must be enabled" as
subsections, and "Coexisting with Recognize" says it applies only while Recognize is enabled. `docs/own-tables.md`:
Stage G live, the ExApp track closed, the last full suite run 40 of 40. `docs/memories-fork.md`: both fork commits
pushed and tagged. `docs/exapp-clustering.md`: one current status paragraph. `README.md`: the intro, the status and
disclaimer (typos fixed; the data is in our own tables), both modes in "What this is", limitations and requirements,
the fork section (live, `projection_targets=own`), and "Keeping Recognize's own face detection off" scoped to an
enabled Recognize.

Not changed, for the user: whether "early public alpha" and the README's People-page limitation ("only tried by hand,
on a throwaway instance") still describe it; the page has been in use on live since 0.8.0.

**Found 2026-11-21, not fixed yet:** `CLAUDE.md` (`PathFilterService`: "Empty list = nothing included") and
`README.md` (quickstart step 3: "An empty list means nothing is scanned") are wrong. The code
(`PathFilterService::isIncluded()`, `resolveSearchRoots()`) and the personal settings page agree that an empty list
includes all of the user's files.

### 3.2 Shorten the status blocks — done 2026-11-21

`docs/own-tables.md`: "Where things stand" is three short paragraphs; the day-after check moved to the end of Stage E as
a standing procedure, updated for the `own` target and `--verify`'s check of the frozen table. `docs/memories-fork.md`:
each step's measured results moved from the status block into the step's own section ("Done (measured)"), checked line
by line against the old text; the status is one paragraph plus the fork, clone and build facts. The dev gotchas that
were only in `own-tables.md`'s status block are now at the end of `dev/README.md`. (`docs/exapp-clustering.md` was done
in 3.1.)

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

### 6.1 CLAUDE.md open item 4 — fixed 2026-11-21, deployed to live the same evening

Each fix was checked on dev against the old code, which had to fail the same check:

1. **`/detect` on the event loop** (`ex_app/lib/main.py`). Decoding and inference moved into `_analyse()`, run with
   `run_in_threadpool`; `_INFERENCE_LOCK` keeps inference one at a time, as the event loop did (parallel ONNX Runtime
   sessions spiking every core preceded the native crashes). An exception from `FACE_ANALYSIS.get()` is now a 400 if
   it is an OpenCV error (the image itself, so permanent) and an explicit 500 with the traceback in the container log
   otherwise (retryable). Measured: `/heartbeat` polled every 50 ms during three forced detections of a 2 MB image,
   slowest answer **157 ms on the old image, 4.9 ms on the new one** (median about 1.3 ms both). A stand-in model
   raising `cv2.error` gave 400, `RuntimeError` 500, a normal answer 200, and the lock was free afterwards.
2. **Zero-byte branch** (`DetectQueuedJob`). The check moved after `$pending` and marks only those users. Measured
   with an emptied file and a job run through `occ background-job:execute`: a user who already had a marker
   (`faces_found = 7`) went to **-1 on the old code and kept 7 on the new**; a pending user got -1 on both.
3. **Candidate count** (`ProcessedFileService::candidateFileIds()`). Applies `SUPPORTED_MIMETYPES` like
   `BatchTriggerService`. Measured: one `.cr2` file (`image/x-dcraw`) raised admin's count from **96 to 97 on the old
   code and left it at 96 on the new**.

Regression: `dev/run-all.sh --ui` with the fixes, 39 of 40; the one failure was `rollback-drill` refusing to swap
uncommitted bridge files, and run alone after the commit it passed 24 of 24.

**Deployed to live 2026-11-21 (about 22:30 local), with the user's go-ahead.** Before: live's bridge was byte-identical
to the tag `pre-stage-f`; the backup of its folder and the previous ExApp image (tagged `facewright:pre-bugfix`) are
kept, see the operator's private notes. `make run`'s settings were compared with the running container's first (equal,
secrets compared by hash). ExApp: `make build`, `make run`, healthy, model downloaded again at start (the image does
not keep it). Bridge: `git archive HEAD facewright_bridge` copied in, live's folder then equal to HEAD (`4b89a5a`),
version unchanged at 0.12.1, no upgrade needed. Checks: one forced `occ facewright:detect` of a zero-face photo went
through AppAPI and HaRP to the new ExApp (`POST /detect` 200, 0 faces, only the marker's time changed); `project
--dry-run` for `own` all 0; `projection_last_error` unset; no warning or error in `nextcloud.log` since; candidate counts
with the new filter: Consti 55,380 (4.3 s), Julia 49,309 (3.4 s). Rollback: the backup folder back in, `docker tag
facewright:pre-bugfix facewright:latest`, `make run`.

**Found while deploying: `make verify` hung, a startup race.** Its `curl` (no timeout) sat for about four minutes
until stopped. Not hairpin NAT: from inside the Nextcloud container the public URL answers in 36 ms, and the same
heartbeat request answered 200 in 22 ms nine minutes later. The timeline: the container started 21:27:29 and the
model download ran until uvicorn listened at 21:27:41; frpc logged `dial to uds /tmp/exapp.sock error: no such file`
at 21:27:34, and uvicorn logged no heartbeat until the later test. `healthcheck.sh` only checks that `frpc` runs, so
the container is "healthy" about 2 s after start, before the app listens, and `make verify` ran in that gap. The
request reached frpc, frpc could not dial the socket, and the connection was left open with no answer, which a `curl`
without `--max-time` waits on forever. (2026-11-09, at S6, has the same `dial to uds` line; Nextcloud's own AppAPI
calls time out instead, logged then as `cURL error 28`, which the bridge treats as retryable.)

**Fixed (2026-11-23, deployed to live the same day):**
- `healthcheck.sh` asks `/heartbeat` on the socket (HaRP mode) or on `APP_HOST:APP_PORT` (dev), with a 1 s timeout, so
  "healthy" means the app answers. The `Dockerfile` probes every 2 s until the first success (5 min start period, for
  a first model download) and then once a minute, since every probe is now an access-log line.
- `make verify`'s `curl` has `--max-time 15`: a dead tunnel is `HTTP_STATUS:000` and exit 28, not a hang.
- The model lives under the data volume (`FaceAnalysis(root=$FACEWRIGHT_DATA_DIR/insightface)`, about 600 MB with the
  zip insightface keeps), so only the first start of a new volume downloads it.
Measured on dev: first start downloads into `/data`, healthy 2 s after uvicorn listened (not before); a recreated
container starts with no download and is healthy in 2.1 s; `exapp-client` 12/12, `exapp-cluster` 28/28,
`cache-hygiene` 20/20, six `POST /detect` 200 on the volume's model. The HaRP branch, run read-only inside the live
container against the running app: exit 0; against a missing socket curl fails at once (exit 7). The first live start
with the new image downloads the model once more, into the volume. Live deploy: the first start downloaded the model into the
volume and was healthy after 15 s, with uvicorn listening and no `dial to uds` line; `make verify` answered 200; a forced
`occ facewright:detect` of the same zero-face photo as before gave `POST /detect` 200; `projection_last_error` unset.
Rollback: `docker tag facewright:pre-healthcheck facewright:latest`, `make run` (the model in the volume is harmless).

A side note from the test: on Nextcloud 33+ `oc_jobs.id` is a Snowflake id
with no auto-increment, so a hand-made job row needs an explicit id.

### 6.2 `docs/people-ui-review.md`

Items 1 to 3 (P1) done and item 4 closed on 2026-11-23, bridge 0.12.2, deployed to live the same day
(`occ upgrade`, which also took richdocumentscode from 26.4.502 to 26.4.603 from the App Store, as agreed; live then
equal to HEAD, `project --dry-run` 0 for `own`, no Recognize jobs, no errors logged). The measurements are in that
document. Items 5 and 6 done on 2026-11-24, bridge 0.12.3 (on dev; not deployed to live yet): the covers are one query
and the totals come with the first page only; page 1 of the people list stays at 130-200 ms on live for its covers,
which only a stored cover per person would fix. Open: 7, 9 to 11, 13; 8 and 12 need the user. Item 7 is
still as found (16 `userId === null` checks in `PeopleApiController`).

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

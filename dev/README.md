# dev/ — verification scripts for the compose dev stack

Bash scripts (not zsh: it does not split unquoted variables) that drive the dev stack through `docker exec`.
Defaults match `docker-compose.yml`; override `NC_CONTAINER`, `DB_CONTAINER`, `DB_USER`, `DB_PASS`, `DB_NAME`,
`ADMIN_USER`, `ADMIN_PASS`, `BOB_USER`, `BOB_PASS` from the environment. Scratch data lives in `dev/.work/`
(ignored by git).

## Rules every script follows

- It checks the **output**, never just an exit code, and **asserts its own precondition** (`need`): a step with
  nothing to act on stops with exit 2 instead of passing.
- Chosen ids come from the database, not from the script, so the checks work on other data.
- After each data-changing step `verify_step` runs `occ facewright:own-tables --verify`.
- Each checker has been shown to fail: a planted fault, or the pre-fix code.

## Baseline and snapshots

The dev stack is in the live shape: fork of Memories as the reader (system config `memories.recognize.provider=
facewright_bridge`), Recognize installed but disabled, app config `projection_targets=own`. `run-all.sh` refuses to start
otherwise. A snapshot (`dev/snapshot.sh`) holds **our** tables only (faces, user_faces, clusters, cluster_stats,
processed, centroids, merge dismissals, Memories' covers) with their id counters; `restore` loads it, empties the own copy
(`facewright_bridge_face_*`), refills it with `occ facewright:project` and runs `own-tables --verify`. It refuses a file
that holds Recognize's tables (the old format; the last one is `.work/snapshots/original-stock.sql`).

## Start

    dev/snapshot.sh save original      # once, from a healthy dev stack
    dev/run-all.sh [--ui]              # everything; restores the snapshot before each suite

## Scripts

| Script | Checks | Changes data |
|---|---|---|
| `reads.sh capture\|compare\|stable` | ~50 outputs of the People code (commands, API); `stable` proves two captures equal | no |
| `reads-direct.sh` (+ `reads_direct.php`, `compare_direct.py`) | the access-sync, detection and reset readers on a sample of files | no |
| `battery.sh` | the DAV router and the reads of the `facewright_bridge/` tree: what is applied, refused, left alone; a person's listing against SQL, a face file's bytes against the Files tree, a Destination in another root (`recognize/`) refused | restores itself |
| `project-own.sh` | the projection: an empty copy, the fill, one planted fault per category, a broken face upsert isolated from People writes (docs/memories-fork.md, M6) | yes (restore) |
| `writers-people.sh` | every People API write | yes (restore) |
| `writers-cluster.sh` | reopen, assign, mark unclusterable, clear marks, resets | yes (restore) |
| `persist-guard.sh` (+ `persist_guard.php`) | a clustering pass writing after a human changed the rows: the human wins, and a person the pass left empty goes | yes (restore) |
| `own-traps.sh` (+ `own_traps.php`) | a repeated share copy is harmless; FaceStore refuses a person on a state that has none | yes (restore) |
| `writers-files.sh` | detection with a real face, copy, prune, a real share, delete | cleans up |
| `chain-loop-test.sh dangling\|assignable` | the clustering chain ends; fails on code from before "progress means rows written" | yes (restore) |
| `clustering-sets.sh save\|compare` (+ `members.py`) | who ends up with whom, independent of numbering | yes (restore) |
| `clustering-truth.sh` (+ `truth.py`, `fetch-dataset.sh`) | clustering against labelled identities (Georgia Tech set, downloaded into `.work/`, never committed: no stated licence); the oracle `truth.py USER` checks pure / complete / same in Recognize's table and is proven able to fail | yes (restore) |
| `project-drift.sh` | reconcile: plants kinds of drift in the own copy, requires the dry run to report exactly those, repairs, checks Memories | yes (restore) |
| `project-failure.sh` | a broken push must not break People writes; the admin page says so; the hourly job repairs | yes (restore) |
| `people-stats.sh` | each person's stored size and cover: the cover face leaving, a better face joining, opposite moves at once, merge, delete, reset, `--recount` | yes (restore) |
| `project-concurrency.sh` | two parallel writers on the same faces; the copy must still agree; fails if the upsert is degraded | yes (restore) |
| `memories-api.py capture\|compare\|stable` | what Memories itself shows (people, photos per person, unassigned) as the oracle for table changes; proven able to fail | no |
| `memories-ui/flows.py` | Memories' own UI: rename, remove face, move face, merge, remove person; requests and database | yes (restore) |
| `memories-ui/admin_provider.py` | the face provider dropdown on Memories' admin page (fork only): the select and its card, choosing the other provider, choosing `recognize` deletes the key, a new page gets the provider, `nosuchapp` shows the error card; puts the setting back (docs/memories-fork-admin-ui.md) | the system setting (restored) |
| `fork-swap.sh save-store\|fork\|store\|status` | swaps the Memories folder in the dev container between the App Store build and the fork's build (docs/memories-fork.md, M4); not a check | the app folder |
| `exapp-cluster.sh` (+ `exapp_call.php`, `exapp_cluster_gen.py`, `exapp_match_selftest.py`) | the ExApp's embedding cache and `/cluster` through AppAPI: checksums agree, all-or-nothing puts, 409 on a missing or stale face, a 50k pool with `/heartbeat` timed meanwhile, `match_chunk()` against a port of the PHP loop (docs/exapp-clustering.md, C1) | ExApp cache only; leaves it empty |
| `cluster-inmemory.sh [--prepare]` (+ `cluster_inmemory.php`) | the comparison's view of PHP clustering (`loadPoolForComparison`, `assignInMemory`) writes nothing, reopen adds exactly the unclusterable rows, a temporary label is refused (docs/exapp-clustering.md, C2) | only with `--prepare` (restore) |
| `exapp-client.sh [USER]` (+ `exapp_client.php`) | the bridge's `ExAppClusteringClient`: checksums, cold and warm cache sync, a stale entry, the 409 retry, a face that cannot be synced, the ExApp stopped (docs/exapp-clustering.md, C3) | ExApp cache only; stops and starts the ExApp |
| `cluster-compare.sh` | `occ facewright:cluster-compare`: admin reopened and the ground-truth user (pending, then reopened to force joins) agree with 0 differences and write nothing; planted ExApp faults (min_faces ignored, threshold too tight) exit 1; nothing pending exits 2 (docs/exapp-clustering.md, C4) | yes (restores; swaps the ExApp's file and restores it) |
| `cache-hygiene.sh` | deleted faces leave the ExApp's cache: a file delete, a folder delete in one request, the ExApp stopped (the delete still succeeds, one warning), `forgetFile()` outside a walk, `occ facewright:reset` emptying it; planted fault in the batched forget (docs/exapp-clustering.md, S4) | yes (restores; uploads and deletes copies of the portrait; stops and starts the ExApp) |
| `cluster-engine.sh` (+ `cluster_engine.php`) | `ClusterScanJob` under `clustering_engine=exapp`: one job per pass and the php chain's result; the fallback to PHP with the ExApp stopped; PHP peak memory of an exapp pass against a php chunk; planted faults (negative labels written without people, a failure after people were made) (docs/exapp-clustering.md, S3) | yes (restores; stops and starts the ExApp; deletes the config key) |
| `embedding-crc.sh` | `facewright_faces.embedding_crc`: the backfill (resumable), `--verify`'s check, the client's fallback for NULL, a forced re-detection of the real portrait rewriting embedding and checksum together; planted faults in the data and in the upsert (docs/exapp-clustering.md, S1) | yes (restore; uploads and deletes the portrait) |
| `live-capture.sh OUT users` | read-only capture for another instance (`NC_CONTAINER=…`); pause processing first | no |
| `make-shared-fixture.sh`, `check-fixture.sh` | shared-file fixture and the preconditions the suites need | fixture only |
| `setup-venv.sh`, `make-portrait.py` | Playwright/Chromium and a real-face test photo (`dev/.work/venv`) | no |

## The Memories fork (docs/memories-fork.md)

- `run-all.sh` has the wrapper `with_exapp` (clustering engine `exapp`) and `with_stock_memories` (provider unset and Recognize
  enabled, for `memories-ui/admin_provider.py` only; everything is put back afterwards).
- **Take every `memories-api.py capture` right after `snapshot.sh restore original`**: the UI flows delete a person and make
  Memories write cover rows, so a capture after them differs for reasons that are not the change under test.
- `snapshot.sh restore` empties the two own tables and refills them.

## Why it is not slower

- **App passwords.** `lib.sh` replaces the suites' passwords with app passwords (`fast_pass`, kept in `.work/tokens`), because
  a real password costs an Argon2id check per request. Same requests, same answers. The Memories UI flows log in for real.
- **Cached detection.** `clustering-truth.sh` keeps what detection wrote in `.work/gt-cache`, keyed on the ExApp and detection
  code and on `PEOPLE`, and rebuilds it when those change. `DETECT_FRESH=1` forces the real upload and detection.
- `run-all.sh` prints each suite's seconds. Suites share one database, so they cannot run side by side.

## Using them for a change

Capture on the old code, run `stable` to prove the capture deterministic, change the code, capture again, diff.
Planting a fault and seeing the checker fail is part of the work, not an extra.

Flow 4 (merge) is the regression for the parallel-MOVE deadlock; it fails if the router stops retrying.

Things that bit: Apache's opcache revalidates every 60 s, so after editing source a test must call `reload_web`
(`lib.sh`) or it runs the old code; `docker exec` needs `-i` for piped stdin (a `y` for `occ background-job:delete`).

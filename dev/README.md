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

## Start

    dev/snapshot.sh save original      # once, from a healthy dev stack
    dev/run-all.sh [--ui]              # everything; restores the snapshot before each suite

## Scripts

| Script | Checks | Changes data |
|---|---|---|
| `reads.sh capture\|compare\|stable` | ~50 outputs of the People code (commands, API); `stable` proves two captures equal | no |
| `reads-direct.sh` (+ `reads_direct.php`, `compare_direct.py`) | the access-sync, detection and reset readers on a sample of files | no |
| `battery.sh` | the DAV write router: what is applied, refused, left alone | restores itself |
| `writers-people.sh` | every People API write | yes (restore) |
| `writers-cluster.sh` | reopen, assign, mark unclusterable, clear marks, resets | yes (restore) |
| `persist-guard.sh` (+ `persist_guard.php`) | a clustering pass writing after a human changed the rows: the human wins, and a person the pass left empty goes | yes (restore) |
| `writers-files.sh` | detection with a real face, copy, prune, a real share, delete | cleans up |
| `chain-loop-test.sh dangling\|assignable` | the clustering chain ends; fails on code from before "progress means rows written" | yes (restore) |
| `clustering-sets.sh save\|compare` (+ `members.py`) | who ends up with whom, independent of numbering | yes (restore) |
| `clustering-truth.sh` (+ `truth.py`, `fetch-dataset.sh`) | clustering against labelled identities (Georgia Tech set, downloaded into `.work/`, never committed: no stated licence); the oracle `truth.py USER` checks pure / complete / same in Recognize's table and is proven able to fail | yes (restore) |
| `project-drift.sh` | Stage E reconcile: plants eight kinds of drift in Recognize's tables, requires the dry run to report exactly those, repairs, checks the legacy row and Memories | yes (restore) |
| `project-failure.sh` | a broken push must not break People writes; the admin page says so; the hourly job repairs | yes (restore) |
| `project-concurrency.sh` | two parallel writers on the same faces; the copy must still agree; fails if the upsert is degraded | yes (restore) |
| `project-recognize-trap.sh` | Recognize's own `.noimage` deletion job and `recognize:reset-face-clusters` (dev only): the projection brings everything back and Memories shows what it showed before | yes (restore) |
| `project-recognize-copies.sh` | Recognize's access-sync handler writes rows of its own; reconcile removes them; ids that meet (squatters) | yes (restore) |
| `rollback-drill.sh` | rollback tier R2: hydrate, swap the bridge files back to the Stage D commit, pass the Stage D checks, roll forward again (swaps tracked files; refuses unless clean) | yes (restore) |
| `memories-api.py capture\|compare\|stable` | what Memories itself shows (people, photos per person, unassigned) as the oracle for table changes; proven able to fail | no |
| `memories-ui/flows.py` | Memories' own UI: rename, remove face, move face, merge, remove person; requests and database | yes (restore) |
| `live-capture.sh OUT users` | read-only capture for another instance (`NC_CONTAINER=…`); pause processing first | no |
| `make-shared-fixture.sh`, `check-fixture.sh` | shared-file fixture and the preconditions the suites need | fixture only |
| `setup-venv.sh`, `make-portrait.py` | Playwright/Chromium and a real-face test photo (`dev/.work/venv`) | no |

## Using them for a change

Capture on the old code, run `stable` to prove the capture deterministic, change the code, capture again, diff.
Planting a fault and seeing the checker fail is part of the work, not an extra.

Flow 4 (merge) is the regression for the parallel-MOVE deadlock; it fails if the router stops retrying.

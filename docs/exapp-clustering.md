# Clustering in the ExApp: comparison first

The plan for CLAUDE.md's open item 7, written for a session that has not seen this repository before. It builds a
second clustering engine in the ExApp next to the PHP one and a command that runs both on the same input and reports
where they differ. **PHP stays the only engine that writes.** Switching over (an `exapp` mode, dropping chunking, a
stored checksum) is sketched at the end and is not part of this plan.

## Where things stand

**C1 is done (2026-11-08), on dev only.** The ExApp has the cache and `/cluster`; `dev/exapp-cluster.sh` passes 20 of
20 (with a planted fault caught), `dev/writers-files.sh` still passes (detection unchanged). Measured on dev: 300 attempts
against a 50,000-face pool take 2.4 s of compute (about 3 s round trip), against about 190 s in PHP; `/heartbeat`
stayed under 40 ms meanwhile. Nothing is deployed to live.

**C2 is done (2026-11-08), on dev only.** `ClusteringService` takes the "create a person" step as a callable and has
`loadPoolForComparison()` / `assignInMemory()`; production behaviour is unchanged (see C2's "Done").

**C3 is done (2026-11-08), on dev only.** `ExAppClusteringClient` syncs the cache and calls `/cluster`;
`dev/exapp-client.sh` passes 12 of 12.

**C5 is done on live (2026-11-08):** both libraries compared in full with `--reopen`, 0 differences of any class (user2:
31,126 attempts, PHP 3 h 36 min against 4 min 42 s); see C5's "Progress". **The switch-over is planned as Part 2 (S1 to S7); S1 to S4 are done on dev** (S3: the `exapp` engine,
off unless `clustering_engine=exapp`; S4: deleted faces leave the ExApp's cache).

**C4 is done (2026-11-08), on dev only (bridge 0.10.1 there).** `occ facewright:cluster-compare` runs both engines;
`dev/cluster-compare.sh` passes 16 of 16. On dev the engines agree exactly (0 differences of any class) and the
ExApp is about 20× faster even on dev's small pools. **Next: C5, on live, with the user's go-ahead for each action.**

## Why

Every `ClusterScanJob` chunk reloads the user's whole pool (pending + unclusterable + ≤ 20 per person) as PHP float
arrays, about 12 KB per face against 2 KB stored, and compares each of up to 300 pending faces with all of it. Measured
2026-11-07 with synthetic vectors on PHP 8.5, for a 55k-face pool (the larger library on the live instance): 668 MB
held, 0.63 s per face, about 3 minutes per chunk. Reopening that user's 31k unclusterable faces (a threshold change or
`cluster --reset`) is about 100 chunks, 5 to 6 hours of cron. NumPy does one face against 55k in about a millisecond.

Moving our tables into the ExApp was considered and rejected (CLAUDE.md, open item 7): the data stays in Nextcloud's
database, and the ExApp only computes.

## How to work this plan

- Read `CLAUDE.md` first, all of it. The invariants on clustering (unassigned, unclusterable, "a human's change wins")
  and the job semantics apply unchanged.
- Work on the compose dev stack only. The live instance is touched in C5 alone, and only with the user's go-ahead for
  each action. Its names and paths are in the operator's private notes, never in this repo.
- One commit per step. Each step ends with its **Verify** block passing; the output, not the exit code, is the proof.
- A checker counts only after it has been seen to fail: plant the fault named in the step.
- New `occ` commands are registered in `appinfo/info.xml`: that needs a bridge `<version>` bump and `occ upgrade`
  (check `occ app:update --showonly` first). New ExApp code needs `make build run` (see `Makefile`).
- Update "Where things stand" above at the end of every step.

## The design

### Protocol

The ExApp holds no assignment state. The bridge sends the state with every request; the ExApp keeps only an
**embedding cache**: `facewright_faces.id` → (checksum, 2048-byte float32 little-endian blob).

**The checksum is required.** A face id's embedding is not immutable: `DetectionService::upsertFace()` updates
`facewright_faces` in place on a forced re-detection, keeping the id. The checksum is MariaDB's `CRC32(embedding)`,
computed in the `SELECT` when the bridge needs it. It is not stored, so no writer can forget it. (It is the same CRC as
PHP's `crc32()` and Python's `zlib.crc32()`; C1 verifies that on real rows.) Computing it reads the blobs inside
MariaDB, the same I/O as today's load; a stored column is part of the later switch-over.

All bodies are JSON. All endpoints sit behind `AppAPIAuthMiddleware` like `/detect`.

1. `POST /cluster/cache/check` `{"faces": [[faceId, crc], ...]}` → `{"missing": [faceId, ...]}`: ids not in the cache
   or held with another checksum. Marks the matching ones as seen.
2. `POST /cluster/cache/put` `{"faces": [{"id": faceId, "crc": crc, "embedding": "<base64 of 2048 bytes>"}, ...]}` →
   `{"stored": n}`. 400 if a blob is not 2048 bytes or its `zlib.crc32` is not `crc`; nothing in that request is stored
   then. The bridge sends at most 1,000 faces per request (about 2.8 MB).
3. `POST /cluster` →
   ```
   {"max_distance": 0.5, "min_faces": 3,
    "pool":    [[rowId, faceId, crc, label], ...],   // in the bridge's pool order, see "Same input"
    "attempt": [rowId, ...]}                    // ascending, the ids PHP will attempt in this chunk
   ```
   `rowId` is `facewright_user_faces.id`. `label` is `null` (pending or unclusterable, as `loadDetections()` already
   normalizes) or a person id > 0. Answer:
   ```
   {"decisions": [{"id": rowId, "action": "new"|"join"|"skip"|"already", "label": int|null, "matches": n}, ...],
    "labels":    [[rowId, label], ...],         // every pool row whose label this call changed
    "compute_ms": n}
   ```
   New people get labels `-1, -2, ...` in the order they were created, starting at `-1` on every call. 409
   `{"missing": [faceId, ...]}` if a face is not in the cache with the checksum the pool names (the bridge then runs 1
   and 2 and retries once). The checksum is in the pool so that `/cluster` never matches a stale vector, whatever
   happened since the last `check`. 400 for a label ≤ 0, a row id twice, or an attempted id not in the pool.
4. `POST /cluster/cache/clear` `{}` → `{"removed": n}`: empties the cache and `VACUUM`s the file (a `DELETE` alone left
   the blobs on disk: 196 MB before, 12 KB after).

### The algorithm, exactly as PHP does it

`ClusteringService::assignClusters()` and `findMatches()`, mirrored:

- For each id in `attempt`, in order: if its label is not null (set earlier in this call), action `already`, next.
- Distances to every other pool row: `d = 1 - V @ v`, in **float64** (cast the float32 cache to float64 first; PHP
  computes in doubles). Candidates: `d <= max_distance`, the row itself excluded.
- Sort candidates by distance, ties by pool position (PHP's `usort` is stable since 8.0 and iterates `$detections` in
  pool order): `np.lexsort((position, d))`.
- If `len(candidates) + 1 < min_faces`: `skip`.
- Otherwise the label is that of the first candidate with a non-null label (`join`); if none has one, a new label
  (`new`). The row gets it, and so does every candidate whose label is null.

The pool is the snapshot the bridge sends; the ExApp builds its matrix once per request.

### Same input

The comparison is only meaningful if both engines see the same pool, order included. `loadDetections()` builds
`$detections` in the order MariaDB returns batched `IN()` results, which is not by id. So the command sends the pool in
the exact key order of the `$detections` array PHP is about to use, and passes the same array to PHP.

Across chunks, both engines start each chunk from **PHP's** state after the previous chunk. One difference therefore
never compounds into the next chunk's numbers.

### What counts as a difference

Per chunk, after both engines ran:
- **Decisions**: the same `action` for every attempted id.
- **Existing people**: a row joined to a person id > 0 must have the same id in both.
- **New people**: PHP's temporary labels and the ExApp's map one to one (the first co-occurrence fixes the mapping; a
  row that breaks the mapping is a difference). This compares who is grouped with whom, not numbering.

Each difference is classified by recomputing, in PHP, the distances for the attempt that produced it:
- `boundary`: a candidate's distance is within 1e-9 of `max_distance`. Summation order differs between PHP's loop and
  BLAS, so last-bit differences can move a face across the threshold.
- `tie`: two candidates with different non-null labels at distances within 1e-12. Duplicate photos give identical
  embeddings, so this does happen.
- `unexplained`: anything else. **One `unexplained` is a failed comparison.**

## Steps

### C1 ExApp: cache and `/cluster`

Files: `ex_app/lib/main.py` (or a new module beside it, e.g. `ex_app/lib/clustering.py`, imported by `main.py`),
`Makefile`, `docker-compose.yml`, `Dockerfile` if a dependency is added (it should not be: NumPy is already there,
SQLite is in the standard library).

- **Cache**: SQLite at `$FACEWRIGHT_DATA_DIR/embeddings.sqlite` (default `/data`), table
  `embeddings(id INTEGER PRIMARY KEY, crc INTEGER NOT NULL, vec BLOB NOT NULL, seen_at INTEGER NOT NULL)`.
  `put` upserts; `check` updates `seen_at` for the ids that match. Open a connection per request (endpoints run in a thread pool).
- **Volume**: `make run` mounts a named volume `facewright-data:/data` and sets `FACEWRIGHT_DATA_DIR=/data`. Without it,
  every container restart empties the cache, which is correct but slow.
- **Threads**: the endpoints are plain `def`, not `async def`, so FastAPI runs them in its thread pool and `/heartbeat`
  and `/detect` keep answering during a pass. Cap BLAS threads: `OMP_NUM_THREADS`, `OPENBLAS_NUM_THREADS` and
  `MKL_NUM_THREADS` default to 2 in the `Dockerfile` (`ENV`). ONNX Runtime spreading over every core correlated with
  native crashes and host reboots on this hardware (see the comment at the top of `main.py`), and NumPy's default is
  the same.
- **The embeddings are biometric data.** Add `POST /cluster/cache/clear` (empties the table) for the reset command
  (later) and for the operator. Pruning by `seen_at` belongs to the switch-over, not here.
- Log one line per `/cluster` call: pool size, attempts, `compute_ms`. Never log embeddings.

**Verify:**
- On dev the ExApp is the compose service (`docker compose up -d --build facewright`). **Never `make build` or `make run`
  on this host for dev work: they rebuild and restart the live container.** `/heartbeat` answers while a `/cluster`
  call with a 50k synthetic pool runs (time a heartbeat during the call).
- A put with a wrong checksum stores nothing (400; the count is unchanged).
- On dev, for 20 real rows: `SELECT id, CRC32(embedding) FROM oc_facewright_faces` equals `zlib.crc32` of the same blobs
  (fetch them hex-encoded) and PHP's `crc32()`. **If they differ, stop**: the design depends on it.
- **Body size through HaRP**: dev has no HaRP (compose talks to the ExApp directly), so this is answered by HaRP's
  configuration on live, read-only, and proven in C5.

**Done (2026-11-08).** `dev/exapp-cluster.sh` (with `exapp_call.php`, `exapp_cluster_gen.py`, `exapp_match_selftest.py`)
checks all of the above through AppAPI, plus `match_chunk()` against a line-by-line Python port of the PHP loop
(five synthetic pools of two chunks each, and a deterministic tie between two people that pool order must decide; the
planted fault "pool order reversed" is caught there and only there, because random data has no such ties). Planted
fault for the put: storing each face as it is validated; the suite fails on "cache unchanged". Results:
- The CRCs agree on 20 real rows (MariaDB, PHP, Python).
- Puts of 2,000 faces (5.3 MB each) take at most about 150 ms; a `check` of 50,000 pairs is 1.2 MB, a 50,000-face
  `/cluster` body 2 MB.
- `/cluster` on 50,000 faces with 300 attempts: 2.4 s compute, 0.5 s loading from SQLite, 3 s round trip. The float64
  matrix is 50,000 × 512 × 8 bytes, about 205 MB, while a call runs (not measured as container memory).
- HaRP on live (read-only, v0.4.8): no `http-buffer-request` and no body limit in its HAProxy configuration, `timeout
  server 1800s`; bodies stream to the ExApp. The batch sizes stay as above until C5 proves them through HaRP.

### C2 Bridge: PHP clustering usable in memory, no behaviour change

`assignClusters()` creates people in the database while it matches (`createCluster()` → `FaceStore::allocateCluster()`).
The comparison must not write, so:

- `assignClusters()` takes a `callable(): int $newCluster`. `clusterUser()` and `clusterUserChunk()` pass
  `fn () => $this->createCluster($userId)`, so the production path is unchanged.
- New public methods on `ClusteringService` for the comparison, both writing nothing:
  - `loadPoolForComparison(string $userId, bool $reopen): array`, returning `loadDetections()`'s result. With
    `$reopen`, unclusterable rows are also put into `pendingIds` (in memory only; this is what `clearUnclusterableMarks()`
    followed by a pass would see).
  - `assignInMemory(array &$detections, array $ids, float $maxDistance, int $minFaces, callable $newCluster): array`, a
    thin public wrapper around `assignClusters()`.
- `persistAssignments()` refuses (throws `\LogicException`) any cluster id ≤ 0, so a temporary label can never reach the
  database by mistake.

**Verify** (the production path must behave exactly as before):
- `dev/clustering-sets.sh save` on the commit before, `compare` after: identical.
- `dev/clustering-truth.sh` with `PEOPLE=50`: passes as before (15 of 15 at the last run).
- `dev/persist-guard.sh` and `dev/chain-loop-test.sh dangling` and `assignable`: pass.
- Planted fault: make the production callable return a fixed existing id; `clustering-sets.sh compare` must fail. Revert.
- `dev/cluster-inmemory.sh --prepare` (new): the two new methods write nothing (checksums of our tables, Recognize's and
  `oc_jobs`), `$reopen` adds exactly the unclusterable rows, `faceIds` covers the pool, and `persistAssignments()`
  refuses a temporary label.

**Done (2026-11-08).** `loadDetections()` returns `faceIds` only when asked (`$withFaceIds`), so the production pool is
unchanged in memory. Results on dev:
- `clustering-sets.sh`: the old code saved twice gives identical sets (306 people for admin, 2 for bob), and the new
  code the same. The planted fault (one person for every new face) gives 1 and 1 and fails.
- `clustering-truth.sh` with `PEOPLE=50`: 15 of 15 (a fresh detection of all 750 photos, no cache for 50).
- `persist-guard.sh` 11 of 11, `chain-loop-test.sh dangling` 5 of 5 and `assignable` 4 of 4, `writers-cluster.sh` 16 of 16.
- `cluster-inmemory.sh --prepare`: 8 of 8 on 2,768 reopened faces (305 people formed in memory, nothing written). Planted
  fault (`assignInMemory()` also allocating a real person): the "nothing written" checks fail on `facewright_clusters`.
- `--prepare` exists because the restored dev data has only 4 unclusterable faces and nothing pending: without it a
  reopen forms no person and the run proves little, which the check now reports.

### C3 Bridge: the ExApp client

New `lib/Service/ExAppClusteringClient.php`:

- `syncCache(array $faceIds): void`: reads `id, CRC32(embedding)` for those ids in batches of 500 (one query per batch),
  calls `check`, then reads the missing blobs (batches of 500) and `put`s them base64-encoded, 1,000 per request or what
  C1 measured. Streams with `fetch()`; never holds every blob at once.
- `cluster(array $pool, array $attempt, float $maxDistance, int $minFaces): array`: calls `/cluster`; on 409 runs
  `syncCache()` for the missing ids and retries once; a second 409 is an error.
- Calls go through `PublicFunctions::exAppRequest('facewright', ..., $userId, 'POST', $body, ['timeout' => 120])`, with
  the user being clustered (the ExApp does not use it). A non-`IResponse` or a status other than 200 throws an exception
  with the status and body; nothing here is retried silently.
- Payload sizes and round-trip times are returned with the result, for the command to print.

**Verify:** unit-level through the command in C4. Planted fault for the 409 path: clear the cache (`/cluster/cache/clear`)
between `syncCache()` and `cluster()`; the retry must recover, and with the retry removed the call must fail.

**Done (2026-11-08).** `lib/Service/ExAppClusteringClient.php` and `ExAppClusteringException.php`. Beyond the plan:
`checksums()` computes the CRC in PHP from the blob on databases other than MariaDB/MySQL; `upload()` sends the checksum
of the blob it just read, so a face re-detected since `checksums()` is uploaded consistently and the pool's older
checksum makes `/cluster` answer 409; `takeStats()` returns request count, bytes and time for the command's report.
`dev/exapp-client.sh` (+ `exapp_client.php`; the check-line parser moved into `lib.sh` as `php_checks`) checks, on
dev's admin (2,756 faces):
- the SQL CRC32 equals `crc32()` of the blob as read (1,000 faces);
- a cold sync uploads every face (4 requests, 7.4 MB, about 135 ms), a warm one nothing (1 request, about 20 ms);
- a stale entry (another face's embedding under a face's id) is replaced, and only it;
- `/cluster` after the cache was emptied recovers with one 409, a sync and one retry; planted fault (no retry): fails;
- a face that cannot be synced throws after one retry instead of looping;
- with the ExApp container stopped, a sync throws (`Could not resolve host`), and the ExApp comes back.

### C4 `occ facewright:cluster-compare`

New `lib/Service/ClusteringCompareService.php` and `lib/Command/ClusterCompare.php`, registered in `info.xml` (version
bump).

    occ facewright:cluster-compare <user> [--reopen] [--chunks=<n>] [--chunk-size=300]
                                          [--max-distance=<f>] [--min-faces=<n>] [--show=20]

Thresholds default to the user's effective settings (the same source `ClusterScanJob` is started with). Per chunk:

1. Load the pool once at the start (`loadPoolForComparison`); `syncCache()` for every face id in it.
2. Take the next `chunk-size` ids from `pendingIds` after the cursor, as `clusterUserChunk()` does.
3. Run PHP: `assignInMemory()` on a copy of `$detections`, with `$newCluster` returning `-1, -2, ...` (resetting per
   chunk). Time it.
4. Run the ExApp: `cluster()` with the same pool in `$detections`' key order, labels as they stand. Time it (round trip
   and the ExApp's `compute_ms`).
5. Compare and classify ("What counts as a difference"). Keep the first `--show` differences with their distances.
6. Carry PHP's labels forward as the state for the next chunk, with every temporary label replaced by a positive
   stand-in id above every real person id (e.g. `max(id) + 1,000,000 + n`), consistently for both engines. In
   production those people would exist by then; and `/cluster` refuses labels ≤ 0, and numbers its new people from `-1`
   again on every call, so carrying `-1` forward would collide.

Output: per chunk one line (attempted, new, joined, skipped per engine, differences by class, PHP ms, ExApp ms, payload
bytes); at the end the totals, PHP's peak memory (`memory_get_peak_usage(true)`), and the shown differences. Exit 0 if
no `unexplained`, 1 otherwise, 2 if there was nothing to attempt (say so: on a library with no pending faces, use
`--reopen`).

**It writes nothing to Nextcloud's database.** It fills the ExApp's cache, which is a write to the ExApp's volume.

**Verify:**
- Writes nothing: before and after a run, `COUNT(*)` and `CHECKSUM TABLE` of `oc_facewright_user_faces`,
  `oc_facewright_clusters`, `oc_recognize_face_detections`, `oc_recognize_face_clusters`, and `COUNT(*)` of `oc_jobs`:
  all identical. (Run with processing paused, or a cron tick will change them.)
- On the ground-truth user (`dev/clustering-truth.sh` leaves one; `--reopen`, and once after `occ facewright:cluster
  --reset` so everything is pending): 0 `unexplained`. Record `boundary` and `tie` counts.
- Planted faults, each must give `unexplained` > 0 and exit 1: the ExApp's threshold compared with `<` instead of `<=`
  and `max_distance` set to a value equal to one real distance (pick it from the data); the ExApp ignoring
  `min_faces`; the bridge sending the pool sorted by id instead of in `$detections` order on data where that order
  differs (if no tie exists in dev data, say so and skip this one rather than pretend).
- Larger: a synthetic pool is not needed; the live run in C5 is the scale test.

**Done (2026-11-08).** `lib/Service/ClusteringCompareService.php`, `lib/Command/ClusterCompare.php`, registered in
`info.xml` (0.10.1; on dev `occ app:update --showonly` listed nothing and `occ upgrade` brought back no Recognize job).
How the design was filled in:
- **Memory:** PHP matches in place on the pool (no copy of the vectors); the ExApp's request is built from the labels
  before the chunk. A live-sized pool is held once.
- **Attempts:** a chunk attempts the pending ids above the cursor that are still unlabelled, as production would after
  reloading the pool.
- **Differences:** every row either engine changed must end with the same label (people > 0 equal, new people mapped one
  to one), and the decision counts (new, join, skip) must agree. A row that differs is classified at its own distances,
  then at the attempted rows that gave it its label in either engine (at most 5); at most 50 rows per chunk are
  classified, the rest count as unexplained, so the cap can only make a run stricter. Differing counts with no
  `boundary` or `tie` in the chunk count as one `unexplained`.

`dev/cluster-compare.sh` (results 2026-11-08):
- admin, 2,768 faces reopened: 10 chunks, 305 new and 5 skipped in both, 0 differences; PHP 6.3 s and 80 MB peak, the
  ExApp 0.3 s round trip. Nextcloud's tables (ours, Recognize's, `oc_jobs`) unchanged by count and `CHECKSUM TABLE`.
- the ground-truth user, 750 faces pending, chunks of 100: 50 new in both, 0 differences. Then clustered for real and
  every third assigned face reopened (250): 50 joins to existing people in both, 0 differences.
- nothing pending: exit 2.
- Planted faults in the ExApp, each exit 1 with unexplained differences: `min_faces` ignored (admin's pool); threshold
  0.15 too tight (ground-truth user). The second shows only in the decision counts (2 joins instead of being pulled in
  by their person's first face; the final groupings are equal), which is why the counts are compared.
- **What dev cannot test.** Dev's data sits far from the threshold: neither user's result changes anywhere between 0.4
  and 0.75 (the ground-truth user's first change is at 0.35). So no `boundary` or `tie` case occurs on dev, and the
  plan's `<` vs `<=` fault is not usable as written: it is exactly what the `boundary` class exists to explain, so it
  would be classified, not reported unexplained. The pool-order fault needs a tie between two people, which dev lacks;
  `dev/exapp_match_selftest.py` (C1) covers that order with a constructed tie. The live run is the first test of both
  classes.

### C5 On the live instance (the user's go-ahead for each action)

1. Fresh dump. Deploy the ExApp (new image, volume) and the bridge (version bump, `occ app:update --showonly` first,
   `occ upgrade`, then delete Recognize's `MaintenanceJob` if it came back: see CLAUDE.md).
2. `--reopen --chunks=3` for the smaller library. Read the timings, the peak memory and the differences.
3. The full `--reopen` for both libraries, one at a time, outside busy hours: the PHP side is what costs hours (about
   1 hour and 5 to 6 hours by the estimate above). The ExApp side should be minutes.
4. Record in this document: per library, differences by class, total PHP and ExApp time, PHP peak memory, cache size
   on disk, payload sizes. These numbers decide the switch-over.

**Progress (2026-11-08).** Done with the user's go-ahead:
- Dump and rollback material in the operator's backups (`facewright-pre-c5`: full database, the live 0.10.0 bridge,
  counts, the previous `deploy.env`); the previous ExApp image tagged `facewright:pre-c5`. The running image dated from
  2026-07-29, but its `main.py` and packages equal the repo's before C1, so the rebuild added C1 and nothing else.
- **ExApp:** after `make run`, every signed request answered 401: `deploy.env`'s `APP_SECRET` was not the secret
  AppAPI has registered (the old container had been started with the registered one some other way). Fixed by running
  with the registered secret and correcting `deploy.env`; not by re-registering. `make verify` cannot catch this,
  because `/heartbeat` is exempt from signatures: after any ExApp redeploy, run one signed request (a read-only
  `/detect` through `exAppRequest`). Processing was paused during the swap; nothing was marked failed. Note that
  `nc_py_api` logs both secrets in full on a mismatch.
- **Bridge:** `occ app:update --showonly` listed three unrelated store updates, which `occ upgrade` would have installed.
  This deploy adds only a command, so it was copied with `info.xml` at 0.10.0 and no upgrade (CLAUDE.md's rule for
  command-only changes); live therefore reports 0.10.0 with the 0.10.1 code. The other four files that differed from
  live differed in comments only (compared token by token).
- user1, `--chunks=3`: 900 attempts against a 22,097-face pool, 0 differences; PHP 50 s per chunk, the ExApp 1.1 s round
  trip. The cold sync sent 59 MB in 25 requests through HaRP in 2 s.
- **user1, full `--reopen`:** 13,713 attempts in 46 chunks, 0 differences of any class; both engines 1 new, 1 join,
  13,709 skip. PHP 2,285 s (38 min), peak 350 MB; the ExApp 48 s round trip, 41 s compute, 29 MB sent. 47× faster.
- **user2, full `--reopen`:** a pool of 54,924, 31,126 attempts in 104 chunks, 0 differences of any class; both engines
  14 new, 100 join, 30,954 skip. PHP 12,943 s (3 h 36 min), peak 786 MB; the ExApp 282 s round trip, 239 s compute
  (about 7.7 ms per attempt), 167 MB sent over the 104 requests (each carries the whole pool). 46× faster. The cold sync
  checked 54,924 faces and uploaded 34,376 (the rest were shared with user1) in 38 requests, 93 MB, 3.5 s.
- **The cache on live** after both users: 56,473 embeddings, 222 MB on disk (about 4 KB per face against a 2 KB blob).
- **C5 is complete: both libraries agree exactly.** The S3 timeout (600 s) holds: one request for user2's full reopen is
  about 240 s of compute.

## Deviations from what was discussed

- **No refactor of the writing path for the comparison.** Injecting the "create a person" step into
  `assignClusters()` lets the comparison use temporary labels while production keeps creating people as today. The
  deferred creation that `exapp` mode needs is built with that mode, not before.
- **The checksum is computed in SQL, not stored.** Nothing to migrate or backfill, and no writer can leave it stale.
  Storing it saves I/O and is part of the switch-over.
- **No `shadow` mode in cron.** The command gives the same evidence on demand, at a scale (a full reopen) that cron would
  only reach after a threshold change.

# Part 2: The switch-over (S1 to S7)

Only after C5 is clean for both libraries, and each live step only with the user's go-ahead. Same working rules as
Part 1 ("How to work this plan"). The goal: `ClusterScanJob` uses the ExApp for matching, PHP stays as the fallback, and
nothing about what is written, or when, changes: `persistAssignments()` stays the only writer and its guards (a
human's change wins) stay the gate.

## What changes, and what does not

| | Today (`php`) | After (`exapp`) |
|---|---|---|
| Pool loaded in PHP | ids, labels **and vectors** (about 12 KB per face) | ids, face ids, checksums and labels (no vectors) |
| Matching | `assignClusters()`, 300 attempts per chunk, the pool reloaded per chunk | `/cluster`, every pending id in one request |
| People created | during matching (`createCluster()`) | after the ExApp answers, one per new label, just before the write |
| Writing | `persistAssignments()` | `persistAssignments()`, unchanged |
| Stop condition | a full pass wrote nothing → mark the rest unclusterable | the same; one request is one full pass |
| Pool selection (`selectWantedIds()`, 20 per person) | as is | as is |
| Tie order | the order MariaDB returns the vector batches in | ascending row id |

The last row is a real, deliberate difference: PHP's order is not stable either (unordered `IN()` batches), and ties
between two people are rare (C5 found none on user1). It changes which person wins a tie, nothing else.

## Decisions taken here

- **Engine switch:** app config `facewright_bridge` / `clustering_engine`, `php` (default when unset) or `exapp`. Read
  when a chain starts and stored in `ClusterScanJob`'s argument as `engine`, so a running chain does not change engine
  halfway. `occ facewright:cluster` gets `--engine=php|exapp`, defaulting to the config.
- **Fallback per chain, not per call:** if an ExApp call fails (`ExAppClusteringException`), the job logs a warning and
  re-adds itself with `engine: php` and `afterId: 0`, so the rest of that chain is today's chunked PHP chain. The next
  chain (`ClusteringMaintenanceJob`, hourly) tries the ExApp again. Mixing per call would mix a whole-pass engine with a
  cursor-based one inside one chain.
- **No chunking under `exapp`,** as decided with the user. One request attempts every pending id. Size its timeout from
  C5: `compute_ms` per attempt at user2's pool size, times the largest pending count expected (a full reopen of user2,
  about 31,000), with a margin of 2. HaRP's server timeout is 1800 s (C1). If that product comes out above 600 s,
  stop and bring the numbers to the user: then a cap on attempts per request is needed after all.
- **Stored checksum:** a nullable `embedding_crc` column on `facewright_faces`. `NULL` means "unknown": readers then
  compute `CRC32(embedding)` in SQL as today, so the column is an optimisation and never a source of wrong answers.
  It is written by every writer of `embedding` and filled for old rows by a resumable command.
- **Cache hygiene:** the ExApp prunes entries not seen for 30 days by itself (at most once a day, inside `check`); the
  bridge tells it to forget faces it deletes, after the commit and best-effort (a failure is logged, the prune is the
  backstop); `occ facewright:reset` clears the whole cache.

## Steps

### S1 Stored checksum

- Migration (next version after **0.10.1**: live's `info.xml` says 0.10.0 only because C5 deployed a command without an
  upgrade; the repo is at 0.10.1): `facewright_faces.embedding_crc`, `Types::BIGINT`, nullable, unsigned not required
  (CRC32 fits in a signed BIGINT). No data in the migration.
- Writers: `DetectionService::upsertFace()` sets `embedding_crc = crc32($blob)` in the same `setValues()` call, and so
  does `FaceMigrationService` (Stage 1 backfill, refuses once our tables hold faces, deleted in Stage F.2): both use
  `setValues()`, which updates an existing row in place, and an update that kept an old checksum would make it wrong,
  not unknown. `grep -rn "'facewright_faces'" lib/` must show no other writer of `embedding`.
- `occ facewright:embedding-checksums [--dry-run] [--chunk=5000]`: keyset on `id` over rows with `embedding_crc IS
  NULL`; on MariaDB/MySQL `UPDATE ... SET embedding_crc = CRC32(embedding) WHERE id BETWEEN ...`, elsewhere in PHP.
  Prints rows done; resumable by construction (it only selects `NULL`).
- `ExAppClusteringClient::checksums()` reads `COALESCE(embedding_crc, CRC32(embedding))` (PHP fallback on other
  databases unchanged).
- `occ facewright:own-tables --verify` gains one check: no row where `embedding_crc` is set and differs from
  `CRC32(embedding)`.

**Verify:** on dev, after the backfill, `SELECT COUNT(*) FROM oc_facewright_faces WHERE embedding_crc IS NULL OR
embedding_crc <> CRC32(embedding)` is 0; a forced re-detection (`occ facewright:detect`) keeps it 0; planted fault (the
upsert without the column, then a re-detection that changes the embedding: edit one row's blob by hand first) makes
`--verify` fail. `dev/exapp-client.sh` and `dev/cluster-compare.sh` still pass.

**Done (2026-11-08), on dev only (bridge 0.11.0 there).** Migration `Version000008000Date20261108120000`, the
command, both writers, the client's `COALESCE`, and `--verify`'s `embedding_crc_wrong` (zero) and
`embedding_crc_unknown` (info). `dev/embedding-crc.sh`, 20 of 20:
- the backfill fills all 2,797 dev faces; a second run has nothing to do; with every second row set back to NULL it
  fills exactly those (resumable);
- a wrong stored checksum planted in the data fails `--verify`;
- the client returns `CRC32(embedding)` with every checksum stored and with a quarter of them NULL;
- a forced re-detection of the real test portrait, whose stored embedding and checksum were made consistently stale,
  replaces both; with the checksum taken out of the upsert (planted in the code) the same re-detection leaves a wrong
  checksum and `--verify` fails.
- **Dev data:** admin's files on dev are synthetic fixtures (`fw_*.jpg`, about 900 fake faces each, one all-zero
  embedding), which a re-detection cannot reproduce; the suite uploads the real portrait instead.
- **Snapshots:** `dev/snapshot.sh restore` replays `DROP`/`CREATE TABLE`, so the snapshot "original" taken before the
  migration removed the column again. It was re-saved after the upgrade (the old one kept as `original-pre-s1`), and
  `clustering-truth.sh`'s detection cache (plain `INSERT`s without column names) was rebuilt by its key changing.
  Any later migration needs the same: restore, upgrade, save.
- Regression after the change to detection: `clustering-truth.sh` (`PEOPLE=50`, fresh detection) 15 of 15,
  `writers-files.sh` 21 of 21, `exapp-client.sh` 12 of 12, `cluster-compare.sh` 16 of 16.

### S2 ExApp: forget and prune

- `POST /cluster/cache/forget` `{"faces": [faceId, ...]}` → `{"removed": n}`.
- In `cache_check`: at most once a day (a timestamp in a one-row table, or the file's own metadata), `DELETE FROM
  embeddings WHERE seen_at < now - 30 days`. Log the count.
- `/cluster`: no change. Its body for a full reopen of user2 is about 2 MB pool plus 31,000 attempt ids; C5 already sent
  pools of that size.

**Verify** (extend `dev/exapp-cluster.sh`): forget removes exactly the named ids; with the clock moved (set `seen_at`
back in SQLite for some ids), the next `check` removes those and only those, and not twice in one day.

**Done (2026-11-08), on dev only.** `forget` and the prune are in `ex_app/lib/clustering.py` (the prune's last run is
kept in a one-row `meta` table; `FACEWRIGHT_CACHE_PRUNE_DAYS`, default 30). `dev/exapp-cluster.sh`, 28 of 28:
forget removes exactly 5 named ids (a repeat and an unknown id ignored) and `check` then names those 5; with the last
prune set two days back and 3 entries aged to 31 days, a `check` prunes those 3 and records today; aged entries are not
pruned again the same day; entries seen 29 days ago stay. Planted fault (the daily gate removed): "not again on the same
day" fails. Also found and fixed: **the suite's opening clear never ran.** It came before the first `push_files`, so
`exapp_call.php` was missing, and `|| true` hid that; the check after it passed only because the previous run had
left the cache empty. It now pushes first and checks the clear's status (verified starting from a non-empty cache).

### S3 Bridge: the ExApp engine

In `ClusteringService`:
- `loadPoolIds(string $userId): array` — `selectWantedIds()` plus checksums (`ExAppClusteringClient::checksums()`), no
  vectors: `['rows' => list<[rowId, faceId, crc, label]>, 'pendingIds' => list<int>]`, rows ascending by id, labels
  normalized exactly as `loadDetections()` does (unclusterable → null, and in `pendingIds` only if state is pending).
- `clusterUserExApp(string $userId, float $maxDistance, int $minFaces): array` — `loadPoolIds()`, `syncCache()`,
  `cluster()` with every pending id; then turn the answer into a write:
  - each distinct negative label → `FaceStore::allocateCluster($userId)`, collected as `$createdClusterIds`;
  - a map `rowId => ['cluster_id' => personId]` for every row the answer labelled;
  - `persistAssignments($userId, $map, array_keys($map), $createdClusterIds)` — unchanged; it reads only `cluster_id`,
    which is also why `dev/persist_guard.php` keeps working. It already deletes created people that ended up empty.
  - Return the same shape as `clusterUserChunk()` (`attempted`, `newClusters`, `joined`, `skipped`, `written`,
    `remainingUnclustered`, `pendingUnassignedIds`), with `pendingUnassignedIds` = pending on entry and not written.
- Nothing else in the class changes; the PHP path keeps loading vectors.

In `ClusterScanJob::run()`:
- `engine = $argument['engine'] ?? 'php'`. For `exapp`: call `clusterUserExApp()`; on success apply the same stop logic
  as today with "a full pass" being this one call (`written > 0` → re-add with `passProgress` reset; otherwise mark the
  returned `pendingUnassignedIds` unclusterable and stop). On `ExAppClusteringException`: log a warning with the
  message and re-add `[...$argument, 'engine' => 'php', 'afterId' => 0, 'passProgress' => false]`.
- `ClusterTriggerService::enqueue()` writes `engine` from the app config. Its double-start guard (by `userId` among
  pending jobs' arguments) is unchanged; an old-style argument without `engine` means `php`.

`occ facewright:cluster --engine=php|exapp`: the synchronous path uses `clusterUserExApp()` for `exapp`.

**Verify** on dev (all with `clustering_engine=exapp` unless stated):
- `dev/clustering-sets.sh`: sets saved with `php` and with `exapp` from the same restored data are identical (dev has
  no ties, see C4).
- `dev/clustering-truth.sh` with `PEOPLE=50`: 15 of 15.
- `dev/persist-guard.sh`, `dev/chain-loop-test.sh dangling` and `assignable`, `dev/writers-cluster.sh`: pass (the chain
  tests start chains, so they exercise the job under `exapp`).
- New `dev/cluster-engine.sh`: (a) a chain under `exapp` runs as one job per pass (count job runs), ends, and marks the
  same unclusterable rows as a `php` chain; (b) the ExApp stopped mid-chain: the chain falls back, finishes in PHP,
  logs one warning, and the result equals a pure `php` chain; (c) PHP peak memory of one `exapp` pass on admin's
  reopened pool, against a `php` chunk's.
- Planted fault: `clusterUserExApp()` writing negative labels without allocating → `persistAssignments()` must throw
  (C2's guard) and the job must fall back, not write.

**Done (2026-11-08), on dev only.** As planned, with these specifics:
- `ClusteringService::clusterUserExApp()` and `loadPoolIds()`; the engine is `ENGINE_PHP` / `ENGINE_EXAPP`, read by
  `configuredEngine()` (anything but `exapp` is PHP). Before writing, the answer is checked against the pool: only rows
  that had no label, each given an existing person of the pool or a label a `new` decision introduced; anything else is
  an `ExAppClusteringException`. People are allocated in the order the ExApp made them (-1, -2, ...); if anything fails
  after that, they are deleted again (`deleteEmptyUntitled`).
- `ClusterScanJob` falls back on `ExAppClusteringException` **and** `LogicException` (persistAssignments() refusing a
  label ≤ 0 is a bug in this path, and the fallback must still happen; nothing is written by then).
- `/cluster`'s timeout is 600 s (`CLUSTER_TIMEOUT`; the cache calls keep 120 s). Sized from user2's C5 chunks: 2.26 to
  2.30 s of compute per 300 attempts against a pool of about 55,000, about 7.5 ms per attempt; a full reopen of user2
  (about 31,000 attempts) is then about 235 s, twice that about 470 s.
- `occ facewright:cluster --engine=php|exapp` (default: the config). No fallback there: an ExApp failure exits 1 having
  written nothing.
- Not done: a cap on attempts per request (not needed by the numbers above); `skippedForeign` is 0 under `exapp` (a
  blob of the wrong length is refused by the ExApp's put, and the chain falls back to PHP, which skips it).

Verified on dev with `clustering_engine=exapp`: `clustering-sets.sh` php against exapp identical (admin 306 people,
bob 2; 8.3 s against 1.7 s), `clustering-truth.sh` (`PEOPLE=50`, detection from cache) 14 of 14, `persist-guard.sh` 11
of 11, `chain-loop-test.sh dangling` 5 of 5 and `assignable` 4 of 4, `writers-cluster.sh` 16 of 16 (each confirmed in
the ExApp's log as served by `/cluster`); unchanged: `exapp-client.sh` 12 of 12, `cluster-compare.sh` 16 of 16,
`cluster-inmemory.sh --prepare` 8 of 8. New `dev/cluster-engine.sh`, 21 of 21, on admin reset (2,789 pending):
- (a) the php chain takes 13 runs, the exapp chain 2 (one pass that writes 2,783, one that writes nothing and marks
  6 unclusterable); same people and same unclusterable faces;
- (b) the ExApp stopped before the first run: that run writes nothing, logs one warning and re-queues the chain as
  `engine: php` from `afterId: 0`; the chain stays in PHP after the ExApp is back and ends with the php chain's result;
- (c) PHP peak memory: a php chunk of 300 rises from 16.3 MB to 53.1 MB, a whole exapp pass of 2,789 to 29.0 MB;
- (d) planted: negative labels written without making people. persistAssignments() refuses, nothing is written, the
  warning names the refusal, the chain goes on in PHP and ends with the php chain's result;
- (e) planted: a failure after the 306 people were made. All 306 are deleted again and nothing is written.

### S4 Bridge: cache hygiene

- `AccessSyncService` (the `facewright_faces` delete at the orphan cleanup) and `ResetService` (per-face delete):
  after their transaction commits, `ExAppClusteringClient::forget($faceIds)`, catching and logging
  `ExAppClusteringException`. `occ facewright:reset`: `clear()` after the reset.
- Always, whatever `clustering_engine` says: `cluster-compare` fills the cache in `php` mode too. A failure never fails
  the caller.

**Verify:** `dev/writers-files.sh` (a delete) and `dev/own-traps.sh` pass; after a file delete the ExApp's cache no longer
holds its face ids (read the SQLite count by id); with the ExApp stopped, a delete still succeeds and logs one warning.

**Done (2026-11-09), on dev only.** Specifics:
- `ExAppClusteringClient::forget()` (timeout 10 s: it runs inside a file delete request) and `clear()`, both sent
  without a user.
- The one deleter of `facewright_faces` rows outside a reset is `AccessSyncService::pruneExceptUsers()` when nobody has
  access left (`forgetFile()`). It reads the file's face ids, deletes, then forgets them. **Batched per delete:**
  `PruneService` walks a deleted folder file by file inside the delete request, so it runs the walk in
  `AccessSyncService::batchForgets()`, which sends one forget at the end (and every 10,000 ids). Outside it (the
  access reconcile) a file's faces are forgotten at once. Failures are logged as a warning and never reach the caller.
- `ResetService` empties the whole cache at the end (`clear()`), so no per-face forget there; `occ facewright:reset`
  prints whether that worked.

Verified with the new `dev/cache-hygiene.sh`, 20 of 20, on copies of the real portrait detected for admin: a file delete
removes its face from the cache and leaves the others; a folder of two is forgotten in **one** request (counted in the
ExApp's log); with the ExApp stopped a delete still answers 204, removes the face from our table and logs one warning;
`forgetFile()` called outside a walk forgets at once; `occ facewright:reset` empties the cache. Planted: the batched
forget removed, and the deleted face stays cached. Also `writers-files.sh` 21 of 21, `own-traps.sh` 11 of 11. Two
things the suite had to learn: the delete runs in the web server, whose opcache keeps old code for up to 60 s (so it
calls `reload_web` around the planted fault), and `docker logs --since` needs a nanosecond timestamp, or the previous
step's request is counted.

### S5 Docs

`CLAUDE.md`: in "Components", the engine switch, the fallback, the cache and its checksum rule (a cached embedding
counts only with the checksum the bridge sends; `embedding_crc` NULL means unknown, never "no checksum"); in "Job
semantics", `ClusterScanJob`'s `engine` argument and the fallback; the new command; close open item 7 (keep the numbers
from C5 as the reason). `dev/README.md`: the new suite.

### S6 Live: deploy, still on `php` (the user's go-ahead)

1. Fresh dump. `occ app:update --showonly`: **if store updates are pending, ask the user** before `occ upgrade`, which
   installs them all (C5 found three unrelated ones on 2026-11-08). The migration makes the upgrade unavoidable here,
   unlike C5.
2. Deploy the ExApp (S2) as in C5: `make build run`; `deploy.env` now holds the registered secret, check its hash
   against `oc_ex_apps` before `make run` anyway (C5's 401). Detection paused during the swap; one read-only `/detect`
   before unpausing.
3. Deploy the bridge, `occ upgrade`, delete Recognize's `MaintenanceJob` if it came back.
4. `occ facewright:embedding-checksums --dry-run`, then for real; `own-tables --verify`.
5. `cluster-compare --reopen --chunks=3` for user1 again: 0 differences (the checksums now come from the column).

### S7 Live: switch (the user's go-ahead)

1. `occ config:app:set facewright_bridge clustering_engine --value=exapp`.
2. Watch the next real chain (new photos, or start one with `occ facewright:cluster-scan`): the log line per pass,
   `oc_jobs`, the day-after checks (`project --dry-run`, `own-tables --verify`), and the People page.
3. Rollback at any time: `occ config:app:set facewright_bridge clustering_engine --value=php`. A chain already queued
   finishes on the engine it started with. Nothing written differs in kind, so there is nothing to undo; the column and
   the cache can stay.
4. After a week without fallbacks in the log, record it here; leaving `php` as the fallback is permanent by design.

## Open questions for later (not in S1 to S7)

- **The 20-per-person cap** (`CLUSTER_REPRESENTATIVE_CAP`) exists because PHP could not afford more. Under the ExApp,
  matching against every member is affordable; whether it improves results is open item 2's question (clustering
  quality), and needs the ground-truth set, not a guess.
- **Memories' over-merged clusters** (open item 2) and threshold tuning become cheap to experiment with:
  `cluster-compare` with `--max-distance` already gives both engines' answers in seconds on the ExApp side.

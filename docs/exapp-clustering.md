# Clustering in the ExApp: comparison first

The plan for CLAUDE.md's open item 7, written for a session that has not seen this repository before. It builds a
second clustering engine in the ExApp next to the PHP one and a command that runs both on the same input and reports
where they differ. **PHP stays the only engine that writes.** Switching over (an `exapp` mode, dropping chunking, a
stored checksum) is sketched at the end and is not part of this plan.

## Where things stand

Nothing is built yet. Next: step C1.

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
   or held with another checksum.
2. `POST /cluster/cache/put` `{"faces": [{"id": faceId, "crc": crc, "embedding": "<base64 of 2048 bytes>"}, ...]}` →
   `{"stored": n}`. 400 if a blob is not 2048 bytes or its `zlib.crc32` is not `crc`; nothing in that request is stored
   then. The bridge sends at most 1,000 faces per request (about 2.8 MB).
3. `POST /cluster` →
   ```
   {"max_distance": 0.5, "min_faces": 3,
    "pool":    [[rowId, faceId, label], ...],   // in the bridge's pool order, see "Same input"
    "attempt": [rowId, ...]}                    // ascending, the ids PHP will attempt in this chunk
   ```
   `rowId` is `facewright_user_faces.id`. `label` is `null` (pending or unclusterable, as `loadDetections()` already
   normalizes) or a person id > 0. Answer:
   ```
   {"decisions": [{"id": rowId, "action": "new"|"join"|"skip"|"already", "label": int|null, "matches": n}, ...],
    "labels":    [[rowId, label], ...],         // every pool row whose label this call changed
    "compute_ms": n}
   ```
   New people get labels `-1, -2, ...` in the order they were created. 409 `{"missing": [faceId, ...]}` if a face is not
   in the cache with the checksum last put (the bridge then runs 1 and 2 and retries once).

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
`Makefile`, `deploy.env.example`, `Dockerfile` if a dependency is added (it should not be: NumPy is already there,
SQLite is in the standard library).

- **Cache**: SQLite at `$FACEWRIGHT_DATA_DIR/embeddings.sqlite` (default `/data`), table
  `embeddings(id INTEGER PRIMARY KEY, crc INTEGER NOT NULL, vec BLOB NOT NULL, seen_at INTEGER NOT NULL)`.
  `put` upserts; `check` and `/cluster` update `seen_at`. Open a connection per request (endpoints run in a thread pool).
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
- `make build run register verify` on dev; `/heartbeat` answers while a `/cluster` call with a 50k synthetic pool runs
  (time a heartbeat during the call).
- A put with a wrong checksum stores nothing (400; the count is unchanged).
- On dev, for 20 real rows: `SELECT id, CRC32(embedding) FROM oc_facewright_faces` equals `zlib.crc32` of the same blobs
  (fetch them hex-encoded) and PHP's `crc32()`. **If they differ, stop**: the design depends on it.
- **Body size through HaRP**: send a `check` with 60,000 pairs and a `put` with 1,000 faces through AppAPI (a throwaway
  `php -r` calling `exAppRequest`, as in `dev/clustering-truth.sh`). Record the largest that passed. If HaRP limits
  bodies below what C3 needs, record the limit and size the batches to it.

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
6. Carry PHP's labels forward as the state for the next chunk; temporary labels from earlier chunks stay as they are
   (they are just non-null labels to both engines).

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

### C5 On the live instance (the user's go-ahead for each action)

1. Fresh dump. Deploy the ExApp (new image, volume) and the bridge (version bump, `occ app:update --showonly` first,
   `occ upgrade`, then delete Recognize's `MaintenanceJob` if it came back: see CLAUDE.md).
2. `--reopen --chunks=3` for the smaller library. Read the timings, the peak memory and the differences.
3. The full `--reopen` for both libraries, one at a time, outside busy hours: the PHP side is what costs hours (about
   1 hour and 5 to 6 hours by the estimate above). The ExApp side should be minutes.
4. Record in this document: per library, differences by class, total PHP and ExApp time, PHP peak memory, cache size
   on disk, payload sizes. These numbers decide the switch-over.

## Deviations from what was discussed

- **No refactor of the writing path for the comparison.** Injecting the "create a person" step into
  `assignClusters()` lets the comparison use temporary labels while production keeps creating people as today. The
  deferred creation that `exapp` mode needs is built with that mode, not before.
- **The checksum is computed in SQL, not stored.** Nothing to migrate or backfill, and no writer can leave it stale.
  Storing it saves I/O and is part of the switch-over.
- **No `shadow` mode in cron.** The command gives the same evidence on demand, at a scale (a full reopen) that cron would
  only reach after a threshold change.

## Later: the switch-over (not planned here)

Only after C5's numbers are clean, and with the user's decision:
- `clustering_engine` app config: `php` (default) or `exapp`. Under `exapp` the ExApp's labels are turned into people by
  the bridge (allocate the new ones, then write everything through `persistAssignments()`), and a failed ExApp call
  falls back to PHP for that run.
- **Chunking dropped under `exapp`**: one request per pass, attempting every pending id. `ClusterScanJob` keeps its
  stop condition ("a full pass made no assignment") and its paused handling.
- A stored `embedding_crc` column (migration, written by every writer of `facewright_faces.embedding`, a resumable
  backfill) so a pass reads no blobs when the cache is warm.
- Cache hygiene: prune by `seen_at`; `occ facewright:reset` and the deletion paths clear the matching entries or the
  whole cache.
- CLAUDE.md: the engine, the cache and its checksum rule in "Components" and the invariants; close open item 7.

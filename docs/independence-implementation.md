# Implementation plan: `docs/independence.md`, stage by stage

An executable plan for the roadmap in `docs/independence.md`. Written for a model session that has
not seen this repository before: every step names the files, the functions, the schema and the
verification, and says what must *not* be touched. Where this plan and `independence.md` differ,
this plan wins — it was derived from reading the code more closely and the differences are called
out in "Deviations from independence.md" at the end.

## How to work this plan

1. **Read `CLAUDE.md` first**, in full. The "Invariants" section is not optional; three of the
   rules there (column order, ownership predicate, job dedup semantics) are exactly the ones this
   work touches.
2. **Work on the compose stack, never on the production instance.** `docker compose up -d` and
   `bash dev-setup.sh` give a throwaway Nextcloud 33 with Recognize, Memories and the ExApp. The
   production instance (`nextcloud-app-1`, `nextcloud-db-1`, and the app copies under
   `/mnt/data/nextcloud/nc_apps/`) is read-only reference material for this plan. Do not run
   `occ` or SQL against it.
3. **One commit per numbered step**, message in the style of `git log` (imperative subject, a body
   that says why). Do not batch steps across a stage into one commit.
4. **Deploy mechanics that bite** (from CLAUDE.md, repeated because every stage needs them):
   - A new migration, a new `occ` command, or a new background job needs `<version>` bumped in
     `facewright_bridge/appinfo/info.xml` and `occ upgrade`.
   - Any change to `js/*.js` needs the same version bump (cache-buster is `md5($appVersion)`).
   - A new entry in `appinfo/routes.php` needs the web container restarted
     (`docker compose restart nextcloud`).
   - `IJobList::add()` dedupes on class + JSON argument.
5. **Do not touch `ex_app/`**, `Dockerfile`, `Makefile`, `start.sh`, or `requirements.txt`. The
   ExApp is already independent and none of this work changes its contract.
6. **Do not add a test framework.** There is none, and bootstrapping PHPUnit against Nextcloud is a
   separate task. Verification is `occ` commands, SQL counts, and the Memories UI on the compose
   stack, as listed under each step.
7. **Stop and report rather than improvise** when: a count pair in a verification step does not
   reconcile; a Memories or Photos write operation returns an error after Stage 2; or a query
   builder construct in this plan does not exist in `OCP\DB\QueryBuilder` on Nextcloud 33 (check
   `lib/public/DB/QueryBuilder/` in the container before substituting).

Conventions used below: `rfd` = `oc_recognize_face_detections`, `rfc` = `oc_recognize_face_clusters`.
Paths are relative to `facewright_bridge/` unless they start with `shim/`, `docs/` or `ex_app/`.

---

## Stage 0 — an API of our own, and honest `-1` semantics

**Goal.** One service that owns every people-level operation (list, rename, create, merge, delete,
detach, move), a session-scoped OCS API over it, and a redefinition of `cluster_id = -1` that turns
Finding 1 of `independence.md` from a bug into the intended behaviour. No schema change.

**Why `-1` changes meaning here.** Today `ClusteringService` reads `-1` as "Recognize rejected
this, re-evaluate it" (`RECOGNIZE_REJECTED`). But `-1` is also what Memories' person-delete writes
(via Recognize's `FaceClusterMapper::delete()`), and what Memories' "unassigned faces" view
displays, and what Memories' "move to person" action operates on. Reading `-1` as **unassigned:
excluded from clustering until a human assigns it** makes all three consistent, fixes Finding 1
without a schema change, and is what Immich does after "unassign face". The only cost is that
the few rows Recognize's HDBSCAN parked at `-1` in the past stop being re-evaluated — step 0.1
reopens those once, before the meaning changes.

### 0.1 One-time reopen of legacy `-1` rows (`occ facewright:reopen-unassigned`)

- New `lib/Command/ReopenUnassigned.php`, registered in `appinfo/info.xml` `<commands>`.
- Options: `--dry-run`, `--user=<uid>` (optional; default all users).
- Logic: `UPDATE rfd SET cluster_id = NULL WHERE cluster_id = -1 AND <ownRowsExpr>` (and
  `user_id = ?` if given). Print the matching count before, and rows affected after; with
  `--dry-run` print the count only. Use `DetectionService::ownRowsExpr($qb)` — never sweep
  without it (CLAUDE.md: legacy 128-dim rows are also at `-1`).
- Print a warning line: "After this, -1 means unassigned; run this once, before deploying step 0.2."
- The command stays in the tree (it is harmless to re-run: it will only ever touch rows a user
  has since unassigned, which is why the dry-run count matters).

**Verify:** on the compose stack, `UPDATE` two own rows to `-1` by SQL, run with `--dry-run`
(prints 2), run for real (prints 2 / 2 affected), `SELECT COUNT(*) ... cluster_id = -1` is now 0
for own rows.

### 0.2 Redefine `-1` in `ClusteringService`

File: `lib/Service/ClusteringService.php`.

- Rename the constant: `RECOGNIZE_REJECTED = -1` → `UNASSIGNED = -1`. Rewrite its docblock: what
  writes it (our API, Memories' person delete and face detach via Recognize's DAV, historical
  HDBSCAN rejects — reopened once by 0.1), what reads it (Memories' unassigned view,
  `UnassignedFacesHome`), and that clustering never seeds from it, never matches against it, and
  never assigns it as a side effect.
- `selectWantedIds()`: add `if ($clusterId === self::UNASSIGNED) { continue; }` before the
  positive-id cap, so unassigned rows are not fetched at all.
- `loadDetections()`: delete the `|| $clusterId === self::RECOGNIZE_REJECTED` branch; `-1` rows
  no longer arrive there. Keep the `UNCLUSTERABLE` normalization.
- `countUnclusteredByUser()`: drop the `orX(isNull, eq -1)` and filter `isNull('cluster_id')`
  only. Update its docblock (it no longer counts `-1`).
- `markUnclusterable()`, `clearUnclusterableMarks()`, `resetUser()`: unchanged. Note in
  `resetUser()`'s docblock that a reset also re-opens unassigned rows (`-1 → NULL`) — that is
  the intended meaning of "reset".
- `grep -rn RECOGNIZE_REJECTED lib/` must return nothing afterwards (`ResetService.php:88-89`
  has a comment mentioning it — fix the comment).
- Update CLAUDE.md's `cluster_id` bullet under "Schema mapping": `-1` = unassigned (ours and
  Memories'), excluded from clustering; keep the "do not use -1 for unclusterable" warning, it is
  still true and now has a second reason.

**Verify:** compose stack with ≥ 3 photos of one person clustered; delete the person in Memories
(faces go to `-1`); run `occ facewright:cluster <user>`; the cluster does **not** come back;
Memories' "Unassigned faces" shows the faces; "Move to person" onto a new person works (that goes
through Recognize's DAV `moveInto`, still present in Stage 0).

### 0.3 `PeopleService`

New `lib/Service/PeopleService.php`. Constructor: `IDBConnection $db`, `ClusterMergeService
$clusterMerge`, `ClusteringService $clustering`. Every method takes `string $userId` first and
scopes every query to it; every method throws `\InvalidArgumentException` with a user-readable
message on a bad id/name (the controllers map that to 400, the DAV layer in Stage 2 to
`Sabre\DAV\Exception\NotFound`/`Forbidden`).

Methods and their SQL (all through the query builder, no raw SQL):

- `listClusters(string $userId): list<array{id:int, title:string, count:int}>` —
  `SELECT rfc.id, rfc.title, COUNT(rfd.id) AS c FROM rfc LEFT JOIN rfd ON rfd.cluster_id = rfc.id
  WHERE rfc.user_id = ? GROUP BY rfc.id, rfc.title ORDER BY (rfc.title <> '') DESC, c DESC, rfc.id`.
  Include clusters with zero members (Memories' "create person" makes empty ones).
- `getCluster(string $userId, int $clusterId): array{id,title}` — 404-style exception if
  `user_id` mismatches.
- `resolveCluster(string $userId, string $idOrTitle): int` — move `ClusterMergeService::resolve()`
  here (it is `private` there; make `ClusterMergeService` call `PeopleService`'s, or move the
  merge itself — see 0.4). Numeric → id; otherwise exact title; ambiguous → exception.
- `listFaces(string $userId, int $clusterId): list<array{id:int, file_id:int, x:float, y:float,
  width:float, height:float}>` — `WHERE user_id = ? AND cluster_id = ? ORDER BY id`.
- `listUnassignedFaces(string $userId)` — same, `cluster_id = -1`.
- `facesForFile(string $userId, int $fileId): list<array{id, x, y, width, height, cluster_id,
  title}>` — LEFT JOIN rfc; this backs `nc:face-detections` in Stage 2. Restrict to `ownRowsExpr`
  so legacy rows never reach a UI through us.
- `createCluster(string $userId, string $title): int` — refuse `''`, refuse `/^[0-9]+$/` (an
  untitled cluster is displayed by its id, so a numeric title would be ambiguous — same rule as
  Recognize's `FacesHome::createDirectory()`), refuse an existing title for this user. Insert
  into `rfc` (`title`, `user_id`), return `getLastInsertId()`.
- `renameCluster(string $userId, int $clusterId, string $title): void` — same validation; on a
  duplicate title throw with the message "A person named X already exists; merge instead".
- `mergeClusters(string $userId, string $keep, string $merge, bool $dryRun = false): array{keepId,
  mergeId, moved}` — delegates to `ClusterMergeService` after 0.4 adds `$dryRun`.
- `deleteCluster(string $userId, int $clusterId): int` — `UPDATE rfd SET cluster_id = -1 WHERE
  cluster_id = ? AND user_id = ?`, then `DELETE FROM rfc WHERE id = ? AND user_id = ?`. Returns
  members unassigned. This is byte-for-byte what Recognize's `FaceClusterMapper::delete()` does,
  on purpose: both write paths must agree until Stage 2 removes one of them.
- `detachFace(string $userId, int $detectionId): void` — `UPDATE rfd SET cluster_id = -1 WHERE
  id = ? AND user_id = ? AND cluster_id > 0`; 0 rows affected → exception. Do not touch
  `threshold`.
- `moveFace(string $userId, int $detectionId, int $targetClusterId): void` — verify the target
  cluster belongs to `$userId`; `UPDATE rfd SET cluster_id = ? WHERE id = ? AND user_id = ?`.
  Accept faces currently at `-1`, `-2`, `NULL` or in another cluster.
- `deleteEmptyClusters(string $userId): int` — `DELETE FROM rfc WHERE user_id = ? AND NOT EXISTS
  (SELECT 1 FROM rfd WHERE rfd.cluster_id = rfc.id)`. Not called automatically; exposed for the
  `occ` command in 0.5 and for `PruneService` later (CLAUDE.md Open item 1 mentions empty-cluster
  cleanup after a prune).

Notes for the implementer:
- `NOT EXISTS` with a correlated subquery: build the inner query with a second
  `$this->db->getQueryBuilder()` and pass `$qb->createFunction('NOT EXISTS (' . $sub->getSQL() . ')')`;
  reference the outer alias inside the inner SQL by name. This pattern is used in Nextcloud core
  (`lib/private/Files/Cache/`); check it there if unsure.
- No `face_vector` is ever selected in this service.

### 0.4 `ClusterMergeService`: `--dry-run` and reuse

- Add `bool $dryRun = false` to `merge()`; when true, resolve both sides, count the rows that
  would move (`SELECT COUNT(*) ... cluster_id = mergeId`), and return without writing. Return
  shape becomes `array{keepId:int, mergeId:int, moved:int}` (update `PeopleSettingsController::
  mergeClusters` and `Command/MergeClusters.php` accordingly).
- Replace its private `resolve()`/`ownerOf()` with calls to `PeopleService::resolveCluster()` —
  or the other way round; there must be exactly one implementation.
- `Command/MergeClusters.php`: add `--dry-run`, print `keepId`, `mergeId`, `moved`. This closes
  CLAUDE.md Open item 6; remove that item.

### 0.5 `occ facewright:people` (inspection + maintenance)

New `lib/Command/People.php` (registered in `info.xml`): `facewright:people <user> [--faces=<clusterIdOrTitle>]
[--unassigned] [--delete-empty] [--dry-run]`. Lists clusters (id, title, count) by default;
`--faces` lists a cluster's faces (detection id, file id, box); `--unassigned` lists `-1` rows;
`--delete-empty` calls `deleteEmptyClusters()`. It is how every later step is verified without
the browser, so write it before the controller.

### 0.6 OCS controller

- New `lib/Controller/PeopleApiController.php extends OCSController`, constructor
  `(string $appName, IRequest $request, private ?string $userId, private PeopleService $people)`.
  Every action `#[NoAdminRequired]`; return `DataResponse`; 401 when `$userId === null`;
  `\InvalidArgumentException` → `DataResponse(['message' => ...], Http::STATUS_BAD_REQUEST)`.
- Routes, added under a new `'ocs' => [...]` key in `appinfo/routes.php` (keep the existing
  `'routes'` array untouched):

  | verb | url | action |
  |---|---|---|
  | GET | `/api/v1/people` | `listClusters` |
  | POST | `/api/v1/people` | `createCluster` (`title`) |
  | GET | `/api/v1/people/{id}/faces` | `listFaces` |
  | PUT | `/api/v1/people/{id}` | `renameCluster` (`title`) |
  | DELETE | `/api/v1/people/{id}` | `deleteCluster` |
  | POST | `/api/v1/people/{id}/merge` | `mergeClusters` (`merge`, `dryRun`) |
  | GET | `/api/v1/people/unassigned` | `listUnassignedFaces` (declare **before** `{id}` routes) |
  | PUT | `/api/v1/faces/{id}/cluster` | `moveFace` (`clusterId`) |
  | DELETE | `/api/v1/faces/{id}/cluster` | `detachFace` |
  | GET | `/api/v1/files/{fileId}/faces` | `facesForFile` |

  Resulting URLs: `/ocs/v2.php/apps/facewright_bridge/api/v1/people…`. Callers send
  `OCS-APIRequest: true` and the request token.
- `PeopleSettingsController::mergeClusters` (admin, takes `userId`) stays as the admin's
  cross-user entry point but calls `PeopleService`.
- Restart the web container after editing `routes.php`.

**Verify (from inside the compose `nextcloud` container, as `admin`):**
`curl -u admin:dev-admin-password -H 'OCS-APIRequest: true' http://localhost/ocs/v2.php/apps/facewright_bridge/api/v1/people?format=json`
lists the clusters `occ facewright:people admin` prints; a `DELETE` on one moves its faces to
`-1` (visible via `--unassigned`) and Memories shows them under Unassigned. Confirm authorization
the way CLAUDE.md says: `#[NoAdminRequired]` is present on every action, and a second non-admin
user gets only their own clusters.

### 0.7 Admin page uses the API for merge

`js/admin.js`: the merge form posts to the existing admin route today; leave that (it is the
cross-user one). Add nothing else to the UI in this stage — the point of Stage 0 is the seam,
not features. Bump `<version>` (a `routes.php` change alone does not need it, but 0.1 and 0.5
added commands, which do).

### 0.8 Docs

- CLAUDE.md: Components → Services: add `PeopleService`; `occ` list: add `facewright:people`,
  `facewright:reopen-unassigned`, `--dry-run` on `merge-clusters`; Invariants: the `-1` bullet as
  in 0.2; Open items: remove item 6, and in item 4 replace the "deleting a person … rebuilds the
  person untitled" bug with "fixed in Stage 0: `-1` now means unassigned"; leave the remove-face
  bug (it is fixed by Stage 2).
- `docs/independence.md`: in Finding 1, add "Fixed by Stage 0 (`-1` = unassigned)".

**Stage 0 done when:** all verifications above pass on the compose stack; `grep -rn
RECOGNIZE_REJECTED` is empty; `occ facewright:merge-clusters --dry-run` prints without writing.

---

## Stage 1 — own the embeddings and the ownership test

**Goal.** Embeddings live in a table we own, in binary; "is this row ours" is a join, not a
string length; per-file physical faces get a stable identity. `rfd` keeps carrying geometry,
`cluster_id` and — until Stage 2 — the JSON `face_vector`, because Recognize's DAV code still
decodes it. Nothing is dropped in this stage; it is fully reversible until Stage 2.

### 1.1 Schema: migration `Version000005000Date2026MMDD000000.php`

Two tables. Use the naming style of the existing migrations (`facewright_proc_*` index names).

`facewright_faces` — one row per physical face in a file:

| column | type | notes |
|---|---|---|
| `id` | bigint, autoincrement, PK `facewright_faces_id` | |
| `file_id` | bigint, notnull | |
| `face_index` | int, notnull | 0-based detection order within the file, from the ExApp response |
| `x`, `y`, `width`, `height` | float, notnull | normalized, top-left + size — same as rfd |
| `det_score` | float, nullable | from the ExApp; NULL for backfilled rows |
| `embedding` | blob (`Types::BLOB`), notnull | 512 × float32 little-endian = 2,048 bytes |
| `model` | string(32), notnull, default `'buffalo_l'` | for a future model change |

Indexes: unique (`file_id`, `face_index`) `facewright_faces_file_idx`.

`facewright_detection_faces` — links a per-user rfd row to its physical face:

| column | type | notes |
|---|---|---|
| `detection_id` | bigint, notnull, PK `facewright_detfaces_det` | = `rfd.id` |
| `face_id` | bigint, notnull | = `facewright_faces.id` |
| `user_id` | string(64), notnull | denormalized copy of `rfd.user_id` |

Indexes: (`face_id`) `facewright_detfaces_face`, (`user_id`) `facewright_detfaces_user`.

No foreign keys (Nextcloud's migrations avoid them; consistency is by code + the reconcile
command in 1.6).

### 1.2 `FaceVectorCodec`

New `lib/Service/FaceVectorCodec.php` with two static functions and nothing else:
`encode(list<float> $v): string` = `pack('g*', ...$v)` (little-endian float32; PHP ≥ 7.0.15),
`decode(string $blob): list<float>` = `array_values(unpack('g*', $blob))`. Constant
`DIMENSIONS = 512`, `BYTES = 2048`. `decode()` throws `\UnexpectedValueException` when
`strlen($blob) !== BYTES`. Move `ClusteringService::EXPECTED_DIMENSIONS` here (keep a
deprecated alias for one stage if it is referenced from more than two places).

Round-trip check to put in the docblock: `1 - dot(decode(encode(v)), v)` for an L2-normalized `v`
is ≤ 1e-7 — float32 is what InsightFace produced, so nothing is lost.

### 1.3 Ownership predicate becomes a join (with a transitional OR)

`DetectionService::ownRowsExpr(IQueryBuilder $qb): string` keeps its signature and all 14 call
sites, and returns, during Stage 1:

```
( id IN (SELECT detection_id FROM *PREFIX*facewright_detection_faces) OR char_length(face_vector) > 3500 )
```

built as `$qb->expr()->orX($qb->expr()->in('id', $qb->createFunction($sub->getSQL())), <old expr>)`
where `$sub = $this->db->getQueryBuilder()->select('detection_id')->from('facewright_detection_faces')`.
`ownRowsExpr` is static today and has no `$db`; give it a private static builder that constructs
the subquery through `$qb->getConnection()->getQueryBuilder()` (IQueryBuilder exposes
`getConnection()`), so the signature stays.

Why the OR: it removes the window between deploying this code and finishing the backfill (1.6),
during which a join-only predicate would classify every existing row as "not ours" — and
`AccessSyncService::copyExisting()` would then copy markers without rows on a share, silently
and permanently. Stage 2 removes the `OR` term (2.6). Update the `VECTOR_LENGTH_FLOOR` docblock
to say it is now the transitional half of the predicate and is deleted in Stage 2.

Column references: the existing call sites all query `recognize_face_detections` unaliased, so
`'id'` resolves. `PeopleService::facesForFile()` (0.3) may use an alias — pass it through if so.

### 1.4 `DetectionService::detect()` writes both

In the per-face loop, before the per-user inserts:

- Upsert `facewright_faces` for `(fileId, faceIndex)` — `faceIndex` is the position in the
  ExApp's `faces` array. Use `IDBConnection::setValues('facewright_faces', ['file_id' => …,
  'face_index' => …], ['x' => …, 'y' => …, 'width' => …, 'height' => …, 'det_score' => …,
  'embedding' => FaceVectorCodec::encode($face['embedding']), 'model' => 'buffalo_l'])`
  (CLAUDE.md: `setValues` returns 1 on insert, 0 on update; `insertOrUpdate` does not exist).
  Then read back the `id` (`SELECT id WHERE file_id = ? AND face_index = ?`) — one query per
  face, acceptable; the row-at-a-time insert loop is already Open item 7.
- Per target user: insert the rfd row exactly as today (including `face_vector` JSON), take
  `getLastInsertId()`, insert `facewright_detection_faces (detection_id, face_id, user_id)`.
- Order stays rows → links → marker. A crash between rfd insert and link insert leaves a row
  that the OR-predicate still recognizes as ours and that 1.6's reconcile links later.

`det_score`: the ExApp already returns it; nothing to change in `ex_app/`.

### 1.5 `AccessSyncService::copyToUsers()` copies the link

`copyToUsers()` selects `x, y, width, height, face_vector` today; add `id` to the select, and for
each copied row look up `face_id` from `facewright_detection_faces` by the donor's `id` (one
query per donor row set: `SELECT detection_id, face_id WHERE detection_id IN (...)`). After each
target insert, insert the link with the same `face_id`. A donor row without a link (not yet
backfilled) is copied without one; the reconcile in 1.6 fixes both sides later. Everything else
in the service is untouched — it already goes through `ownRowsExpr()`.

`pruneExceptUsers()` / `forgetFile()`: after deleting rfd rows, delete the links whose
`detection_id` is gone: `DELETE FROM facewright_detection_faces WHERE detection_id NOT IN
(SELECT id FROM rfd WHERE file_id = ?)` is wrong (NOT IN with the same file filter); do it
explicitly — select the rfd ids to be deleted first (the plan's "dump the id list" rule), delete
links by that list, then delete the rows. `facewright_faces` rows for a file nobody has anymore:
delete `WHERE file_id = ?` when `keepUserIds === []` (the `forgetFile` case) only.

### 1.6 Backfill command `occ facewright:migrate-faces`

New `lib/Command/MigrateFaces.php` + `lib/Service/FaceMigrationService.php`. Resumable, chunked
by `file_id`, cursor stored in appconfig `facewright_bridge/migrate_faces_after_file_id`
(deleted on completion). Options: `--dry-run`, `--chunk=<files>` (default 500), `--reset-cursor`.

Per chunk:

1. `SELECT DISTINCT file_id FROM rfd WHERE file_id > :cursor AND <legacy-proxy: char_length(face_vector) > 3500> ORDER BY file_id LIMIT :chunk`. This is the **last** use of the length proxy on purpose — the rows to migrate are exactly the ones it identifies.
2. For each file: `SELECT id, user_id, x, y, width, height, face_vector FROM rfd WHERE file_id = ? AND char_length(face_vector) > 3500 ORDER BY id`.
   Group rows by the geometry key `sprintf('%.6f|%.6f|%.6f|%.6f', x, y, width, height)` — fan-out copies were inserted from the same stored floats, so identical physical faces have identical keys across users. Each group is one physical face; `face_index` = rank of the group by its smallest `id`.
   For each group: decode the first row's JSON; if `count !== 512` log the ids and skip the whole group (it is the degenerate all-zero / foreign row CLAUDE.md mentions); else `setValues('facewright_faces', ['file_id','face_index'], [...geometry, 'det_score' => null, 'embedding' => encode(...)])`, read back `id`, insert one link per row in the group (skip rows that already have a link — `SELECT detection_id ... WHERE detection_id IN (...)` first, so re-runs are idempotent).
3. Advance the cursor to the chunk's last `file_id`; print `files, faces, links` for the chunk.

Wrap each file in a transaction (`$db->beginTransaction()` / `commit()`), not the chunk.

Completion check (printed at the end, and by `--dry-run` as the projected numbers):
`COUNT(*) FROM rfd WHERE char_length(face_vector) > 3500` must equal
`COUNT(*) FROM facewright_detection_faces` plus the number of skipped degenerate rows the log
lists. If it does not, stop; do not proceed to 1.7.

### 1.7 `ClusteringService` reads from `facewright_faces`

- `selectWantedIds()`: replace the unfiltered `SELECT id, cluster_id FROM rfd WHERE user_id = ?`
  with `SELECT d.id, d.cluster_id, l.face_id FROM rfd d INNER JOIN facewright_detection_faces l
  ON l.detection_id = d.id WHERE d.user_id = ? ORDER BY d.id`. Return `array<int detectionId,
  int faceId>` (ascending by detection id) instead of a list.
- `loadDetections()`: pass 2 fetches `SELECT id, embedding FROM facewright_faces WHERE id IN (...)`
  in `VECTOR_FETCH_BATCH` batches of **face ids** (deduplicated — several users' rows may share
  a face, but within one user they never do), decodes with `FaceVectorCodec::decode()`, and
  builds `$detections[$detectionId] = ['file_id' => …, 'vector' => …, 'cluster_id' => …]`. A
  face with a wrong blob length counts as `skippedForeign`. `file_id` comes from pass 1 (add it
  to the select).
- Remove the `json_decode` path and `EXPECTED_DIMENSIONS`. Update the class docblock and the
  `loadDetections()` docblock (the "off-page BLOB" reasoning is still right; it now applies to
  a 2 KB in-page blob, so pass 2 is cheaper again — say so briefly, without the history).
- `countUnclusteredByUser()`: replace `ownRowsExpr` with the same INNER JOIN.
- `VECTOR_FETCH_BATCH` docblock: batches are now ~1 MB, not 5 MB; leave the value.

**Verify:** `occ facewright:cluster --reset <user>` on the compose stack after 1.6 produces the
same clusters (same member sets) as before Stage 1 — export `SELECT cluster_id, GROUP_CONCAT(id
ORDER BY id) FROM rfd WHERE user_id = ? GROUP BY cluster_id` before and after; the multisets of
member lists must be identical up to cluster-id renumbering.

### 1.8 Remaining call sites

- `ResetService::findOwnRows()` / `resetFacewrightData()`: also delete `facewright_detection_faces`
  and `facewright_faces` (by the dumped id lists, the same way the other two tables are done).
- `AccessSyncService::usersWithRowsFor()` ("ours or legacy") — leave; it is used to find *any*
  row holder for pruning decisions.
- `Command/ClusterFaces.php::printClusterSizes()`, `Command/ResetFacewrightData.php::countOwnRows()`:
  they use `ownRowsExpr` and keep working; no change.
- `VectorCompactionService` / `occ facewright:compact-vectors`: unchanged in Stage 1 (rfd still
  carries JSON); deleted in Stage 2.

### 1.9 Docs and version

- Bump `<version>` (migration + two commands).
- CLAUDE.md: "Schema mapping" gains the two tables and the sentence "the embedding of record is
  `facewright_faces.embedding`; `rfd.face_vector` is written for Recognize's DAV code only and
  disappears in Stage 2"; "Telling our rows from Recognize's" is rewritten around the join, with
  the OR term described as transitional; Open item 8 is closed (point at this stage); Open item 7
  gains the per-face read-back query. Add `facewright:migrate-faces` to the `occ` list.
- README "Current limitations": no change.

**Stage 1 done when:** 1.6's count pair reconciles on the compose stack; 1.7's cluster
comparison is identical; a new upload produces a `facewright_faces` row, an rfd row and a link
for every user with access (`occ facewright:detect`, then SQL); a share to a second user
(`occ facewright:backfill-access`) copies links.

---

## Stage 2 — replace Recognize with a `recognize`-contract shim

**Goal.** Recognize is uninstalled. An app with id `recognize` that we own satisfies Memories and
Photos: version gate, `faces.enabled`, `ApiKeyManager`, the DAV tree with its properties, and the
two tables. Every DAV write becomes a `PeopleService` call. `rfd.face_vector` and `threshold` are
dropped; the ownership predicate becomes the join alone.

**Precondition:** Stage 1 complete and its count pair reconciled on the target instance.

### 2.1 Layout

`shim/recognize/` in this repo, deployed to `custom_apps/recognize` (the directory name must
equal the app id). `docker-compose.yml`: add the bind mount
`./shim/recognize:/var/www/html/custom_apps/recognize`; `dev-setup.sh`: stop `app:install
recognize`, add `app:enable recognize` after `facewright_bridge`. Keep `app:install memories`.

```
shim/recognize/
  appinfo/info.xml
  lib/AppInfo/Application.php
  lib/Public/ApiKeyManager.php
  lib/Migration/Version000001000Date2026MMDD000000.php
  lib/Dav/RootCollection.php
  lib/Dav/RecognizeHome.php
  lib/Dav/Faces/FacesHome.php
  lib/Dav/Faces/FaceRoot.php
  lib/Dav/Faces/FacePhoto.php
  lib/Dav/Faces/UnassignedFacesHome.php
  lib/Dav/Faces/UnassignedFacePhoto.php
  lib/Dav/Faces/PropFindPlugin.php
  README.md
```

`info.xml`: `<id>recognize</id>`, `<name>Recognize (Facewright shim)</name>`,
`<namespace>Recognize</namespace>`, `<version>99.0.0</version>` (≥ 3.8.0 for Memories; above
any App Store version so the updater never offers real Recognize on top of it — say so in a
comment), `<licence>agpl</licence>`, `<dependencies><nextcloud min-version="33" max-version="35"/>`,
`<sabre><collections><collection>OCA\Recognize\Dav\RootCollection</collection></collections></sabre>`
plus `<types><filesystem/><dav/></types>` (copy the element order from
`/mnt/data/nextcloud/nc_apps/recognize/appinfo/info.xml:81-84,131-135` — `info.xsd` is a
sequence, so a misplaced element makes the app fail to parse, as CLAUDE.md notes for `<settings>`).

The shim's classes are derived from Recognize's (AGPL-3.0, same as this repo): keep the
"Copyright (c) 2022 The Recognize contributors" header on every derived file and add a line
"Derived from nextcloud/recognize; mapper calls replaced with OCA\FacewrightBridge\Service\PeopleService".

### 2.2 `Application.php`

Copy Recognize's `boot()` (`AppInfo/Application.php:61-70`): listen to
`OCA\DAV\Events\SabrePluginAddEvent` and `addPlugin(PropFindPlugin)`. In `register()`, nothing but
the `principalBackend` service alias Recognize registers (`:55`,
`$context->registerServiceAlias('principalBackend', \OCA\DAV\Connector\Sabre\Principal::class)`) —
`RootCollection`'s constructor asks for it by that name. At the top of `boot()`, if
`IAppManager::isEnabledForUser('facewright_bridge')` is false, log an error once and return; the
DAV tree then 404s instead of fataling on a missing class.

### 2.3 `ApiKeyManager`

Copy `Public/ApiKeyManager.php` verbatim (FQCN `OCA\Recognize\Public\ApiKeyManager`, same
constructor `ICrypto`, `ITimeFactory`, same JSON payload). Memories and Photos inject it by class
name; anything else breaks the People page silently (DI passes `null`, the frontend sends no key,
`beforeMethod` refuses).

### 2.4 Migration

`changeSchema()`:
- If `recognize_face_detections` is absent: create it with `id`, `user_id`, `file_id`, `x`, `y`,
  `height`, `width`, `cluster_id` (same types, nullability, lengths and index names as Recognize's
  `Version002002000Date20220614094721.php:41-76`, minus `face_vector` and `threshold`). Keep the
  physical column order `x, y, height, width` — CLAUDE.md's positional-copy warning stays true.
- If present: `dropColumn('face_vector')` and `dropColumn('threshold')` if they exist.
  This is one `ALTER TABLE` and rebuilds a multi-GB table on MariaDB; say so in the docblock and
  in the cutover notes (2.9). It runs after the legacy purge (2.9), so nothing legacy is lost.
- If `recognize_face_clusters` is absent: create it as `:80-98`.
- Do **not** create Recognize's other tables (`recognize_fs_*`, `recognize_queue_*`).

### 2.5 The DAV tree

Each file is Recognize's with the mapper replaced. `PeopleService` is obtained by constructor
injection in `RootCollection` (Nextcloud's DAV collections are built by the container), and handed
down explicitly to every node like Recognize hands its mappers down. Mapping, per class:

| Recognize call | Replacement |
|---|---|
| `FaceClusterMapper::findByUserId()` (`FacesHome::getChildren`) | `PeopleService::listClusters()` |
| `findByUserAndTitle()` / `find()` (`FacesHome::getChild`) | `resolveCluster()` + `getCluster()` |
| `FacesHome::createDirectory()` | `createCluster()` |
| `FaceRoot::setName()` | `renameCluster()`; on duplicate throw `Forbidden('Not allowed to create duplicate names')` — Memories keys its "merge instead?" prompt on that text; keep it exact |
| `FaceRoot::delete()` | `deleteCluster()` |
| `FaceRoot::getChildren()` / `getChild()` | `listFaces()`; child name is `"{$detectionId}-{$basename}"` (`FacePhoto.php:41-45`); resolve the file via `IRootFolder::getUserFolder($uid)->getFirstNodeById($fileId)` as Recognize does |
| `FaceRoot::moveInto()` | `moveFace()` — source node must be a `FacePhoto` or `UnassignedFacePhoto` |
| `FacePhoto::delete()` | `detachFace()` — no centroid, no `threshold` (Finding 2 fixed) |
| `FaceRoot::getPreviewImage()` | `PeopleService::previewFace($userId, $clusterId)` — new method reproducing `FaceDetectionMapper::findDetectionForPreviewImageByClusterId()` (`:303-318`: box fully inside the image with a half-width margin, largest first) and returning the `{fileid, detection}` JSON with `detection` in `toArray()` shape (see below) |
| `UnassignedFacesHome::getChildren()` | `listUnassignedFaces()` |
| `PropFindPlugin` `nc:face-detections` (both on `FacePhoto` and on plain `File`) | `facesForFile($userId, $fileId)` |
| `PropFindPlugin::beforeMove()` | keep; the duplicate check calls `resolveCluster()` and catches its exception |
| `PropFindPlugin::beforeMethod()` | keep verbatim (`require_api_key` read from app `recognize`) |

`toArray()` shape for detections, as both UIs receive it today (`Db/FaceDetection.php:65-74`,
`FaceDetectionWithTitle::$fields`): keys `id, userId, fileId, x, y, height, width, clusterId,
threshold, title` — `faceVector` is omitted by Recognize itself. Emit `threshold` as `null`
(the column is gone). Give `PeopleService` a `toDavArray(array $row): array` helper that produces
exactly this key set; the DAV classes use it and nothing else does.

The `-1` semantics from Stage 0 already match the tree: `UnassignedFacesHome` lists `-1`,
`deleteCluster()` writes `-1`, `detachFace()` writes `-1`, `moveFace()` accepts `-1`.

### 2.6 `facewright_bridge` cleanup (same commit series, after the shim works on the compose stack)

- `DetectionService`: stop writing `face_vector`; delete `VECTOR_PRECISION`, `encodeVector()`,
  `VECTOR_LENGTH_FLOOR`; `ownRowsExpr()` returns the `IN (SELECT detection_id …)` term only.
  Rename it `ownRowsExpr` → keep the name (14 call sites; a rename buys nothing).
- Delete `VectorCompactionService`, `Command/CompactVectors.php`, their `info.xml` entry.
- `ClusteringService`: `UNCLUSTERABLE` docblock loses the Recognize `MaintenanceJob` reasoning;
  keep the sentinel and the "not re-attempted as seeds" behaviour, which is our own.
- `PeopleSettingsController::setEnabled` / `AdminSettings`: unchanged mechanics (the key
  `recognize/faces.enabled` now belongs to the shim); fix the comments.
- `AccessSyncService::copyToUsers()`: drop `face_vector` from the select.
- `FaceMigrationService` (1.6) and `occ facewright:migrate-faces`: keep for one release with a
  note, then delete — instances that skipped Stage 1 cannot exist (Stage 2 requires it), but the
  command is the documented recovery path if a link is ever missing.
- `grep -rn "face_vector\|VECTOR_LENGTH_FLOOR\|json_decode" lib/` must find nothing related to
  vectors afterwards.

### 2.7 `dev-setup.sh` and a fresh-install path

On a fresh compose stack (no Recognize ever installed): `app:enable facewright_bridge`,
`app:enable recognize` (the shim), `occ upgrade` is not needed for a first enable (migrations run
on enable). The shim's migration creates both tables. Verify the People page renders with zero
people, "create person" works, and a detection populates it.

### 2.8 Verification matrix (compose stack, real Recognize still installed → replaced)

1. Snapshot: `SELECT COUNT(*) FROM rfd`, `… WHERE char_length(face_vector) > 3500`,
   `COUNT(*) FROM facewright_detection_faces`, `COUNT(*) FROM rfc`, cluster member lists as in 1.7.
2. Run the cutover (2.9) on the stack.
3. Memories: People page lists the same people; rename; rename onto an existing name → Memories
   shows its merge prompt (needs the exact `Forbidden` text); delete a person → faces under
   Unassigned; move an unassigned face to a person; remove a face from a person → it appears
   under Unassigned and **stays there** after `occ facewright:cluster <user>` (Finding 2);
   face rectangles show on a photo (`nc:face-detections` on `File`).
4. Photos app: People view renders; rename works.
5. `curl` the DAV tree without `X-Recognize-Api-Key` → 403 (`require_api_key` default is still
   `'true'` in the shim's `beforeMethod`).
6. `oc_jobs` has no `OCA\Recognize\%` rows; `occ background-job:list` (or SQL) confirms.
7. Table size: `information_schema` `data_length` of `rfd` before/after the column drop (an
   estimate, per CLAUDE.md, but the order of magnitude is the point).

### 2.9 Cutover runbook (per instance; written into `docs/independence.md` §Stage 2 once rehearsed)

1. `occ facewright:migrate-faces` complete and reconciled (Stage 1).
2. Admin page → "Pause processing". Confirm no `DetectQueuedJob`/`ClusterScanJob` is mid-run
   (`oc_jobs` `reserved_at` = 0 for our classes).
3. **Legacy purge, rehearsed in a rolled-back transaction first**: dump the id list of
   `rfd WHERE id NOT IN (SELECT detection_id FROM facewright_detection_faces)` to a file with
   its count; delete by that list in chunks of 1,000; then delete `rfc` rows with no remaining
   members (`PeopleService::deleteEmptyClusters()` per user, or the equivalent SQL); print
   before/after counts that must reconcile (`before − listed = after`). Expected on the reference
   instance: the ~39 legacy rows and the one all-zero row.
4. `occ app:disable recognize`; `occ app:remove recognize` (tables and `oc_appconfig` rows for
   app `recognize` survive — `faces.enabled` is among them and the shim inherits it).
5. `DELETE FROM oc_jobs WHERE class LIKE 'OCA\\\\Recognize\\\\%'` (count first).
6. Copy `shim/recognize` to `custom_apps/recognize`; `occ app:enable recognize`; `occ upgrade`
   (runs the column drop — minutes on a large table; the instance is in maintenance for it).
7. Restart the web container (APCu, and `routes`/DAV registration).
8. Deploy the cleaned `facewright_bridge` (2.6) with its version bump; `occ upgrade`.
9. Unpause. Run 2.8's checks 3–6.
10. `.noimage` markers: leave them. Facewright ignores them and nothing else reads them now; a
    user can delete them from the web UI at leisure. Do not `occ files:scan` for it.

### 2.10 Docs

- CLAUDE.md: "Why Recognize cannot simply be uninstalled" becomes "The `recognize` contract we
  provide" (what the shim implements and why the id/namespace must be `recognize`); delete
  "Coexisting with Recognize" entirely; "Telling our rows from Recognize's" becomes one sentence
  about the join; Deployment gains the shim (copy to `custom_apps/recognize`, version pinned at
  99.0.0 to block store updates); Open item 4 loses the remove-face bug; the licensing note gains
  the "derived from Recognize, AGPL" sentence.
- README: "What this is" no longer says Recognize stays installed; Quickstart gains the shim;
  "Current limitations" drops the schema-drift bullet.
- `docs/independence.md`: mark Stages 0–2 done with commit hashes; keep Stage 3 and the "What
  stays coupled" section.

**Stage 2 done when:** 2.8 passes on the compose stack twice — once upgrading from a stack that
had real Recognize, once on a fresh stack that never did.

---

## Stage 3 — not part of this plan

Stage 3(a) is a proposal to the Memories project, not code here; 3(b) is only built if (a) is
refused. Neither should be started from this plan. If a session is asked to prepare (a): the
material is `independence.md` M3/M7, the `facerecognition` backend as the OCS precedent, and the
Stage 0 API as the write surface to offer.

---

## Deviations from `independence.md`

1. **`-1` is redefined in Stage 0, not Stage 2.** The doc treats Finding 1 as unfixable before
   the shim; reading the Memories/Recognize code again shows that `-1` already *means*
   "unassigned" to every consumer except our clustering, so aligning our reading fixes it with
   no schema change.
2. **Stage 1 has two tables, not three, and no `state`/`rejected_cluster_id` columns.**
   Assignment state stays in `rfd.cluster_id` (`NULL` / `-1` / `-2` / id) because both UIs read
   it there anyway, and a second copy would have to be kept in step. "Detached" is `-1`
   (unassigned until a human moves it), which is what Immich does and what Memories displays;
   a `rejected_cluster_id` that would let clustering re-place a detached face elsewhere is not
   needed for that behaviour.
3. **`rfd.face_vector` is kept through Stage 1 and dropped by the shim's migration in Stage 2**,
   rather than shrunk to `'[]'` in Stage 1. Recognize's `FacePhoto::delete()` decodes it and
   computes a centroid over it; an empty array there would turn "remove face" into a 500 in
   Memories for the whole interim. The drop is one `ALTER TABLE` instead of a gigabyte-scale
   resumable `UPDATE`.
4. **The ownership predicate is `join OR length` during Stage 1**, so there is no window in
   which a share fan-out could copy markers without rows.
5. **No `set cover` operation.** Covers live in `memories_covers`, which is Memories' own table
   and UI; the DAV contract has no cover operation to reproduce.

## Deviations found during implementation

1. **Step 0.4 as written creates a circular constructor dependency.** `PeopleService::
   mergeClusters()` delegating to `ClusterMergeService`, while `ClusterMergeService::merge()`
   resolves ids via `PeopleService::resolveCluster()`, means each service's constructor needs an
   instance of the other - Nextcloud's DI container cannot build either one. Resolved by moving
   the merge logic (resolve both sides, move detections, delete the losing cluster) directly into
   `PeopleService::mergeClusters()`, the same place `resolveCluster()` already lives, and deleting
   `ClusterMergeService` outright rather than keeping it as a pass-through wrapper with no
   distinct responsibility. `Command/MergeClusters.php` and `PeopleSettingsController::
   mergeClusters` both call `PeopleService` directly now; the latter was already moving there per
   step 0.6's own instruction.

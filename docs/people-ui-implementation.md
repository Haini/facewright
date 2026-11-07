# Implementation plan: the People curation UI (`docs/people-ui.md`)

An executable plan for the requirements in `docs/people-ui.md`. Written for a model session that
has not seen this repository before: every step names the files, the functions, the SQL shape and
the verification, and says what must *not* be touched. Where this plan and `people-ui.md` differ,
this plan wins; differences are listed under "Deviations from people-ui.md" at the end.

## How to work this plan

1. **Read `CLAUDE.md` first, in full**, then `docs/people-ui.md`. The `cluster_id` invariant
   (`NULL` / `-1` / `-2` / id) and "Telling our rows from Recognize's" are exactly what steps 1–3
   touch.
2. **Work on the compose stack, never on the production instance.** `docker compose up -d` and
   `bash dev-setup.sh` give a throwaway Nextcloud with Recognize, Memories and the ExApp. The
   production instance is
   read-only reference material. The single exception is step 6's timing measurement, which is
   read-only SQL plus a read-only `occ` command and must be explicitly approved by the user first.
3. **One commit per numbered step**, in the style of `git log` (imperative subject, a body that
   says why). Steps 1 and 2 are bug-relevant on their own and must land before any UI step.
4. **Deploy mechanics that bite** (from CLAUDE.md):
   - A migration, a new `occ` command, a new background job or a new `<navigations>` entry needs
     `<version>` bumped in `facewright_bridge/appinfo/info.xml` and `occ upgrade`.
   - Any change to `js/*.js` or `css/*.css` needs a version bump too (the `?v=` cache-buster is
     `md5($appVersion)`). Bump the patch level per step that needs it (0.7.0 → 0.7.1 → …); step
     15 sets 0.8.0.
   - A new `appinfo/routes.php` entry needs the web container restarted
     (`docker compose restart nextcloud`), or it 404s with everything looking right.
5. **Do not touch `ex_app/`**, `Dockerfile`, `Makefile`, `start.sh`, `requirements.txt`. Do not
   change `DetectionService`, `AccessSyncService`, `PruneService` or any listener. Do not write
   Recognize's config or job table.
6. **No build step, no npm, no test framework.** Plain PHP template + vanilla JS + one CSS file,
   like `js/personal.js`. Verification is `occ`, SQL counts, `curl` and the browser on the compose
   stack.
7. **Stop and report rather than improvise** when: a count pair does not reconcile; an `OCP` API
   named here does not exist on the stack's Nextcloud version (check `lib/public/` in the
   container before substituting); `app-info.xsd` rejects the `info.xml` change; or step 6's
   measurement crosses the thresholds it names.

Conventions: `rfd` = `oc_recognize_face_detections`, `rfc` = `oc_recognize_face_clusters`,
`ff` = `facewright_faces`, `fdf` = `facewright_detection_faces`. Paths are relative to
`facewright_bridge/` unless they start with `docs/`. Every SQL statement here is built with
`IQueryBuilder`; raw SQL is shown only for readability.

**Rules that apply to every new `PeopleService` method:**
- `$userId` comes first and appears in the `WHERE` of every statement, including the `UPDATE`
  of a bulk operation. An id that isn't the user's is silently not matched; the method reports
  the affected count, and the controller returns it.
- Id lists are passed in `array_chunk(..., 1000)` slices with `PARAM_INT_ARRAY`.
- Multi-statement writes run inside `$this->db->beginTransaction()` / `commit()`, with
  `rollBack()` in a `catch (\Throwable $e)` that rethrows.
- Never select `face_vector`. Embeddings come only from `ff.embedding`, and only in step 6.

---

## 1 — Race guard in `ClusteringService::persistAssignments()`

**Why.** `persistAssignments()` (`lib/Service/ClusteringService.php`, end of file) does
`UPDATE rfd SET cluster_id = :c WHERE id = :id` for each changed id, with no check on the value
the row has *now*. A `ClusterScanJob` chunk loads the pool, spends seconds matching, then writes.
Anything a human did to those rows in between is overwritten: a face moved to a person ends up in
another one, and a face removed (`-1`) gets reassigned. A person deleted or merged in between can
leave rows pointing at a `cluster_id` with no `rfc` row, which Memories can't see and our list
can't reach. This exists today (Memories writes, `occ facewright:people`); the UI makes it common.

**Change.**
- Every id in `changedIds` was `NULL` or `-2` in the database when `loadDetections()` read it:
  `assignClusters()` only changes ids whose pool `cluster_id` is `null`, and the pool normalizes
  exactly `NULL` and `-2` to `null`. So the guard is
  `... WHERE id = :id AND (cluster_id IS NULL OR cluster_id = -2)`.
- Before the loop, select which target cluster ids still exist:
  `SELECT id FROM rfc WHERE user_id = :u AND id IN (:targets)`. Skip any assignment whose target
  is missing. Add `string $userId` to `persistAssignments()`'s parameters (both callers have it).
- Clusters this call created that end up with zero persisted rows must not be left behind
  (they'd show up as empty "Unnamed" people). Have `assignClusters()` also return
  `createdClusterIds`. After persisting, delete those that got no row:
  `DELETE FROM rfc WHERE id IN (:created) AND user_id = :u AND NOT EXISTS (SELECT 1 FROM rfd WHERE cluster_id = rfc.id)`.
  Reuse the subquery shape in `PeopleService::deleteEmptyClusters()`.
- Return the number of rows actually written. The callers' stats stay as they are (they describe
  what matching decided). Log at info level when written < requested:
  `"facewright: {n} cluster assignment(s) for {user} skipped, rows changed during the pass"`.
- Docblock on `persistAssignments()`: one paragraph saying why the guard exists. No history.

**Do not** wrap the matching in a transaction or lock rows. The pass takes seconds to minutes,
and holding locks that long would block Memories' writes.

**Verify.**
1. Compose stack, user with ≥ 50 pending faces. In one shell, run
   `occ facewright:cluster <user>`. While it runs (add a temporary `sleep(10)` before
   `persistAssignments()` locally, **not committed**), set one pending row to `-1` and delete one
   existing cluster via `occ facewright:people <user>` / SQL.
2. After: the `-1` row is still `-1`. `SELECT COUNT(*) FROM rfd d LEFT JOIN rfc c ON c.id = d.cluster_id WHERE d.user_id = :u AND d.cluster_id > 0 AND c.id IS NULL`
   is `0`. No `rfc` row for the user has zero members unless it has a title.
3. Without the interference, the same run produces the same member sets as before the change.
   Export `SELECT cluster_id, GROUP_CONCAT(id ORDER BY id) ... GROUP BY cluster_id` before and
   after a `--reset` run on a copy; the multisets must be identical up to renumbering.

## 2 — `IGNORED = -3` ("not a face")

**Why.** Requirement F5. The semantics are in `people-ui.md`.

**`ClusteringService`.**
- Add `public const IGNORED = -3;` next to `UNASSIGNED`, with a docblock in the same style:
  "not a face, or not someone the user wants to see. Set only by a human. Clustering never seeds
  from it, matches against it, or changes it, and `resetUser()` keeps it. Invisible to Memories
  (inner-joins `rfc`, unassigned view reads `-1` only) and to Recognize (its predicates are
  `IS NULL`, `= -1`, `= <id>`; its own `resetClusters()` would wipe it, like everything else)."
- `selectWantedIds()`: skip `IGNORED` exactly where `UNASSIGNED` is skipped. **This is the
  load-bearing line**: without it a `-3` is loaded as a real cluster id, offered as a join
  target, and new faces get written into "cluster -3".
- `resetUser()`: change the `UPDATE` to
  `... SET cluster_id = NULL WHERE user_id = :u AND (cluster_id IS NULL OR cluster_id <> -3)`
  (the `IS NULL` branch is harmless, it keeps the statement equivalent to today's for everything
  else). Update its docblock: reset reopens `-1` and `-2` but keeps `-3`.
- `countUnclusteredByUser()`, `clearUnclusterableMarks()`, `markUnclusterable()`: no change
  (they select `IS NULL` / `-2` only). Check this by reading them.

**`PeopleService`.**
- `listIgnoredFaces(string $userId, int $limit, int $offset)`, a thin wrapper over
  `listFacesByClusterId()` (which gains paging in step 3; do this step first with the current
  signature and adjust in step 3).
- `ignoreFaces(string $userId, array $detectionIds): int` sets `cluster_id = -3` where `id IN`,
  `user_id = :u` and `(cluster_id IS NULL OR cluster_id <> -3)`. Then it deletes the
  now-empty *untitled* source clusters (see "empty-source cleanup" in step 3) in the same
  transaction.
- `unignoreFaces(string $userId, array $detectionIds): int` sets `NULL` where
  `cluster_id = -3`. Back to pending, so clustering may place it. That is intended: "I was wrong,
  this is a face".
- `moveFace()` already accepts any source state, including `-3`. No change.

**`occ facewright:people`** (`lib/Command/People.php`): add `--ignored`, mirroring
`--unassigned`.

**Other readers of negative `cluster_id`**: `Command/ClusterFaces.php::printClusterSizes()`
prints every non-null id, so `-3` appears as "cluster -3". Leave it (it is a diagnostic).
`ResetService` and `ResetFacewrightData` select `cluster_id > 0`, so they're unaffected.
`AccessSyncService::copyToUsers()` omits `cluster_id`, so recipients start at `NULL`. That is
correct: an ignore is per user.

**Verify.**
- Ignore 3 faces of a person directly in SQL (`UPDATE rfd SET cluster_id = -3 WHERE id IN (...)`;
  `ignoreFaces()` gets exercised again in step 3). `occ facewright:cluster <user>`: they stay
  `-3`, and no face joins `cluster_id = -3` (`SELECT COUNT(*) FROM rfd WHERE cluster_id = -3`
  equals exactly the number you ignored).
- `occ facewright:cluster <user> --reset`: same count of `-3` afterwards; no other negative value
  remains except `-2` from the new pass.
- Memories (browser): the faces are gone from the person and absent from "Unassigned".

## 3 — `PeopleService`: paging, search, covers, bulk operations

All in `lib/Service/PeopleService.php`. Keep existing public signatures working: new parameters
are optional with defaults that reproduce today's behaviour, because `Command/People.php` and
`PeopleSettingsController` call them.

**`listClusters(string $userId, ?int $limit = null, int $offset = 0, int $minCount = 0, string $search = '')`**
- `minCount`: `HAVING COUNT(d.id) >= :min OR c.title <> ''`. Named people are always listed, as
  is a named person with zero faces.
- `search`: `AND c.title LIKE :q`, where `:q` is `'%' . $this->db->escapeLikeParameter($search) . '%'`
  (use `$qb->expr()->iLike` if available, else `like`).
- Ordering unchanged. `setFirstResult($offset)`, plus `setMaxResults($limit)` when not null.
- Each item gains `cover` (a detection id, or `null`) and the result keeps
  `{id, title, count}`. Covers are filled by a private `coversFor(string $userId, array $clusterIds): array`:
  one query per cluster on the page (≤ page size, default 60):
  `SELECT d.id FROM rfd d JOIN fdf l ON l.detection_id = d.id JOIN ff f ON f.id = l.face_id WHERE d.user_id = :u AND d.cluster_id = :c ORDER BY f.det_score DESC, (f.width * f.height) DESC, d.id ASC LIMIT 1`.
  `det_score` is `NULL` for backfilled rows. Check how MariaDB sorts `NULL` under `DESC` on the
  stack (it sorts last). If not, add `f.det_score IS NULL` as the first sort key. If the query
  is slow on a 30k-member cluster, add `(cluster_id)` to the plan check (`EXPLAIN`). Recognize
  ships an index on `rfd.cluster_id`; confirm it exists with `SHOW INDEX`, don't create one on
  Recognize's table.

**`countClusters(string $userId, int $minCount = 0, string $search = '')`**: the same
predicate as `COUNT(*)` over the grouped subquery, for the list header ("1,234 people").

**Face listings**: `listFacesByClusterId()` gains `?int $limit = null, int $offset = 0`;
`listFaces()`, `listUnassignedFaces()`, `listIgnoredFaces()` pass them through. Add
`countFacesByClusterId(string $userId, int $clusterId): int`. Order stays `id ASC`.

**Bulk methods** (each returns an int count; see the general rules above):
- `moveFaces(string $userId, array $detectionIds, int $targetClusterId): int`: resolve the target
  via `getCluster()` (throws if not the user's), then
  `UPDATE rfd SET cluster_id = :t WHERE user_id = :u AND id IN (...)`, then empty-source
  cleanup. Re-implement `moveFace()` as `moveFaces($u, [$id], $t)` and keep its "does not exist"
  exception when the count is 0.
- `moveFacesToNewPerson(string $userId, array $detectionIds, string $title): array{clusterId: int, moved: int}`:
  `createCluster()` + `moveFaces()` in one transaction.
- `detachFaces(string $userId, array $detectionIds): int`: `-1`, keeping `detachFace()`'s
  `cluster_id > 0` predicate, then empty-source cleanup. `detachFace()` delegates and keeps its
  exception on 0.
- `ignoreFaces` / `unignoreFaces`: from step 2.
- `mergeMany(string $userId, int $keepId, array $mergeIds, bool $dryRun = false): array{keepId: int, merged: list<int>, moved: int}`:
  resolve every id via `getCluster()` first (all-or-nothing: any foreign/missing id throws before
  writing). Reject `$keepId` in `$mergeIds`. Then one transaction:
  `UPDATE rfd SET cluster_id = :keep WHERE user_id = :u AND cluster_id IN (:merge)`,
  `DELETE FROM rfc WHERE user_id = :u AND id IN (:merge)`, plus dismissal/centroid cleanup (step
  6, add the call then). `mergeClusters()` keeps its signature and delegates after resolving.
  This also adds the `user_id` scope its `UPDATE` lacks today.
- `deleteMany(string $userId, array $clusterIds): int`: the same all-or-nothing resolution, then
  one transaction doing what `deleteCluster()` does for each, set-based. `deleteCluster()`
  delegates.

**Empty-source cleanup** (private `deleteEmptyUntitledClusters(string $userId, array $clusterIds)`):
before a face move/detach/ignore, record the distinct positive source `cluster_id`s of the
affected rows (`SELECT DISTINCT cluster_id FROM rfd WHERE user_id = :u AND id IN (...) AND cluster_id > 0`).
After the update, delete those with `title = ''` that have no member left (the subquery from
`deleteEmptyClusters()`). Named empty people are kept on purpose: the user may be about to move
faces into them.

**Verify.** `occ facewright:people` output is unchanged for an existing user (the CLI uses the
defaults). Then a transaction you roll back at the end, in `php occ shell`-style (or a scratch
`occ` command you don't commit) on the compose DB:
- `moveFaces` of N faces from A to B: `count(A)` drops by N, `count(B)` rises by N, and A is
  deleted iff it was untitled and N = its size.
- `mergeMany(keep, [a, b])`: `count(keep)` after = the sum of the three before; `a` and `b` are
  gone from `rfc`.
- A foreign user's detection id in the list: the count excludes it, and their row is unchanged.

## 4 — OCS API for the new operations

`lib/Controller/PeopleApiController.php` and `appinfo/routes.php` (`'ocs'` key). Same
conventions as the existing actions: `#[NoAdminRequired]`, 401 without a session,
`\InvalidArgumentException` → 400 (404 for "does not exist" lookups, as today).

| verb | url | action | params / result |
|---|---|---|---|
| GET | `/api/v1/people` | `listClusters` (extended) | `limit` (default 60, max 200), `offset`, `minCount`, `search` → `{items: [...], total}` |
| GET | `/api/v1/people/unassigned` | `listUnassignedFaces` (extended) | `limit`, `offset` → `{items, total}` |
| GET | `/api/v1/people/ignored` | `listIgnoredFaces` | as above |
| GET | `/api/v1/people/{id}/faces` | `listFaces` (extended) | as above |
| POST | `/api/v1/people/merge` | `mergeMany` | `keep`, `merge: int[]`, `dryRun` |
| POST | `/api/v1/people/delete` | `deleteMany` | `ids: int[]` → `{unassigned}` |
| POST | `/api/v1/faces/move` | `moveFaces` | `ids: int[]`, and either `clusterId` or `newTitle` → `{clusterId, moved}` |
| POST | `/api/v1/faces/detach` | `detachFaces` | `ids` → `{count}` |
| POST | `/api/v1/faces/ignore` | `ignoreFaces` | `ids` → `{count}` |
| POST | `/api/v1/faces/unignore` | `unignoreFaces` | `ids` → `{count}` |

- **Changing `GET /people` and the face listings from a bare list to `{items, total}` is a
  contract change.** Grep `js/` for callers before making it (`admin.js` uses only the admin
  merge route today) and update any you find in the same commit.
- Declare every static path (`/people/unassigned`, `/people/ignored`, `/people/merge`,
  `/people/delete`) **before** the `/people/{id}` routes, as the existing comment in
  `routes.php` says. Add `'requirements' => ['id' => '\d+']` to the `{id}` routes as a second
  guard.
- Cap `ids` at 5,000 per request (400 above that). The UI pages at most 200 per grid, so
  select-all-on-page never comes near it.
- Restart the web container.

**Verify** from inside the `nextcloud` container:
`curl -u admin:dev-admin-password -H 'OCS-APIRequest: true' 'http://localhost/ocs/v2.php/apps/facewright_bridge/api/v1/people?format=json&limit=5&minCount=3'`
returns 5 items and a total that matches `countClusters`. `POST /faces/ignore` with one id of a
second user returns `count: 0`. Check `#[NoAdminRequired]` on every new action through
`ControllerMethodReflector`, not by "it works as admin" (CLAUDE.md).

## 5 — Face crop endpoint

New `lib/Controller/FacePreviewController.php extends Controller`, with the constructor injecting
`?string $userId`, `IRootFolder`, `IPreview`, `IDBConnection`, `LoggerInterface`. Route (regular
`'routes'`, not OCS, since `<img src>` can't send OCS headers):
`['name' => 'FacePreview#preview', 'url' => '/faces/{id}/preview', 'verb' => 'GET', 'requirements' => ['id' => '\d+']]`.
Attributes: `#[NoAdminRequired]`, `#[NoCSRFRequired]`. It's a GET with no side effects, and
`<img>` can't send a request token.

`preview(int $id, int $size = 256)`:
1. Clamp `$size` to 64…512.
2. `SELECT file_id, x, y, width, height FROM rfd d WHERE d.id = :id AND d.user_id = :u AND <DetectionService::ownRowsExpr($qb, 'd')>`.
   No row → `NotFoundResponse`.
3. `$file = $this->rootFolder->getUserFolder($uid)->getFirstNodeById($fileId)`. If it isn't an
   `OCP\Files\File` → 404. (If `getFirstNodeById` doesn't exist on the stack, use
   `getById()[0] ?? null`.)
4. `$preview = $this->preview->getPreview($file, 1024, 1024, false)`. The aspect ratio is kept,
   so normalized coordinates apply directly. `NotFoundException` → 404.
5. Crop exactly as Memories' `PeopleBackendUtils::cropFace()` does
   (`custom_apps/memories/lib/ClustersBackend/PeopleBackendUtils.php`, read it).
   It's a square of `max(w·W, h·H) · 1.5` around the box centre, then
   `$img->scaleDownToFit($size, $size)`. That matches the geometry Memories already proves
   correct on these rows, including EXIF-rotated photos. Do not copy the code verbatim (AGPL
   both sides, but keep it ours); the arithmetic is five lines.
6. `new DataDisplayResponse($img->data(), 200, ['Content-Type' => $img->mimeType()])`, then
   `->cacheFor(7 * 24 * 3600, false, false)` and
   `->setETag(md5($id . '|' . $file->getEtag() . '|' . $size))`. Check on the stack whether the
   framework answers `If-None-Match` with 304 on its own; if not, compare it in the action.
7. Any `\Throwable` from image handling → log at warning level with the detection id, then 404.
   A broken image must not 500 the whole grid.

**Verify.** `curl -u admin:… -o /tmp/f.jpg http://localhost/index.php/apps/facewright_bridge/faces/<id>/preview?size=256`
gives a face. A second user's detection id gives 404. A legacy 128-dim row's id (if the stack has
one) gives 404. A portrait photo with EXIF orientation 6 crops the face, not a wall: compare it
with Memories' crop of the same face.

## 6 — Merge suggestions backend, with a measurement gate

**Migration** `lib/Migration/Version000006000Date2026MMDD000000.php` (same style as
`Version000005000…`, no foreign keys):

`facewright_cluster_centroids`: `cluster_id` bigint PK (`fw_centroid_pk`), `user_id`
string(64) notnull (index `fw_centroid_user`), `centroid` blob notnull (512 × float32-LE,
`FaceVectorCodec`), `member_count` int notnull, `computed_at` bigint notnull.

`facewright_merge_dismissals`: `id` bigint autoincrement PK, `user_id` string(64), `cluster_a`
bigint, `cluster_b` bigint. Unique index (`user_id`, `cluster_a`, `cluster_b`)
`fw_dismiss_pair`. The invariant is `cluster_a < cluster_b`, always; normalize in code.

**`lib/Service/SuggestionService.php`** (constructor: `IDBConnection`, `PeopleService`,
`UserSettingsService`, `IAppConfig`, `ITimeFactory`).
- `REPRESENTATIVES = 10`. A centroid is the L2-normalized mean of up to 10 member embeddings,
  chosen as for covers (best `det_score` first), read from `ff` via `fdf`. Never `face_vector`.
- `centroids(string $userId, array $clusters): array<int, list<float>>` (clusters as from
  `listClusters`) loads cached rows for those ids. A row is stale when its `member_count` ≠ the
  cluster's current `count`. Recompute stale and missing ones, then `setValues()` them. This
  is the only place embeddings are read.
- `forPerson(string $userId, int $clusterId, int $limit = 10): list<{id, title, count, cover, distance}>`:
  candidates are `listClusters($u, null, 0, $minFaces)` where `$minFaces` is the user's
  `getClusterMinFaces()`. Drop the person itself and dismissed pairs; compute distance
  `1 − dot` against each centroid; keep `distance <= suggest_max_distance`; sort ascending.
- `dismiss(string $userId, int $a, int $b): void`: both ids must be the user's (`getCluster`),
  then normalize the order and `setValues()`.
- `suggest_max_distance`: appconfig `facewright_bridge/suggest_max_distance`, default `0.6`, a
  placeholder until calibrated (see below).

**Cleanup hooks.** In `PeopleService::mergeMany()` / `deleteMany()`, delete centroid rows and
dismissal rows referencing any removed cluster id; for `keep`, delete its centroid only.
`ClusteringService::resetUser()` deletes the user's rows in both tables. `ResetService` (full
reset) deletes both tables entirely, following the way it already handles `ff`/`fdf`. Rows
orphaned by Memories-side deletes (Recognize DAV) are harmless: `rfc` ids are autoincrement and
never reused, and every read joins against current clusters. Say so in the docblock rather than
adding a sweeper.

**Command**: extend `occ facewright:people` with `--suggest --faces=<idOrTitle>` (per person;
`--suggest` alone is an error). It prints the suggestions, distances and **elapsed wall time
and peak memory** (`hrtime`, `memory_get_peak_usage(true)`).

**Per person only, by decision.** A library-wide list was built first and measured: named ×
candidates comparisons took ~0.9 s warm with 40 named people but ~7 s with 400, so it was
removed rather than tuned. There is no global suggestions view anywhere in the UI.

**Measurement gate (stop here and report to the user before step 7).**
1. On the compose stack: correctness. A person split in two shows up as a top suggestion.
2. With the user's explicit approval, on production: add `--no-store` to the command. It
   computes centroids in memory and writes nothing, so it runs read-only against the existing
   Stage 1 tables without step 6's migration being deployed. (Confirm with the user that
   `occ facewright:migrate-faces` has completed there first, or the numbers are meaningless.)
   This needs the new command code copied to production, which is a deployment, so the user
   decides and does it. Record: cluster count, candidate count, time and peak memory for
   centroid computation (the cold cost) and for the distance pass alone (roughly the warm cost).
3. Thresholds: if the warm per-person time is over 1 s or the cold per-person time is over 10 s,
   stop. The remedy (precompute in `ClusteringMaintenanceJob`, or sampling) is a decision for
   the user, not for this session. Dev result (synthetic, 2,500 people, warm buffer pool): warm
   0.12 s, cold 1.7 s, so the gate is met there; the production run is a sanity check, not a
   blocker.
4. Calibration of `suggest_max_distance` against the hand-labelled ground-truth set (kept
   outside the repo, CLAUDE.md Open item 2) is the user's call. Ask whether they want to provide
   it. Until then the UI shows the top 10 under the placeholder, sorted by distance, which stays
   useful even if the threshold is wrong.

## 7 — Suggestion OCS routes

Add to `PeopleApiController` (or a separate `SuggestionApiController` if `PeopleApiController`
passes ~250 lines; same conventions either way):

| verb | url | action |
|---|---|---|
| GET | `/api/v1/people/{id}/suggestions` | `forPerson` → `{items}` |
| POST | `/api/v1/suggestions/dismiss` | `dismiss` (`a`, `b`) |

Declare `/people/{id}/suggestions` before the generic `/people/{id}` routes, with the `id`
digits requirement. `forPerson` clamps `limit` to 1..50 and answers 404 for a person that isn't
the user's; `dismiss` answers 400 for a foreign or identical pair. Restart the web container.
**Verify** with `curl` that the result matches step 6's command output, that a second user gets
404/400 for the first user's people, and that a dismissed pair disappears from `forPerson`.

## 8 — Page skeleton: navigation, controller, template, routing

- `lib/Controller/PageController.php extends Controller`, `index(): TemplateResponse` with
  `#[NoAdminRequired]`, `#[NoCSRFRequired]`. It renders `templates/main.php` with
  `enabled` (`UserSettingsService::isEnabled`), `minFaces` (`getClusterMinFaces`) and
  `personalSettingsUrl` (`IURLGenerator::linkToRoute('settings.PersonalSettings.index', ['section' => 'facewright_bridge'])`).
  It calls `Util::addScript('facewright_bridge', 'people')` and
  `Util::addStyle('facewright_bridge', 'people')`. Route
  `['name' => 'Page#index', 'url' => '/', 'verb' => 'GET']`.
- `appinfo/info.xml`: a `<navigations><navigation><name>People</name><route>facewright_bridge.page.index</route><icon>app.svg</icon><order>8</order></navigation></navigations>`
  block. **Element order is enforced by `app-info.xsd`.** Read the xsd in the container
  (`resources/app-info.xsd`) and put `<navigations>` where its sequence says, then run
  `occ app:check-code facewright_bridge` or open the apps page to confirm it parses. Version bump,
  `occ upgrade`, restart.
- `templates/main.php`: a left navigation (`People`, `Unassigned`, `Ignored`) and
  a content area. Use core's app layout markup (`#app-navigation`, `#app-content`) if it still
  styles correctly on the stack; otherwise use a plain flex layout in `people.css`. Strings via
  `$l->t()`. Pass `minFaces`, `enabled` and URLs to JS via `data-` attributes on the root
  element (no inline script: CSP).
- `js/people.js`: one IIFE like `personal.js`. It contains:
  - `ocs(method, path, body)` → `fetch(OC.linkToOCS('apps/facewright_bridge/api/v1', 2) + path + '?format=json' …)`
    with `OCS-APIRequest: true`, `requesttoken: OC.requestToken`, JSON body. It unwraps
    `ocs.data` and throws `ocs.data.message` on non-2xx.
  - A hash router: `#/people`, `#/person/<id>`, `#/unassigned`, `#/ignored`.
    Default is `#/people`.
  - An empty state when `enabled` is false: one sentence and a link to personal settings.
- `css/people.css`: only Nextcloud CSS variables (`--color-main-background`,
  `--color-primary-element`, `--border-radius-large`, …) so dark mode and theming work. Grids
  use `grid-template-columns: repeat(auto-fill, minmax(…))` so they work at phone width.
- JS strings stay plain English, as in `personal.js`. Template strings go through `$l->t()`.

**Verify.** A non-admin user sees "People" in the top bar, the page loads with no console errors
and no CSP violations, and a user without Facewright enabled sees the empty state.

## 9 — People list

- `#/people`: a grid of tiles showing the crop `faces/<cover>/preview?size=256` (a placeholder
  when `cover` is null), the title or "Unnamed", and the face count. A header shows the total, a
  search box (debounced 300 ms → `search`) and a "Hide people with fewer than [N] faces" number
  input, defaulting to `minFaces` and remembered in `localStorage` (wrapped in try/catch).
- Infinite scroll: `limit=60`, with an `IntersectionObserver` on a sentinel element that loads
  the next `offset`. Stop when a page returns fewer than `limit`.
- `loading="lazy"` on every `<img>`.

**Verify** on a user with ≥ 200 clusters: named people come first, scrolling loads more, the
filter and search change the total, and the network tab shows no request returning more than
`limit` items.

## 10 — Person detail and face actions

- `#/person/<id>`: a header with the title (click → inline edit; Enter saves via
  `PUT /people/{id}`). The face count, and buttons "Merge into…", "Delete person".
- Rename collision: the 400 message from `assertValidTitle()` contains "already exists; merge
  instead". The JS recognizes that case (match on the HTTP status plus a `code` field: add
  `'code' => 'title_exists'` to that one 400 in the controller, rather than parsing English) and
  offers "Merge into <name>". That resolves the name via `GET /people?search=<exact>` and calls
  the merge flow from step 12.
- A paged face grid (`limit=120`, infinite scroll), each tile a crop at size 160.
- **Selection model**, shared by every face grid: click toggles, shift-click selects a range in
  render order, and "Select all loaded" / "Clear". A sticky action bar appears when the
  selection is non-empty and shows the count plus "Move to…", "Remove from person",
  "Not a face". "Remove from person" is hidden in the Unassigned view, and "Not a face" is
  replaced by "Restore" in the Ignored view.
- "Move to…" opens a `<dialog>` person picker: a search box over `GET /people?search=…&limit=20`
  plus "New person named …" (→ `newTitle`).
- After any action, remove the affected tiles locally and update counts. Don't reload the whole
  grid (offsets would shift; reload only when the user scrolls back to top).

**Verify.** Move 3 faces to another person: the source count drops by 3, the target rises by 3,
and Memories shows the same. Remove 2 → they appear in Unassigned. "Not a face" on 1 → it appears
in Ignored and not in Memories. Shift-click across 20 tiles selects 20.

## 11 — Unassigned and Ignored views

`#/unassigned` and `#/ignored` reuse step 10's grid and selection with their own endpoints. The
header explains each in one sentence. Unassigned: "Faces removed from a person, or left over when
a person was deleted. They won't be grouped automatically." Ignored: "Faces marked as not a
face. Restoring one lets automatic grouping consider it again."

**Verify.** Restore from Ignored puts the row at `NULL`, and the next `occ facewright:cluster`
may place it.

## 12 — Photo modal, merge and delete flows

- **Photo modal**: clicking a tile (not a selection click; selection is the checkbox corner
  or a modifier key; pick one and be consistent) opens a `<dialog>` with
  `<img src="/index.php/core/preview?fileId=<f>&x=1024&y=1024&a=1">` inside a
  `position: relative` wrapper. Boxes come from `GET /files/<f>/faces`, absolutely positioned
  with `left/top/width/height` as percentages (the rows are normalized, and `a=1` keeps the
  aspect). Each box is labelled with its title or "Unnamed"/"Unassigned". Clicking a box selects
  that face for the same actions. Add an "Open in Files" link `/index.php/apps/files/?fileid=<f>`.
  Get the `file_id` from the face listing (already in the payload).
- **Merge**: from a person, "Merge into…" opens the person picker. On pick, call `dryRun: true`,
  show "Move N faces from A into B. A's name is dropped; B's is kept.", then confirm → real call →
  navigate to B. From the people list with ≥ 2 people selected: "Merge selected" asks which one
  to keep (a radio list of the selected people), shows the dry-run count, then calls
  `/people/merge`.
- **Delete**: a confirm `<dialog>`: "Delete A? Its N faces move to Unassigned; nothing is
  deleted from your files." Bulk delete from the people list does the same with a total count.
- People-list selection uses the same selection model as faces (step 10), on tiles.

**Verify.** Merge two people via the UI: in SQL, the losing `rfc` row is gone and `count(keep)` =
the sum. Memories shows one person. The dry-run count equals the real `moved`. Delete a person:
`count(-1)` rises by its size.

## 13 — Suggestions on the person page

No separate view. Person detail gets a "Possibly the same person" strip from
`/people/{id}/suggestions`: each suggestion shows its cover crop, name or "Unnamed", face count
and similarity (`round((1 − d) * 100)`%), with "Merge" (this person is kept; the dry-run
confirmation from step 12) and "Not the same person" (→ dismiss, the card disappears).
- Load it after the face grid, with a spinner; the first (cold) request is the slow one per
  step 6's numbers. Don't show the strip at all when the list is empty.
- Cache the response in memory for the page's lifetime; invalidate on merge or dismiss.

**Verify.** After a merge or dismiss the suggestion is gone after a reload. A dismissed pair doesn't
come back after a clustering pass.

## 14 — Docs

- `CLAUDE.md`:
  - "Schema mapping": add `-3 = ClusteringService::IGNORED` to the `cluster_id` paragraph (set
    only by a human, kept by reset, invisible to Memories/Recognize, lost on forced
    re-detection/full reset). Add the two step-6 tables.
  - "Job semantics": one bullet saying that `persistAssignments()` writes only rows still
    `NULL`/`-2` into clusters that still exist, and why.
  - "Components": `PageController`, `FacePreviewController`, `SuggestionService`, the page
    assets, the new OCS routes in one line.
  - "Coexisting with Recognize": `occ recognize:reset-face-clusters` (Recognize's
    `resetClusters()`, `SET cluster_id = NULL WHERE cluster_id IS NOT NULL`, no user or
    dimension filter) wipes every assignment including ours and `-3`. Never run it.
  - "Open items": item 4's fourth bug gets "Use Facewright's People page instead (removal there
    sticks)". Item 6 is unchanged.
- `README.md`: a short "People page" paragraph (what it does, that Memories stays the photo
  browser).
- `docs/people-ui.md`: mark the open points as resolved, with the measured numbers from step 6.

## 15 — Release

Set `<version>` 0.8.0, `occ upgrade`, restart, and run the full verification matrix below once
more end to end on the compose stack. Production deployment is the user's decision and the
user's action; prepare the commands, don't run them.

---

## Verification matrix (compose stack, two non-admin users `alice`, `bob`, one shared folder)

| check | expected |
|---|---|
| `bob` calls any `alice` cluster/face id via OCS or preview | 400/404 or `count: 0`; `alice`'s rows unchanged |
| clustering pass with a concurrent UI move/remove (step 1 harness) | the human's value wins, no orphan cluster ids |
| ignore → cluster → reset | `-3` count unchanged throughout |
| merge/delete/move counts | before/after pairs reconcile exactly |
| Memories after each UI action | shows the same names, members and unassigned faces |
| shared file: `alice` ignores a face | `bob`'s copy of that face is unaffected |
| crop of an EXIF-rotated photo | matches Memories' crop |
| phone width (devtools, 375 px) | no horizontal scroll; grids reflow |
| `POST /detect` count in ExApp logs | unchanged by any UI action (the UI never triggers inference) |

## Deviations from `people-ui.md`

1. **The centroid cache table is part of the design from the start** (step 6), not a fallback.
   On demand, every person view would read up to 10 embeddings for every candidate cluster.
   That's 2,000 clusters × 10 × 2 KB ≈ 40 MB per request, which is too much regardless of CPU.
   The measurement was whether the *cached* path is fast enough: it is (step 6).
2. **Suggestions are per person only; there is no library-wide list.** Decided by the user
   after a global list was built and measured: its cost grows with named people × candidates
   (~7 s warm at 400 named people), and "who else is this person?" is the more actionable
   question. If a global view is wanted later, it belongs in a background job.
3. **The list filter always shows named people**, even below the minimum size, so a named
   person with one face can't become unreachable.
4. **Moving faces out of an untitled person that becomes empty deletes it**, and named ones
   are kept. That isn't in the requirements, but without it every bulk move leaves "Unnamed (0)"
   tiles behind.
5. **Covers are best `det_score`**, not Memories' position/size heuristic. That's simpler, and
   it uses data only we have. Revisit if covers look poor.

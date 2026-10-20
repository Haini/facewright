# Getting independent from Recognize and Memories

A review of how far `facewright_bridge` is coupled to Recognize and Memories today, and a staged
roadmap for reducing it. Companion to `CLAUDE.md`, which documents the current design; this file
documents what would have to change and in which order.

The step-by-step implementation plan for the roadmap is `docs/independence-implementation.md`;
where the two differ, that plan wins (its "Deviations" section lists the differences and why).

Verified against the app versions installed on the reference instance: Memories 8.1.0,
Recognize 12.0.2 (both at `/mnt/data/nextcloud/nc_apps/`), and the Photos app shipped with
Nextcloud 33. Line numbers refer to those versions.

## Why

Companion mode was the fastest way to a working People page, and it still is. Its costs are now
visible:

- **Schema drift.** Recognize 10.0.0 renamed the vector column (`vector` → `face_vector`,
  `Version010000001Date20250727094721`). Every one of our 30 table references would have broken
  silently or loudly; nothing warns before `occ upgrade`.
- **Two write-path bugs** (below) that cannot be fixed on our side, because Memories' writes land
  in Recognize's code, not ours.
- **The coexistence machinery** — `.noimage` markers, the `-1`/`-2` sentinels, the dimension-band
  ownership proxy, watching `oc_jobs` after every Recognize upgrade — is all cost paid to a
  classifier nobody on the instance wants running.

The reference instance uses Recognize for faces only. Replacing it outright is therefore an
acceptable end state; the roadmap treats it as the target and coexistence as the interim.

## Coupling inventory

### Recognize

| # | What we depend on | Our side | Their side |
|---|---|---|---|
| R1 | The two tables' schema: 30 references in 8 files | `ClusteringService` (9), `AccessSyncService` (6), `ClusterMergeService` (4), `ResetService` (3), `VectorCompactionService` (3), `DetectionService` (2), `Command/ResetFacewrightData` (2), `Command/ClusterFaces` (1) | Column order `x, y, height, width`; `face_vector` `TEXT NOT NULL`; `threshold FLOAT`; `cluster_id` nullable (`Version002002000Date20220614094721.php:41-98`) |
| R2 | Sharing the table with Recognize's legacy 128-dim rows | `DetectionService::ownRowsExpr()` / `VECTOR_LENGTH_FLOOR` (`DetectionService.php:80,257`), `ClusteringService::EXPECTED_DIMENSIONS`, `skippedForeign` | — |
| R3 | `cluster_id` sentinels chosen around Recognize's jobs: `-1` read as pending, `-2` as our "evaluated, unclusterable" | `ClusteringService.php:56-63,428-436` | `FaceDetectionMapper::getUsersForUnclustered()` (`:349`) and `sampleRejectedDetectionsByUserId()` (`:276`) |
| R4 | `.noimage` marker per user root to keep the classifier off; APCu-cached `IgnoreService`; the mass-`FsDeletion` trap | operational only (CLAUDE.md, "Coexisting with Recognize") | `FileListener` |
| R5 | `faces.enabled` under the `recognize` app key, written directly | `PeopleSettingsController.php:50`, `AdminSettings.php:35` | Memories reads it (M5) |
| R6 | Every People-page **write** is a WebDAV call into Recognize's tree `recognize/{uid}/faces/…` | nothing of ours is involved — that is the problem | `Dav/RootCollection.php:51-53` (`getName()` returns `'recognize'`; the mount name comes from there, not the app id), registered via `<collections>` in `appinfo/info.xml:133`; `PropFindPlugin` added on `SabrePluginAddEvent` (`AppInfo/Application.php:61-70`) |
| R7 | The tree is gated by an API key: header `X-Recognize-Api-Key`, an `ICrypto`-encrypted JSON `{type: 'recognize-api-key', version: 1, timestamp}` valid 24 h, enforced unless appconfig `require_api_key` is not `'true'` | — | `Dav/Faces/PropFindPlugin.php:131-166`; minted by `Public/ApiKeyManager.php` |
| R8 | `MaintenanceJob` / `ClusterFacesJob` crash on 512-dim rows (multi-GB, `memory_limit=-1`) | see CLAUDE.md | registered unconditionally |

### Memories

| # | What we depend on | Evidence |
|---|---|---|
| M1 | The **read set**: `rfc.id, user_id, title`; `rfd.id, cluster_id, file_id, x, y, width, height`. Inner-joins `oc_memories` on `fileid`, so only Memories-indexed files ever show. Never reads `face_vector` or `threshold`. | `ClustersBackend/RecognizeBackend.php:104-116,144-147,227-239` |
| M2 | `memories_covers` stores a **detection id** as a cluster's cover | `RecognizeBackend.php:177-184` |
| M3 | Frontend hardcodes the cluster types `recognize` and `facerecognition`, and the DAV paths `/recognize/${user}/faces/${name}/${faceid}-${basename}`. Write operations: `moveFile` (rename person; move face to person), `deleteFile` (delete person; remove face), `createDirectory` (new person), `getDirectoryContents` (its merge flow) | `js/memories-main.js` |
| M4 | `PageController::__construct(private ?\OCA\Recognize\Public\ApiKeyManager $apiKeyManager)` — a dependency on a **class in Recognize's namespace**. Nullable, so DI passes `null` when the class is absent; the key reaches the frontend as the `recognizeApiKey` initial state | `Controller/PageController.php:29,48-58` |
| M5 | Gate: `isEnabledForUser('recognize')`, version ≥ 3.8.0, and `faces.enabled === 'true'` | `Util.php:93-121` |
| M6 | The "unassigned faces" route selects `cluster_id = -1` | `RecognizeBackend.php:100` |
| M7 | The backend registry is pluggable **server-side** (`Manager::register()`, `Backend::register()`); the frontend is not | `ClustersBackend/Manager.php:59`, `Backend.php:177` |

### Photos (Nextcloud's own app)

| # | What it does | Evidence |
|---|---|---|
| P1 | Same contract as Memories: `isEnabledForUser('recognize')`, injects `ApiKeyManager`, provides `recognizeApiKey`, talks to the `recognize/…` DAV tree | `apps/photos/lib/Controller/PageController.php:93-102,141` |

**Consequence.** The "recognize contract" — app id `recognize` at version ≥ 3.8.0,
`faces.enabled`, `OCA\Recognize\Public\ApiKeyManager`, the DAV tree with its `PropFindPlugin`
properties, and the two tables' read set — is a de-facto ecosystem interface with two consumers.
Implementing it once serves both, without a patch to either.

The DAV tree a replacement has to reproduce (from `recognize/lib/Dav/`):

- `recognize/{uid}` → children `faces` and `unassigned-faces` (`RecognizeHome.php:67-87`).
- `faces/{title-or-id}` — a cluster; name is the title, or the numeric id when untitled
  (`FaceRoot.php:53-55`). `setName` renames and throws `Forbidden` on a duplicate (`:60-71`);
  `delete` removes the cluster (`:145-150`); `moveInto` re-assigns a face (`:132-139`); `createDirectory` on
  `faces/` creates an empty cluster and refuses purely numeric names (`FacesHome.php:52-63`).
- `faces/{cluster}/{detectionId}-{basename}` — a face (`FacePhoto.php:41-45`); `delete` detaches
  it from the cluster.
- Properties (`PropFindPlugin.php:34-38,64-110`): `nc:face-detections`, `nc:file-name`,
  `nc:realpath`, `nc:nbItems`, `nc:face-preview-image`, plus fileid, etag, favorite, has-preview,
  permissions and file metadata on a face; `nc:face-detections` is also served on ordinary
  `File` nodes (that is what draws face rectangles on a photo).
- `beforeMove` refuses a rename onto an existing title (`:112-130`); `beforeMethod` is the API-key
  gate (R7).

`CLAUDE.md`'s earlier "~300–400 lines, no Memories patch" estimate for a shim omitted
`ApiKeyManager`, the property list and Photos. The size is closer to a stripped copy of
`recognize/lib/Dav/` (885 lines today) plus the key manager, and the shim must declare
`<namespace>Recognize</namespace>` so the class Memories and Photos inject resolves.

## Findings from the review

Confirmed by reading both code bases; not yet reproduced on a live instance. Reproduce on the
compose stack before fixing (see "Verification").

1. **Deleting a person in Memories brings the person back.** `FaceRoot::delete()` →
   `FaceClusterMapper::delete()` (`recognize/lib/Db/FaceClusterMapper.php:92-100`) sets every
   member's `cluster_id = -1` before deleting the cluster row. `ClusteringService::loadDetections()`
   (`ClusteringService.php:428-432`) reads `-1` as "never evaluated by us", so the next
   `ClusterScanJob` pass re-forms the same cluster under a new id with an empty title. Until that
   pass, the faces appear in Memories' "unassigned" view (M6).
   **Fixed by Stage 0 (`-1` = unassigned)**: `ClusteringService::UNASSIGNED` now excludes `-1`
   rows from clustering entirely instead of reading them as pending, with no schema change - see
   `docs/independence-implementation.md` Stage 0, step 0.2.
2. **Removing a face from a person does not stick.** `FacePhoto::delete()`
   (`recognize/lib/Dav/Faces/FacePhoto.php:48-59`) sets `cluster_id = NULL` — pending, for us — and
   writes `threshold`, which nothing of ours reads. The face still sits within 0.5 of its former
   cluster-mates and rejoins on the next pass. Before that it loads **every** detection of the
   cluster including vectors (`findByClusterId` selects all columns) to compute a Euclidean
   centroid inside the web request; for the very large clusters noted in CLAUDE.md's Open item 2
   that is tens of thousands of ~4.8 KB JSON strings decoded in one PHP request.
3. **`-1` now has three producers**, not one: Recognize's HDBSCAN noise label, Memories'
   person-delete (via 1), and our own reading of it as pending. The sentinel table in CLAUDE.md
   describes only the first.
4. `PeopleSettingsController::setEnabled` writes another app's config as the only way to toggle
   our own page's visibility (R5).

Both 1 and 2 need a per-user record of "the user rejected this assignment" that clustering honours.
That record belongs in a table we own — which is why the data-model stage below comes before the
DAV stage, not after.

## Roadmap

Stages are ordered so that each one is useful on its own and none has to be undone by the next.
"Removes" lists the CLAUDE.md invariants and open items that stop applying.

### Stage 0 — an API of our own inside `facewright_bridge`

**Scope.** A controller (OCS or plain JSON, `#[NoAdminRequired]`, session-user scoped like
`PersonalSettingsController`) exposing: list clusters with counts and cover; list faces of a
cluster; rename; merge (`ClusterMergeService`); delete; detach a face; move a face; set cover. The
admin and personal pages switch to it for the operations they already expose (merge, run
clustering). No schema change.

**Why first.** Every later stage — the shim's DAV tree, an own UI, a Memories backend — is a
thin adapter over exactly this set of operations. Writing them once against the current tables
means the adapters never touch SQL.

**Removes.** Open item 6 (`merge-clusters` has no `--dry-run`, admin-only) can be closed as part of
this by giving the API a preview mode.

**Size.** One controller, one service (`PeopleService`) wrapping the existing services; ~400
lines. Reversible (delete it).

### Stage 1 — own the data model

**Scope.** Three tables, migrated by a resumable `occ` command (CLAUDE.md: large rewrites are not
migrations), with the id list dumped before any delete:

- `facewright_faces` — the physical face, once per file: `file_id`, `face_index` (detection
  order within the file), `x, y, width, height` (normalized, top-left + size, same as today),
  `det_score`, `embedding` (binary float32, 2,048 bytes; today's JSON is 4.8 KB per user with
  access).
- `facewright_user_faces` — per-user assignment: `user_id`, `face_id`, `cluster_id` (nullable),
  `state` (`pending` / `unclusterable` / `detached`) and `rejected_cluster_id` (nullable; set by
  "remove face from person" so clustering does not put it straight back).
- `facewright_clusters` — `user_id`, `title`, `cover_face_id`.

`recognize_face_detections` and `recognize_face_clusters` become a **projection** we maintain:
one row per (user, face) carrying geometry, `cluster_id` and a `face_vector` of `'[]'`. The
column is `NOT NULL` and `FacePhoto::delete()` decodes it, so it must remain a JSON array;
`'[]'` makes that method a no-op-with-warning instead of a crash. A mapping column
(`facewright_user_faces.projection_id`) replaces the length proxy as the definition of "ours".

**Decision to take here (Open item 8).** The face-identity key. Recommend the surrogate
`(file_id, face_index)` rather than a geometry tuple: re-detecting a file (a future model
upgrade) changes every box by a few pixels, and a geometry key would make every such row a new
face.

**Removes.** R1's reach shrinks to the 7 projected columns. R2 disappears entirely
(`ownRowsExpr()`, `VECTOR_LENGTH_FLOOR`, `EXPECTED_DIMENSIONS`, `skippedForeign`, the
"all-zero embedding falls below the floor" case, `compact-vectors`). Findings 1 and 2 become
fixable (the shim in Stage 2 fixes them; until then, `AccessSyncService`-style reconciliation
can at least re-project from our state). Open item 8 closes. Fan-out stops duplicating vectors
(the 1.5× noted in Open item 8).

**Blast radius.** Every table-touching service is rewritten against the new tables:
`DetectionService`, `ClusteringService`, `AccessSyncService`, `ClusterMergeService`,
`ResetService`, `PruneService`, `BackfillAccess`. This is the largest stage and the first
irreversible one once the old `face_vector` values are dropped — so the command keeps them until
a separate `--drop-legacy-vectors` step, verified by a count pair that must reconcile.

### Stage 2 — replace Recognize with a `recognize`-contract shim

**Precondition.** Stage 1: once Recognize is removed, its migrations no longer create the tables,
so our data must already live in tables we own; the projection tables are then created by the
shim's own migration.

**Scope.** A second small app in this repo (or a mode of `facewright_bridge` — but app id and
namespace must be `recognize` / `Recognize`, so it is cleaner as its own directory):

- `appinfo/info.xml`: `<id>recognize</id>`, `<namespace>Recognize</namespace>`, a version
  ≥ 3.8.0 that sorts *below* real Recognize's (so a stray app-store update cannot overwrite it —
  or, better, `<dependencies>` that make the store refuse), `<dav/>` with the `<collections>`
  entry, and a migration that creates `recognize_face_detections` /
  `recognize_face_clusters` with the same names, columns and indexes if absent.
- `faces.enabled` appconfig owned and written by us (moves out of `PeopleSettingsController`).
- `OCA\Recognize\Public\ApiKeyManager` with the same `generateApiKey()` shape (M4, P1).
- `Dav/RootCollection` + `RecognizeHome` + `Faces/*` + `PropFindPlugin`, each method a call into
  Stage 0's API: delete person → mark members `detached`/`pending` in *our* state (Finding 1),
  detach face → set `rejected_cluster_id` (Finding 2), the rest as today.
- `unassigned-faces` maps to our `detached` state, not to `-1`.

**Cutover** (one-time, per instance): `occ app:disable recognize`, `app:remove recognize` (tables
survive), purge the legacy 128-dim rows by dumped id list, remove the `.noimage` markers (safe
once Recognize is gone — the mass-deletion trap was Recognize's `FileListener`), install the
shim, `occ upgrade`. Restart the web container (APCu).

**Removes.** R3, R4, R7 (we own the key), R8, the whole "Coexisting with Recognize" section of
CLAUDE.md, `RECOGNIZE_REJECTED`, `UNCLUSTERABLE`'s reason for being `-2` (any value works once
nobody else reads the column), the `oc_jobs` check after upgrades. Findings 1–4 are fixed.

**Costs.** Cannot coexist with real Recognize (same id): object/landmark tagging and music genres
are gone for good on that instance. Not publishable on the App Store under that id; distribution
stays manual. Memories and Photos keep working unchanged (M5, P1 are satisfied by the shim).

**Size.** Roughly the 885 lines of `recognize/lib/Dav/` minus what Stage 0 already provides,
plus `ApiKeyManager` (~30 lines) and one migration.

### Stage 3 — independence from Memories (optional)

With Stage 2 in place, Memories needs no change: it is talking to an app that happens to be
called `recognize`. Stage 3 is about no longer needing that name.

- **(a) Upstream a generic people backend to Memories.** The server side is already a registry
  (M7); the frontend needs its cluster-type list and the DAV/OCS call sites generalized. The
  existing `facerecognition` backend (`ClustersBackend/FaceRecognitionBackend.php`, OCS-based
  writes) is the precedent to point at in a proposal. Offer Stage 0's API as the write surface.
- **(b) An own People page** over Stage 0's API: list, rename, merge, detach, and links into
  Files/Memories for the photos. Only if (a) is refused; it is a second UI to maintain.

Recommendation: attempt (a) as an offer once Stages 0–2 have run for a while; do not build (b)
speculatively.

Update: a narrowed (b) is now planned regardless of (a): a face-crop *curation* page (name,
merge, delete, fix, ignore faces; no photo galleries), because Memories' write path can't
make a face removal stick, has no merge, and doesn't page on large libraries. Memories stays
the place to browse photos. Requirements: `docs/people-ui.md`.

## What stays coupled regardless

- Memories only shows files present in `oc_memories` (M1). Detection on files Memories has not
  indexed is invisible until it indexes them; nothing on our side can change that.
- `memories_covers` references our detection ids (M2). Stage 1's migration must keep detection
  ids stable in the projection, or covers reset once.
- The contract's "unassigned" semantics (`cluster_id = -1`) is what both UIs display; whatever we
  store internally, the projection has to emit `-1` for faces we want shown there.
- The ExApp is already independent: it knows only `POST /detect` and `nc_py_api`.

## Decisions still open

1. Face-identity key for Stage 1 — recommended `(file_id, face_index)`, see above.
2. Whether the shim keeps `require_api_key` enforcement (recommended: yes, same default, so the
   two UIs behave identically to today).
3. Whether `unassigned-faces` shows `detached` faces only, or also `unclusterable` ones
   (recommended: detached only; unclusterable rows number in the tens of thousands on a real
   library and are noise in that view).
4. App-store plan, if any: the shim can never be listed under `recognize`; `facewright_bridge`
   could, but only once it no longer requires the shim — i.e. only after Stage 3(a).

## Verification

- Re-check every line reference above against the installed versions before acting on it:
  `grep -n recognize /mnt/data/nextcloud/nc_apps/memories/lib/Util.php`,
  `sed -n 136,166p /mnt/data/nextcloud/nc_apps/recognize/lib/Dav/Faces/PropFindPlugin.php`,
  `sed -n 92,100p /mnt/data/nextcloud/nc_apps/recognize/lib/Db/FaceClusterMapper.php`,
  `docker exec nextcloud-app-1 grep -n recognize /var/www/html/apps/photos/lib/Controller/PageController.php`.
- Finding 1, on the compose stack (`docker compose up -d && bash dev-setup.sh`): upload photos of
  one person, `occ facewright:cluster <user>`, delete the person in Memories, run the command
  again; an untitled cluster with the same members reappears. Finding 2: remove one face from a
  person, re-run, the face is back in the cluster.
- Stage 1's migration: print the (user, face) count from the old table and the new tables before
  and after; they must reconcile exactly, with the same transaction-and-rollback rehearsal
  CLAUDE.md prescribes.
- Stage 2: with real Recognize removed and the shim installed, Memories' People page and Photos'
  People view both render, and all four write operations (rename, delete, move face, remove face)
  round-trip through `occ facewright:cluster` without undoing themselves.

# Requirements: Facewright People curation UI

Requirements for an own face-curation page in `facewright_bridge` (Stage 3(b) of
`docs/independence.md`, narrowed to curation). Gathered 2026-10-20. The step-by-step
implementation plan is a separate doc, to be written from this one.

## Context

Memories' People page is where photos are browsed by person, but curating clusters through it is
weak. All its writes go through Recognize's DAV code: removing a face sets it `NULL`, so it
rejoins on the next clustering pass (CLAUDE.md Open item 4, independence.md Finding 2). It can't
merge two people (a duplicate name is refused). It doesn't page, so it struggles with tens of
thousands of untitled clusters. Stage 0 already built the write side we need, `PeopleService` plus
the session-scoped OCS API in `PeopleApiController`, but nothing in a browser calls it.

This feature is a **face-crop curation UI** inside `facewright_bridge`: name, merge, delete,
fix and ignore faces. Memories stays the place to browse photos. It is Stage 3(b) of
`docs/independence.md`, narrowed to curation only. That doc said not to build 3(b) speculatively;
the reasons above are why it is built anyway (noted there).

Decisions:
- Placement: its own app page with a top-level navigation entry.
- Unit shown: face crops. A click opens the full photo with its boxes, plus a link to Files.
  No per-person galleries.
- v1 scope: name / merge / delete people, fix individual faces, an unassigned view, merge
  suggestions, bulk selection.
- Two face actions: "remove from person" (→ `-1`, unassigned) **and** "not a face / ignore".
- Scale: named people first, paged, with a minimum-size filter. Face grids are paged too.
- Stack: vanilla JS and a PHP template, no build step (same as `admin.js` / `personal.js`).

## Functional requirements

### F1 Page and access
- Navigation entry "People (Facewright)" → `/apps/facewright_bridge/`, rendered by a new
  `PageController` (`#[NoAdminRequired]`, `#[NoCSRFRequired]` on the GET only). This needs
  `<navigations>` in `info.xml`, a version bump and `occ upgrade`, and a container restart for
  the new route.
- Every read and write is scoped to the session user. Nothing in the API takes a user id, the
  same rule as `PeopleApiController`.
- If the user hasn't enabled Facewright, the page shows an empty state that links to personal
  settings.

### F2 People list (left pane or main grid)
- One tile per cluster: cover crop, name or "Unnamed", face count.
- Ordering stays as `PeopleService::listClusters()` has it: named first, then by size, then id.
- Paged (limit/offset, infinite scroll). There is a "hide people with fewer than N faces" filter,
  defaulting to the user's `min-faces` threshold, and a name search.
- The cover is the member face with the highest `facewright_faces.det_score`, not a manually
  chosen one. Memories' covers live in `memories_covers` and stay Memories' business.

### F3 Person detail
- Inline rename. `assertValidTitle()` rules apply: not empty, not purely numeric, unique. The
  "already exists" error offers **merge into that person** instead of a dead end.
- Paged grid of face crops.
- Actions on one face or a selection: move to another person (existing, or new by name), remove
  from person (`-1`), not a face / ignore.
- Delete person, with a confirm dialog: its faces go to unassigned (`-1`), as
  `PeopleService::deleteCluster()` already does.
- Merge: pick a target person; the target's name wins. The merge runs `dryRun` first to show how
  many faces will move.

### F4 Unassigned view
- Paged grid of `cluster_id = -1` faces, with the same face actions as F3.
- `-2` (unclusterable) faces are **not** listed here. That follows independence.md open decision
  3: they number in the tens of thousands and are noise.

### F5 Ignored ("not a face")
- A new sentinel `ClusteringService::IGNORED = -3`. It is stored per user in
  `recognize_face_detections.cluster_id`.
- Clustering never seeds from it, never matches against it, and never moves it.
  `selectWantedIds()` must skip it the way it skips `-1`. **Today a `-3` would be treated as a
  real cluster id and used as a match target.**
- Memories can't see `-3`: it inner-joins clusters and its unassigned view reads only `-1`.
  Recognize has no predicate that matches `-3`.
- `ClusteringService::resetUser()` (`occ facewright:cluster --reset`) **keeps** `-3`. "Not a
  face" is a judgement about the detection, not about clustering.
- A small "Ignored" view lets the user un-ignore a face (back to `NULL`).
- Limitation, documented rather than fixed in v1: a forced re-detection (`occ facewright:detect`)
  or `facewright:reset` deletes and rewrites the rows, so the ignore is lost.
- CLAUDE.md's `cluster_id` invariant section gets the new value.

### F6 Full photo view
- Clicking a crop opens a modal with the core preview (`/core/preview?fileId=…&x=1024&y=1024&a=1`).
  All of the user's faces on that file are drawn as boxes with names, using
  `PeopleService::facesForFile()` (already restricted to `ownRowsExpr()`).
- An "Open in Files" link (`/apps/files/?fileid=…`). Face actions also work from the boxes.

### F7 Merge suggestions
- A "Possibly the same person" panel on the person detail. Per person only, no library-wide
  list: it was built and measured, and its cost grows with the number of named people.
- Similarity is the cosine distance between cluster centroids, built from up to N representative
  embeddings from `facewright_faces`. That reuses the idea behind
  `CLUSTER_REPRESENTATIVE_CAP`. Candidates are limited to clusters that pass the size filter.
- "Not the same person" dismissals persist. A new table
  `facewright_merge_dismissals(user_id, cluster_a, cluster_b)` keeps a dismissed pair from
  coming back. Rows are removed when either cluster is deleted or merged.
- Cost: centroids are cached in a table (`facewright_cluster_centroids`) and recomputed only
  when a person's size changed. On synthetic dev data (2,500 people) a request takes 0.12 s warm
  and 1.7 s cold.

### F8 Bulk selection
- Multi-select on face grids and on the people list, with shift-click ranges and select-all on
  the page.
- Bulk faces: move, remove, ignore. Bulk people: merge N into one, delete N.
- Each bulk action is **one request and one transaction**, not N requests. That means new
  `PeopleService` methods taking id lists (`moveFaces`, `detachFaces`, `ignoreFaces`,
  `mergeMany`, `deleteMany`), each still scoped to `$userId` in the `WHERE`.

## Backend changes this implies (not exhaustive, for the implementation plan)

- **Face crop endpoint** `GET /faces/{id}/preview?size=…`. It checks that the detection is the
  user's, resolves the file through the user's folder, takes an `IPreview` of about 1024px, then
  crops the normalized box with padding (`OCP\IImage::crop`) and resizes it. It returns
  `Cache-Control: private, max-age` (an ETag on detection id plus file etag). This gives
  sharper crops than CSS-cropping a small preview.
- **Paging** on `listClusters` (limit, offset, minCount, search, cover face id) and on
  `listFaces` / `listUnassignedFaces` (limit, offset). Today they return everything.
- **Race guard in `ClusteringService::persistAssignments()`**: it updates by id with no check
  on the current value. A face the user moved, removed or ignored while a `ClusterScanJob` chunk
  was running gets overwritten. A person deleted or merged mid-chunk can leave rows pointing at
  a cluster id that no longer exists. Fix: only update rows still at the value that was loaded
  (`IS NULL` / `-2`), and check that the target cluster still exists. This is a real bug even
  without the UI; the UI just makes it reachable much more often.
- `mergeClusters()` is not user-scoped in its `UPDATE` (cluster ids are, via `resolveCluster`).
  That's harmless today, but the bulk variant should scope by `user_id` too.
- The OCS routes keep using `PeopleApiController`. The page JS calls `/ocs/v2.php/...` with
  the `OCS-APIRequest` header and the request token.

## Non-functional requirements

- **Scale target**: the reference library has ~10⁵ detections, thousands of clusters, and
  single clusters of thousands of faces. No request loads a whole cluster or selects
  `face_vector`. Embeddings are read only from `facewright_faces`, and only in F7.
- **Consistency with Memories**: the UI writes the same table, so Memories reflects changes right
  away (names, merges, deletes, `-1`). Nothing here writes Recognize's config or jobs.
- **No build step**: one `people.js`, one `people.css`, one template. The JS cache-buster needs a
  version bump per change (CLAUDE.md "Bridge app").
- Mobile works at phone width; desktop is the primary target.
- Template strings go through `$l->t()`. JS strings stay plain English, like `personal.js`.

## Out of scope for v1
- Per-person photo galleries, albums, sharing names with other users (copies stay unnamed,
  per the CLAUDE.md fan-out section).
- Manual cover selection, adding a face box by hand, editing boxes.
- Admin views of other users' people. The admin merge on `PeopleSettingsController` stays as it
  is.
- Undo. Dry-run counts and confirm dialogs stand in for it.

## Open points to settle in the implementation plan

The implementation plan is `docs/people-ui-implementation.md`. It settles point 3 (the race fix is
step 1) and gates point 1 on a measurement (its step 6).
1. F7: compute on demand vs. a cached centroid table, decided by measurement. Also the centroid
   distance threshold. It isn't 0.5, since centroids are tighter than single faces, so it needs
   calibrating against the hand-labelled ground-truth set (Open item 2).
2. Crop endpoint caching: rely on the core preview cache plus HTTP caching, or also store crops
   in appdata.
3. Whether to put the persistAssignments race fix in its own step before any UI work.
   Recommended: yes.

## Verification (for the eventual implementation)
- Compose stack only. Authorization is checked through `ControllerMethodReflector` with a
  non-admin user, and a request with another user's face id or cluster id gets a 400/404.
- Race: start a `ClusterScanJob` chunk, detach a face mid-run, and confirm it stays at `-1`.
- `-3`: ignore a face, run `occ facewright:cluster <user>` and `--reset`, and confirm it stays at
  `-3` and doesn't appear in Memories.
- Bulk move/merge: before/after counts reconcile exactly, in a transaction that's rolled back on
  the reference data first.
- Memories shows renames, merges and deletes made in the new UI without any action on its side.

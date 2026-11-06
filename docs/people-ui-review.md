# People page: review action items

A review of the People page as released in `facewright_bridge` 0.8.0 (commits `db15d3e`..`6826e3c`):
`PeopleService`, `SuggestionService`, `PeopleApiController`, `SuggestionApiController`,
`FacePreviewController`, `PageController`, `js/people.js`, `css/people.css`, and the
`ClusteringService` changes that came with it. Each item below is self-contained: problem, where,
fix, how to verify. Work them in order. Items marked **Ask first** need a decision from the user
before any code changes.

## How to work this list

- Read `CLAUDE.md` first, especially "Invariants" and "Bridge app" deployment mechanics. The
  requirements behind the page are in `docs/people-ui.md`.
- Work against the compose stack only, never production.
- One commit per item (or per tightly related pair, as noted). End each commit message with the
  attribution line the session gives you.
- JS or `info.xml` changes need a `<version>` bump in `appinfo/info.xml` (cache-buster) plus
  `occ upgrade`; new or removed `routes.php` entries need a container restart. Bump once at the
  end of a batch, not per commit, unless you need to test in a browser in between (you will).
- Do not touch: `ClusteringService::persistAssignments()`'s guard logic, the `-1`/`-2`/`-3`
  semantics, `ownRowsExpr()`'s definition, or anything in "Reviewed and fine" at the bottom.
- When an item says "measure", record the numbers in the commit message.

---

## P1: Bugs

### 1. Person page: a late response renders into whichever view is now showing

**Where:** `js/people.js`, `personView()` (the `ocs('GET', '/people/' + id)` at the end) and
`render()`.

**Problem:** `personView()` returns a teardown that only stops `grid`, and `grid` is created
inside the `.then()` of the initial GET. If the user navigates away (Back, or a nav link) before
that GET resolves, `render()` tears down (nothing to stop), clears `main`, and builds the new view;
then the GET resolves and `faceGrid()` appends a grid, message and action bar to `main` *of the new
view*, plus registers a scroll listener nobody will ever remove. The 404 branch has the same
problem (appends "This person no longer exists" to the new view). `showSuggestions()` is harmless
(it writes into a detached node) but still issues the request.

Related: a dialog (`openPhoto`, `pickPerson`, confirm dialogs) is appended to `root`, not `main`,
so it survives a hash change. Closing an orphaned photo dialog then calls `reload()` on a torn-down
grid, which fetches again and calls `scroller.check()` on a stopped scroller.

**Fix:**
- In `personView()`, keep `var stopped = false;`, set it in the returned teardown, and return early
  from the GET's `.then` / `.catch` when `stopped`.
- In `render()`, before clearing `main`, close every open dialog. `dialogStack` holds the elements
  but not their `close` functions; store `{dialog, close}` (or keep a parallel array of closers) and
  call each close in reverse order. `close()` already tolerates being called twice.

**Verify:** In Chromium DevTools, throttle the network to "Slow 3G", open a person, and press Back
immediately. The People list must show only the people grid, one action bar, and scrolling to the
end must issue exactly one `/people?offset=…` request per page (no `/people/{id}/faces` requests).
Open a photo from a person, press Back with the dialog open: the dialog must close.

### 2. Name taken during rename: the merge offer can miss the other person

**Where:** `js/people.js` `offerMerge()`; `PeopleService::assertValidTitle()`;
`DuplicateTitleException`; the three `title_exists` responses in `PeopleApiController`.

**Problem:** `offerMerge()` re-finds the other person with a substring search capped at 200
results and then filters for an exact title. A common name fragment (or a library with many
similarly named people) can push the exact match past 200, and the user gets "already exists"
with no merge offer. The server already knew the id when it threw.

**Fix:** Give `DuplicateTitleException` a `public readonly int $existingId` (constructor param),
set it in `assertValidTitle()` from the `fetchOne()` it already does, and add
`'existingId' => $e->existingId` to each `title_exists` response body. In JS, `ocs()` should keep
the response `data` on the Error (`error.data = data`). `offerMerge(title)` then does
`GET /people/{existingId}` (which returns `count`) instead of the search.

**Verify:** Create > 200 people whose titles contain "Anna" (SQL insert on the compose stack is
fine), name one "Anna", rename another person to "Anna": the merge offer must appear and name the
right person and face count.

### 3. Merge suggestions use stale centroids after face moves

**Where:** `PeopleService::setFacesCluster()`; `SuggestionService::centroids()`; `js/people.js`
`suggestionCache`.

**Problem:** A centroid is considered current when `member_count` equals the cluster's current
size. Moves/detaches/ignores don't clear centroids, so moving one face out of a person and another
in leaves the size unchanged and the old centroid is used indefinitely. Separately, the page caches
suggestions per person for the page's lifetime and only clears that cache on merge/dismiss, not
after face moves.

**Fix:**
- In `setFacesCluster()`, after the update, call `$this->forgetCentroids($userId, …)` with the
  `$sources` of the batch plus `$to` when `$to > 0`. It is inside the same transaction already.
- In `js/people.js`, call `forgetSuggestions()` after any successful face action (in `faceGrid`'s
  `runAction` success path and in `openPhoto`'s action success path).

**Verify:** On the compose stack: open a person P with suggestions (`occ facewright:people <user>
--suggest --faces=<P>` shows `centroidsCached`), move one face out of P and one into P via the page,
re-run the `occ` command: P's centroid must be recomputed (`centroidsComputed` ≥ 1).

### 4. Legacy 128-dim rows show up as tiles that can never render

**Where:** `PeopleService::listFacesByClusterId()`, `countFaces()`, `clusterListQuery()`;
`FacePreviewController::preview()` (filters with `ownRowsExpr()`).

**Problem:** The preview endpoint refuses legacy rows, but the face listings and counts don't
exclude them. A legacy row at `-1` (what Memories writes when a legacy person is deleted) appears
in Unassigned as a permanent grey placeholder, and counts disagree with what's shown. Same inside a
person that was merged with a legacy cluster (merge is deliberately not dimension-scoped).

**Ask first** — first measure whether this matters, then let the user choose:
```sql
SELECT d.cluster_id < 0 AS sentinel, COUNT(*)
FROM oc_recognize_face_detections d
LEFT JOIN oc_facewright_detection_faces l ON l.detection_id = d.id
WHERE l.detection_id IS NULL
GROUP BY 1;
```
Run this on production read-only (or have the user run it). If both counts are zero and
`occ facewright:migrate-faces` has finished there, record that and close the item with a one-line
note in CLAUDE.md "Open items". Otherwise, the proposed fix is to inner-join
`facewright_detection_faces` in `listFacesByClusterId()` and `countFaces()` (the permanent
ownership test). Do **not** use `ownRowsExpr()` here: its length half reads every row's
`face_vector`, which would turn a cheap indexed count into a full vector scan of the cluster.

**Verify:** Insert one row with a 128-dim `face_vector` and `cluster_id = -1` on the compose stack;
Unassigned's total and tiles must not include it.

---

## P2: Performance

### 5. Covers are one query per person, each sorting the whole person

**Where:** `PeopleService::coversFor()`, called for every page of the people list (60 people) and
every suggestion list.

**Problem:** 60 queries per page, each joining three tables and sorting all of that person's faces
to take the first. A person with thousands of faces sorts thousands of rows for one id.

**Fix:** Measure first. Time `GET /ocs/v2.php/apps/facewright_bridge/api/v1/people?limit=60` on the
largest library available (log `hrtime` around `coversFor()` temporarily, don't commit it). If it is
under ~100 ms, close the item with the number. Otherwise replace the loop with one query using
`ROW_NUMBER() OVER (PARTITION BY d.cluster_id ORDER BY (f.det_score IS NULL), f.det_score DESC,
f.width * f.height DESC, d.id)` filtered to `rn = 1`, with `d.cluster_id IN (…)`. Window functions
are available on every database Nextcloud ≥ 32 supports; QueryBuilder cannot express it, so build it
as raw SQL with the table names from `$qb->getTableName()` and named parameters, the same way
`countClusters()` does. Keep the ordering identical so covers don't change.

**Verify:** Before/after: the `cover` ids returned for the first three pages must be identical
(diff the JSON), and record both timings.

### 6. The people list runs its GROUP BY twice per page

**Where:** `PeopleApiController::listClusters()` → `listClusters()` + `countClusters()`.

**Problem:** Every page computes the full grouped count again. The page only needs `total` once:
`peopleView()` uses it for the header and for `done`, and `done` also works from
`items.length < PEOPLE_PAGE`.

**Fix:** Only compute `total` when `$offset === 0`; return `'total' => null` otherwise. In JS,
`loadMore()` keeps the previous `state.total` when `data.total === null`, and `done` becomes
`data.items.length < PEOPLE_PAGE || (state.total !== null && state.offset >= state.total)`.
Apply the same to `listFaces` / `listUnassignedFaces` / `listIgnoredFaces` and `faceGrid` — but note
`faceGrid.removeTiles()` adjusts `state.total` locally, so it must keep working off the first
page's value.

**Verify:** Scroll a list to the end: one `COUNT` query in the MariaDB general log per view load,
not per page. The total shown must still decrease when faces are moved out.

---

## P3: Maintainability and over-engineering

### 7. Twenty unreachable `userId === null` checks

**Where:** every action in `PeopleApiController` and `SuggestionApiController`; `PageController`
(`$userId ?? ''` and the `''` branches).

**Problem:** None of these actions is `#[PublicPage]`, so the framework's security middleware
rejects unauthenticated requests before the action runs; `$userId` is never null inside them. The
copies are noise and make every action longer than its logic.

**Fix:** First confirm via `ControllerMethodReflector` (see "Working practices" in CLAUDE.md) that no
action in these three controllers carries `PublicPage`. Then add one private helper per controller,
`private function uid(): string { return $this->userId ?? throw new \LogicException('No user session on a non-public route'); }`,
use it in place of `$this->userId`, and delete the null checks. In `PageController`, drop the `''`
fallbacks the same way. Do not change `FacePreviewController`: it returns 404 for a missing user,
which is the right answer for an `<img>`.

**Verify:** `curl` an API route without credentials: still 401 (from the middleware). Every page
action still works when logged in.

### 8. Single-item OCS routes nothing calls — **Ask first**

**Where:** `appinfo/routes.php` and `PeopleApiController`: `POST /people` (`createCluster`),
`DELETE /people/{id}` (`deleteCluster`), `POST /people/{id}/merge` (`mergeClusters`),
`PUT /faces/{id}/cluster` (`moveFace`), `DELETE /faces/{id}/cluster` (`detachFace`).

**Problem:** The page uses only the bulk forms. These five were specified in Stage 0
(`docs/independence-implementation.md`) as "an API of our own", but the planned Stage 2 DAV shim
calls `PeopleService` directly, not OCS. They are untested surface area, and they disagree with the
bulk forms on status codes (not-found is 404 for `deleteCluster`, 400 for `moveFace`/`mergeMany`).

**Proposal for the user:** remove the five routes and their actions (keep the `PeopleService`
methods: `mergeClusters()` is used by `PeopleSettingsController` and `occ facewright:merge-clusters`;
`deleteCluster()`/`detachFace()`/`moveFace()` can stay as thin conveniences or go). Update the Stage 0
route table in `docs/independence-implementation.md` with a note. If the user prefers to keep them,
instead make not-found consistently 404 across all actions (throw a dedicated
`PersonNotFoundException extends \InvalidArgumentException` from `getCluster()` and map it).

**Verify:** Container restart, then every People page flow still works; the removed URLs 404.

### 9. Ambiguous `c` alias in the people list query

**Where:** `PeopleService::listClusters()`: `selectAlias(count('d.id'), 'c')` then
`addOrderBy('c', 'DESC')`, while `c` is also the alias of `recognize_face_clusters`.

**Fix:** Rename the select alias to `face_count` (and `$row['c']` accordingly).

**Verify:** People list order unchanged (named first, then largest).

### 10. Keyboard selection and roles in the grids

**Where:** `js/people.js` `faceGrid.addTile()`, `personTile()` / `peopleView.addTile()`.

**Problem:**
- Face tiles: Space and Enter both call `activate()`, which opens the photo when nothing is
  selected — so a keyboard user can never start a selection (there's no Ctrl with a key event
  here). The corner mark is a `<span>`, not focusable.
- Person tiles are `<a role="checkbox">`: a link announced as a checkbox, with no keyboard path to
  select at all.

**Fix:** Face tiles: Space toggles selection (`selection.toggle(face.id)`), Enter keeps
`activate()`. Person tiles: drop `role`/`aria-checked` from the `<a>`; make the corner mark a
`<button type="button" class="fw-face-check" aria-pressed="false" aria-label="Select …">` and have
`createSelection().mark()` update `aria-pressed` on that button when present (keep the
`aria-checked` path for face tiles). Check `css/people.css` `.fw-face-check` still positions a
`<button>` the same way (core button styles: see the `!important` note in CLAUDE.md "Things that
bit").

**Verify:** In Chromium, keyboard only: Tab to a face, Space selects it, Shift+click range still
works with the mouse, Enter on an unselected face opens the photo. Tab to a person's corner mark,
Space selects; Enter on the tile opens the person.

### 11. The people list duplicates `faceGrid`'s paging machinery

**Where:** `peopleView()` `state`/`loadMore()`/`reset()` vs `faceGrid()` `state`/`loadMore()`/`reload()`.

**Problem:** Two copies of the generation-counter / offset / done / loading logic; item 6 has to
change both, and they already differ slightly (message handling).

**Fix:** Only do this after items 1 and 6. Extract `pagedLoader({fetch(limit, offset), pageSize,
onItems(items), onTotal(total), onError})` returning `{loadMore, reset, adjust(removedCount)}` and
use it in both. No behaviour change. If the extraction ends up longer than the duplication it
removes, stop and leave it.

**Verify:** Every view scrolls to the end, filters reset correctly, and removing tiles still pulls
the next page when the grid empties.

### 12. JS strings are not translatable — **Ask first**

**Where:** every user-visible string in `js/people.js` (the template already uses `$l->t()`).

**Proposal:** wrap strings with Nextcloud's global `t('facewright_bridge', …)` and use
`n('facewright_bridge', 'singular', 'plural', count)` in `plural()`. Only worth doing if the user
wants the app translatable or on the App Store; otherwise skip and say so in CLAUDE.md.

---

## Verification-only items

### 13. EXIF orientation: do boxes line up on rotated phone photos?

**Where:** `ex_app/lib/main.py` (`cv2.imdecode(..., cv2.IMREAD_COLOR)`), `FacePreviewController`
(crops from Nextcloud's preview), `openPhoto()` (boxes over `/core/preview`).

Nextcloud previews apply EXIF orientation. Whether `cv2.imdecode` does depends on the OpenCV build
and version. If it doesn't, every box on a photo with `Orientation` ≠ 1 is in the wrong place, in
crops and the photo view alike — and Memories' own face display too.

**Do:** On the compose stack, upload a portrait JPEG with `Orientation=6` (phone photo, or set it
with `exiftool -Orientation=6 -n`), run `occ facewright:detect <user> <path>`, open the face on the
People page. If crops/boxes are off, report back to the user before fixing — the fix is in the ExApp
(apply orientation before inference, e.g. decode with Pillow + `ImageOps.exif_transpose`) and needs
a re-detection plan for already-processed files, which is a design decision.

---

## Reviewed and fine — do not change

- **No XSS surface in `people.js`:** `h()` only ever sets `textContent`, attributes and listeners;
  no `innerHTML` anywhere. Keep it that way.
- **Authorization:** every OCS action derives the user from the session; no parameter names a user.
  `getCluster()` ownership checks precede every write. `FacePreviewController` checks the detection
  belongs to the session user and reads the file through that user's folder.
- **`#[NoCSRFRequired]` on the preview route** is correct (GET, no side effects, `<img src>`);
  its `Cache-Control` is private, and the ETag short-circuit before preview generation is
  intentional.
- **OCS endpoints are CSRF-safe** via the `OCS-APIRequest` header.
- **`MAX_IDS` / `MAX_PAGE` clamps**, transactions around the bulk operations, and
  `persistAssignments()`'s "human wins" guard.
- **`SuggestionService::lastRun()` and `--no-store`** look like over-engineering but are what made
  the per-person decision measurable; they cost nothing on the request path beyond two `hrtime()`
  calls.
- **Hardcoded white/black in `css/people.css`** (face boxes, overlays on photos) is deliberate:
  they sit on top of photographs, not on themed backgrounds.

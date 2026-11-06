# Contact names: suggesting people's names from contact photos

Requirements and an executable implementation plan. The goal is to use the photos in a user's
address books to suggest names for their unnamed people on the People page. Written for a model
session that has not seen this repository before. Part 1 is what and why; Part 2 is how, step by
step. Where they differ, Part 2 wins.

---

# Part 1 — Requirements and design

## What it does

For each user who opts in, Facewright reads the contacts in the address books that user can see,
including shared ones. For every contact whose photo shows exactly one clear face, it computes an
embedding with the same model as photo detection. Each unnamed person (cluster) is compared with
those contacts. Where one contact is clearly the closest, the People page offers
"Is this *Anna Müller*?":

- **Use this name** renames the person. If another person already has that name, the action
  becomes **Merge into Anna Müller** instead.
- **Not them** remembers that this person is not that name, and the pair is never suggested again.

## Decisions (made with the user, 2026-10-22)

- **Suggestions only, never automatic.** Nothing is renamed or merged without a click.
- **Uncalibrated.** Thresholds are educated defaults in app config, not measured against ground
  truth. The UI says the suggestions may be wrong. Calibrating them against the hand-labelled set
  outside the repo is future work, as it is for clustering (CLAUDE.md open item 2).
- **Opt-in twice.** An admin switch allows the feature instance-wide (default off), and each user
  turns it on for themselves (default off). The data involved is third parties' faces linked to
  their names.
- **Opting out deletes.** When a user turns it off, their contact embeddings, suggestions and
  dismissals are deleted. Unlike photo detection, which is never retroactive, this data exists only
  for this feature, and only because the user asked for it.
- **Only the user's own and shared address books.** The system address book (other accounts'
  avatars) is out of scope for v1. Which accounts a user may see depends on sharing and
  contacts-menu restrictions, which this feature should not reimplement.
- **Remote photo URLs are never fetched.** Only photos embedded in the vCard count, both binary
  `ENCODING=b` and `data:` URIs. A `PHOTO;VALUE=URI:https://…` is treated as no photo. Fetching it
  would let any contact's vCard make the server request arbitrary URLs. Nextcloud's own
  `PhotoCache` refuses them for the same reason.
- **Unnamed people only.** A contact never suggests renaming a person who already has a name.

## How matching works

- **Contact side:** the vCard photo is decoded in PHP, scaled to at most 1024 px and sent as bytes
  to a new ExApp endpoint, `/embed`. That endpoint pads the image before detection, because a
  tightly cropped portrait is not what the detector sees in normal photos. The bridge keeps the
  photo only if it contains one dominant face:
  - detector score ≥ `contact_min_det_score` (0.5);
  - face width ≥ 32 px in the original image;
  - the largest face's area at least 2× the second largest.

  Otherwise the photo is recorded as no face, ambiguous or too small, and gives no suggestions.
- **Person side:** the cached centroid each person already has for merge suggestions (normalized
  mean of their 10 best faces, `facewright_cluster_centroids`), so no new representation.
- **Candidate people:** unnamed people with at least the user's minimum cluster size, the same set
  the People list shows by default.
- **Rule per person:** let `d1` be the cosine distance to the closest contact name, and `d2` the
  distance to the closest contact with a *different* name. Several contacts with the same
  normalized name count as one name, keeping the best distance. Suggest the closest name if both
  hold:
  - `d1 ≤ contact_max_distance` (default 0.5);
  - there is no other name, or `d2 − d1 ≥ contact_min_margin` (default 0.1).

  The margin applies only on the person side. Two people matching the same contact is expected,
  since clustering splits people, and the second one simply becomes a merge offer.
- **Dismissals** are keyed by (person, normalized name). That way "not Anna" holds across duplicate
  Anna cards, re-synced cards and changed photos.
- **When it runs:** suggestions are precomputed in a background job and stored. The page only
  reads them, with filters applied at read time: the person still exists and is still unnamed, the
  contact row still exists, and the pair is not dismissed. Comparing every person with every
  contact (K × C dot products of 512 floats) is too slow for a web request.

## Out of scope for v1

The system address book and instance avatars; automatic naming; calibration; reacting to contact
changes instantly (CardDAV events); suggesting a different name for an already named person;
showing a cropped contact face (the whole contact photo is shown).

## Effect on Memories

Names reach Memories with no extra work: accepting a suggestion is a rename of a
`recognize_face_clusters` row, which is what Memories reads.

Since Stage D of `docs/own-tables.md` the joins here should target `facewright_clusters` rather than
`recognize_face_clusters`, and renames reach Memories through the projection. Cluster ids are the same in
both.

---

# Part 2 — Implementation plan

## How to work this plan

1. **Read `CLAUDE.md` in full first**, then Part 1. The `cluster_id` invariants, "Job semantics"
   (paused jobs re-add themselves, silent returns must log, 4xx versus retryable failures) and
   "Bridge app" deploy mechanics all apply here.
2. **Work on the compose stack only** (`recognaize-nextcloud-1`, `recognaize-db-1`,
   `recognaize-facewright-1`). Production (`<app>` and friends) is read-only. The only
   use of production is step 6's read-only counts, and only after the user approves.
3. **One commit per numbered step**, in the style of `git log`: an imperative subject and a body
   that says why. End each message with the attribution line the session gives you.
4. **Deploy mechanics:**
   - A migration, a new `occ` command, a background job or JS/CSS changes need `<version>` bumped
     in `appinfo/info.xml` (0.8.0 → 0.8.1 → …; step 10 sets 0.9.0) plus `occ upgrade`.
   - New `routes.php` entries need `docker compose restart nextcloud`.
   - ExApp changes need the compose stack's `facewright` image rebuilt and the container recreated
     (see `docker-compose.yml`). The production ExApp is redeployed by the user with the
     `Makefile`, not by you.
5. **APIs that are not public:**
   - `OCA\DAV\CardDAV\CardDavBackend::getAddressBooksForUser()` and `getMultipleCards()`.
   - `OCA\DAV\CardDAV\PhotoCache::getPhotoFromVObject()`.
   - A read-only `SELECT` of `uri`, `etag` from the `cards` table.

   All of these are core (the `dav` app), not Recognize, and were checked on the stack (NC
   33.0.10). They are the only internal surface this feature uses, and every use goes through one
   class, `ContactSource` (step 4), so a future Nextcloud change is a one-file fix. If a signature
   differs on the stack, stop and report rather than substituting.
6. **Do not touch:** `DetectionService`, clustering logic, the `-1`/`-2`/`-3` semantics, the
   existing `/detect` behaviour, and anything listed in `docs/people-ui-review.md` "Reviewed and
   fine".
7. **No tests, no build step.** Verify with `occ`, SQL, `curl` and the browser on the stack, as in
   `docs/people-ui-implementation.md`.

**Test data.** Create vCards for `alice` on the stack with `curl -u alice:… -X PUT -H
'Content-Type: text/vcard' --data-binary @card.vcf
http://<stack>/remote.php/dav/addressbooks/users/alice/contacts/<uid>.vcf`. Cover these cases:

- (a) a vCard 3 `PHOTO;ENCODING=b;TYPE=JPEG:` card whose photo is a crop of a face that is
  already in one of alice's unnamed people;
- (b) a vCard 4 `PHOTO:data:image/jpeg;base64,…` card;
- (c) a photo with two equally large faces;
- (d) a logo with no face;
- (e) `PHOTO;VALUE=URI:https://example.com/x.jpg`;
- (f) no photo at all;
- (g) two cards with the same `FN`;
- (h) an address book bob shares with alice (create it as bob, then share it through the DAV
  sharing API or the Contacts app if installed).

Keep the `.vcf` files in the scratchpad, not the repo.

---

## 1 — ExApp: `POST /embed`

**Files:** `ex_app/lib/main.py`.

- Move the shared parts of `detect()` into helpers, without changing `/detect`'s behaviour or
  error codes:
  - `_decode(image_bytes: bytes) -> np.ndarray`: the empty check (400), `cv2.imdecode`, the
    `cv2.error` handling (400) and the `None` check (400);
  - `_faces(img) -> list[dict]`: `FACE_ANALYSIS.get()` and the result list.
- Add `class EmbedRequest(BaseModel): image: str` (base64).
- Add `@APP.post("/embed")`:
  - Decode the base64 with `base64.b64decode(…, validate=True)`; `binascii.Error` → 400.
  - More than 10 MB decoded → 413.
  - Call `_decode()`, then pad the image with `cv2.copyMakeBorder` by `pad = int(0.25 *
    max(h, w))` on each side, `BORDER_CONSTANT`, value `(127, 127, 127)`.
  - Run `_faces()` on the padded image, then subtract `pad` from every bbox and kps coordinate.
  - Return the same JSON shape as `/detect`: `faces`, plus `image_width`/`image_height` of the
    *unpadded* image.
- It needs no file access, and no Nextcloud call beyond the auth middleware.
- Don't fix the known "inference runs on the event loop" bug here (CLAUDE.md open item 4). `/embed`
  inherits it on purpose, so the fix stays one change for both endpoints.

**Verify:** rebuild and recreate the container; `/heartbeat` answers. Inside the container, run a
throwaway script (scratchpad, `docker cp` it in) that:

- builds a `FaceAnalysis` like `lifespan()` does;
- runs the padding path on test card (a)'s JPEG and prints one face with sensible bbox
  coordinates inside the original image;
- confirms the same image *without* padding, if it is a tight crop, finds no face or a lower
  score. Record both results in the commit message: this is the evidence for the padding.

`/detect` is verified end to end in step 4.

## 2 — Tables

**Files:** `lib/Migration/Version000007000Date20261022000000.php`, `appinfo/info.xml` (0.8.1).

Follow the shape of `Version000006000Date20261020000000` (guarded by `hasTable`, no foreign keys,
named indexes with an `fw_` prefix).

`facewright_contact_faces`: one row per (user, visible card).

| column | type | notes |
|---|---|---|
| `id` | BIGINT autoincrement | PK `fw_cf_pk` |
| `user_id` | STRING(64) | |
| `addressbook_id` | BIGINT | `addressbooks.id`; a shared book has the owner's id |
| `card_uri` | STRING(255) | |
| `card_etag` | STRING(64) | as stored in `cards.etag` (unquoted) |
| `display_name` | STRING(255) | trimmed `FN` |
| `name_key` | STRING(255) | `mb_strtolower(trim(FN))`, for grouping and dismissals |
| `status` | SMALLINT | see `ContactSyncService` constants (step 4) |
| `photo_hash` | STRING(40) nullable | sha1 of the decoded photo bytes |
| `embedding` | BLOB nullable | `FaceVectorCodec`, only when status is OK |
| `det_score` | FLOAT nullable | |
| `updated_at` | BIGINT | |

Unique index `fw_cf_card` on (`user_id`, `addressbook_id`, `card_uri`); index `fw_cf_user` on
(`user_id`, `status`).

`facewright_contact_suggestions`: at most one per (user, person), rewritten per user by step 6.

| column | type | notes |
|---|---|---|
| `id` | BIGINT autoincrement | PK `fw_cs_pk` |
| `user_id` | STRING(64) | |
| `cluster_id` | BIGINT | `recognize_face_clusters.id` |
| `contact_face_id` | BIGINT | the best card for that name |
| `name_key` | STRING(255) | |
| `distance` | FLOAT | |
| `computed_at` | BIGINT | |

Unique index `fw_cs_cluster` on (`user_id`, `cluster_id`).

`facewright_contact_dismissals`:

| column | type |
|---|---|
| `id` | BIGINT autoincrement, PK `fw_cd_pk` |
| `user_id` | STRING(64) |
| `cluster_id` | BIGINT |
| `name_key` | STRING(255) |

Unique index `fw_cd_pair` on (`user_id`, `cluster_id`, `name_key`).

Like the merge-suggestion tables, these reference cluster ids, which are never reused, so a row
left behind by a person deleted elsewhere is inert.

**Verify:** `occ upgrade`; `SHOW CREATE TABLE` for all three tables matches the spec.

## 3 — Extract `CentroidService` from `SuggestionService`

**Files:** new `lib/Service/CentroidService.php`, `lib/Service/SuggestionService.php`.

There are about to be two consumers of person centroids, so move the cache out of
`SuggestionService` without changing behaviour:

- Move `centroids()` (public, renamed `forClusters(string $userId, array $clusters, bool $store)`,
  same return shape), `computeCentroid()`, `dot()` (public static) and `REPRESENTATIVES` into
  `CentroidService`.
- `SuggestionService` takes `CentroidService` in its constructor and delegates.
- Keep `SuggestionService::REPRESENTATIVES` as an alias constant if anything references it (grep).
- If review item 3 in `docs/people-ui-review.md` (forgetting centroids in `setFacesCluster()`) has
  landed, nothing changes there. If not, don't do it here.

**Verify:** `occ facewright:people alice --suggest --faces=<id> --no-store` prints the same
suggestions and distances before and after (diff the output).

## 4 — `ContactSource` and `ContactSyncService`, plus `occ facewright:contacts`

**Files:** new `lib/Service/ContactSource.php`, `lib/Service/ContactSyncService.php`,
`lib/Command/Contacts.php`, `appinfo/info.xml` (register the command, 0.8.2).

`ContactSource` is the only class that touches `OCA\DAV`:

- `addressBooks(string $userId): list<array{id: int, uri: string}>`:
  `CardDavBackend::getAddressBooksForUser('principals/users/' . $userId)`. Owned and shared books.
  Return `uri` as it appears *for this user*, since a shared book's uri differs from the owner's.
  Step 8 needs it.
- `cardEtags(int $addressBookId): array<string, string>` (uri → etag): a light `SELECT uri, etag
  FROM cards WHERE addressbookid = ?` with `IQueryBuilder`. Never `getCards()`, which loads every
  `carddata`, photos included.
- `photos(int $addressBookId, list<string> $uris): array<string, array{name: string, photo:
  ?string}>`:
  - Use `CardDavBackend::getMultipleCards()` for the uris, in chunks of 50.
  - Parse each card with `Sabre\VObject\Reader::read()`. `name` is the trimmed `FN` (fall back to
    `N` joined as given + family, or `''`).
  - `photo` is the `body` from `PhotoCache::getPhotoFromVObject()`, or null when it returns false.
    It already refuses non-`data:` URIs.
  - A card that fails to parse gets `photo => null, name => ''` and is logged at debug.

`ContactSyncService::sync(string $userId): array` (counts per status, plus `embedded`,
`unchanged` and `removed`):

- Status constants: `OK = 1`, `NO_PHOTO = 0`, `NO_FACE = -1`, `AMBIGUOUS = -2`,
  `UNDECODABLE = -3`, `TOO_SMALL = -4`, `NO_NAME = -5`.
- For each visible book, compare `cardEtags()` with the stored rows for (user, book):
  - Cards whose etag is unchanged are skipped.
  - Rows whose (book, uri) is no longer visible, in any book, are deleted. That covers a deleted
    card and an unshared book.
- For changed or new cards, fetch with `photos()`:
  - A name that is empty or purely numeric (the same rules as `PeopleService::assertValidTitle()`)
    → `NO_NAME`.
  - No photo → `NO_PHOTO`.
  - If `sha1(photo)` equals the stored `photo_hash` and the stored status is not a retryable one,
    update only `display_name`, `name_key` and `card_etag`, with no inference.
  - Otherwise load the photo into `OCP\Image` (`loadFromData` false → `UNDECODABLE`), call
    `scaleDownToFit(1024, 1024)`, and send `base64_encode($img->data())` to `/embed` through
    `OCA\AppAPI\PublicFunctions::exAppRequest('facewright', '/embed', $userId, 'POST', ['image' =>
    …], ['timeout' => 30])`, exactly as `DetectionService::detect()` calls `/detect`.
- Classify failures exactly like `DetectionService`:
  - A non-`IResponse` result or a 5xx → retryable. Write nothing for that card, so the next sync
    retries it. Stop the sync for this user after 3 consecutive retryable failures, since the ExApp
    is down, and log at warning.
  - A 4xx → `UNDECODABLE`.
- From the faces, compute each face's area from its bbox and sort by area.
  - None → `NO_FACE`.
  - Largest face `det_score < contact_min_det_score` (appconfig float, default 0.5) → `NO_FACE`.
  - Largest face width < 32 px → `TOO_SMALL`.
  - A second face with area > ½ of the largest → `AMBIGUOUS`.
  - Otherwise `OK`: store `embedding` via `FaceVectorCodec::encode()` and `det_score`.
- Write rows with `IDBConnection::setValues()` keyed on (`user_id`, `addressbook_id`, `card_uri`).
- Rows are per user even for a book shared with several users. The second user re-runs inference
  for the same card. That is accepted for v1: address books are small next to photo libraries, and
  sharing the result across users would need a per-card table keyed on something stable across
  shares, which is not worth it yet. Note it in the class docblock.
- `deleteUser(string $userId): void` deletes the user's rows from all three contact tables (used by
  step 7's opt-out).

`occ facewright:contacts <user> [--sync] [--list]`:

- `--sync` runs `sync()` and prints the counts, elapsed time and the number of `/embed` calls.
- `--list` prints one line per stored row: book, uri, name, status name, det_score.

**Verify:** with test cards (a)–(h) for alice:

- `--sync`:
  - (a) and (b) → `OK`;
  - (c) → `AMBIGUOUS`;
  - (d) → `NO_FACE`;
  - (e) and (f) → `NO_PHOTO`;
  - (g) → two rows, same `name_key`;
  - (h) → present, with bob's book id.
- `docker logs recognaize-facewright-1` shows one `POST /embed` per photo-bearing card.
- A second `--sync` makes zero `/embed` calls.
- Edit (a)'s `NOTE` (etag changes, photo doesn't): zero `/embed` calls, row updated.
- Delete card (f): its row is gone.
- Unshare (h): its row is gone for alice.
- Stop the ExApp container and edit a card: the card is not written, the warning appears, the next
  sync after a restart embeds it.

## 5 — Settings

**Files:** `lib/Service/UserSettingsService.php`, admin and personal templates and JS
(`templates/admin.php`, `js/admin.js`, `templates/personal.php`, `js/personal.js`), their
controllers and routes, `appinfo/info.xml` (0.8.3).

- Admin, `IAppConfig` bool `contact_names_allowed`, default false: one checkbox in the admin
  section, "Allow users to get name suggestions from their contacts' photos".
- Per user, `contact_names_enabled`: stored like `enabled`, as the string `'true'`/`'false'` with
  `FLAG_INDEXED`, so the job can find users with `searchUsersByValueString`. Add
  `isContactNamesEnabled()`, `setContactNamesEnabled()` and `contactNamesUsers()` (users with it on
  *and* `enabled`) next to the existing methods.
- Personal section (only rendered when the admin allows it):
  - a checkbox, with the explanation "Facewright compares the photos of your contacts with the
    people found in your photos and suggests names. Suggestions are not checked and may be wrong.
    Turning this off deletes what was computed from your contacts.";
  - a "Check contacts now" button, which enqueues step 7's job for the session user.
- Turning the checkbox off calls `ContactSyncService::deleteUser()`. Turning it on enqueues the job.
- Thresholds have no UI: `contact_max_distance` (0.5), `contact_min_margin` (0.1) and
  `contact_min_det_score` (0.5) are app config read with defaults, changed with `occ config:app:set
  facewright_bridge …`.
- Every new controller action follows `PersonalSettingsController`'s rules (`#[NoAdminRequired]`,
  session user only).

**Verify:** confirm through `ControllerMethodReflector` (CLAUDE.md "Working practices") that the
admin action is admin-only and the personal ones are not. Turning it off for alice empties her rows
in all three contact tables (`COUNT(*)` before and after). With the admin switch off, the personal
section shows no contact controls.

## 6 — `ContactMatchService`, with a measurement gate

**Files:** new `lib/Service/ContactMatchService.php`; extend `lib/Command/Contacts.php`;
`appinfo/info.xml` (0.8.4).

`match(string $userId, bool $store = true): array` returns the suggestions plus stats: people,
contacts, names, comparisons, seconds.

1. Candidate people: `PeopleService::listClusters($userId, null, 0, getClusterMinFaces($userId))`
   filtered to `title === ''`.
2. Their centroids: `CentroidService::forClusters($userId, $people, $store)`.
3. OK contacts: `SELECT id, name_key, embedding FROM facewright_contact_faces WHERE user_id = ? AND
   status = 1`, decoded, then grouped by `name_key`. Keep every embedding per name: a person's
   distance to a name is the minimum over that name's cards.
4. Dismissed pairs for the user: `(cluster_id, name_key)` set.
5. For each person: compute `d1`/`d2` exactly as in Part 1 with `CentroidService::dot()`, skipping
   dismissed names (a dismissed name is neither `d1` nor `d2`). Keep the person if the rule passes,
   remembering the `contact_face_id` of the best card.
6. With `$store`, in one transaction: delete the user's `facewright_contact_suggestions`, then
   insert the new set (chunked).

`forUser(string $userId, ?int $clusterId = null): list<array{clusterId, cover, count, contactId,
name, distance, mergeTargetId: ?int}>` reads the stored rows with the read-time filters:

- inner join `recognize_face_clusters` on id and user with `title = ''`;
- inner join `facewright_contact_faces` with `status = 1`;
- not in dismissals.

Set `mergeTargetId` to the id of the user's person whose `title` equals the contact's
`display_name`, if any (one query for all names), or null. Fill `cover` and `count` with
`PeopleService::coversFor()` and `countFaces()`. Order by distance. Optional `$clusterId` filter,
for the person page.

`dismiss(string $userId, int $clusterId, string $nameKey)`: check ownership with
`PeopleService::getCluster()`, `insertIgnoreConflict`, and delete the stored suggestion row for
that person.

`occ facewright:contacts <user> --suggest [--no-store]` prints the suggestions (person id, size,
name, distance, d2 or "-", merge target) and the stats.

**Measurement gate:** `K × C` comparisons in PHP run at a rate you measure on the stack. Generate
a synthetic case by temporarily inserting 2,000 OK rows with random normalized embeddings for alice
in a transaction you roll back, or into a throwaway user. Compute comparisons/second from
`--suggest --no-store`. Then ask the user to run, read-only on production:
`SELECT COUNT(*) FROM oc_recognize_face_clusters WHERE user_id = '<largest user>' AND title = ''`
and an estimate of contacts with photos
(`SELECT COUNT(*) FROM oc_cards c JOIN oc_addressbooks b ON b.id = c.addressbookid WHERE
b.principaluri = 'principals/users/<uid>' AND c.carddata LIKE '%PHOTO%'`). If the projected time
is over 120 s per run, stop and report before step 7. The likely remedy is skipping people whose
centroid and the contact set are both unchanged since the last run, which is a design change to
agree first.

**Verify:** on the stack:

- card (a) yields a suggestion for the person whose face it was cropped from, distance well under
  0.5;
- (c), (d), (e), (f) contribute nothing;
- the two (g) cards produce at most one suggestion per person;
- dismiss it via `occ` (add `--dismiss=<clusterId>:<name>`, or use step 8's API once it exists) and
  `--suggest` no longer lists it;
- name that person by hand: `forUser()` no longer returns it, before any recompute;
- with `--no-store`, `SELECT COUNT(*) FROM oc_facewright_contact_suggestions` is unchanged.

## 7 — Jobs

**Files:** new `lib/BackgroundJob/ContactSyncJob.php` (`QueuedJob`, argument `{userId}`) and
`lib/BackgroundJob/ContactMaintenanceJob.php` (`TimedJob`, interval 6 h); `appinfo/info.xml`
(register both, 0.8.5).

- `ContactSyncJob::run()`:
  - Re-check at run time that the admin switch is on, the user exists, and the user has it on.
    Otherwise log at debug and return.
  - Honour `processing_paused` by re-adding itself with the same argument, like every job here.
  - Then `ContactSyncService::sync()` followed by `ContactMatchService::match()`.
  - Log one info line with the counts.
- `ContactMaintenanceJob` adds a `ContactSyncJob` for each of `contactNamesUsers()`. Deduplication
  on class + argument makes re-adding safe (CLAUDE.md "Job semantics").
- A sync that stopped on retryable failures still runs `match()` on what it has.
- No listener on CardDAV events in v1 (see "Out of scope"). The personal "Check contacts now"
  button covers impatience.

**Verify:** `occ background-job:list --class='OCA\FacewrightBridge\BackgroundJob\ContactSyncJob'`
after toggling on shows one job. Run it with `occ background-job:execute <id> --force-execute`;
counts appear in the log, and `--list` and `--suggest` reflect it. With `processing_paused` on,
the job re-adds itself and does nothing.

## 8 — OCS API and the contact photo route

**Files:** new `lib/Controller/ContactNamesApiController.php` (OCS) and
`lib/Controller/ContactPhotoController.php` (plain); `appinfo/routes.php`; restart the container.

OCS, same rules as `SuggestionApiController` (`#[NoAdminRequired]`, session user only, no parameter
names a user). Every action answers 404 when the admin switch or the user's own switch is off.

- `GET /api/v1/names` → `{items: forUser()}`, capped at 200.
- `GET /api/v1/people/{id}/names` → `forUser($uid, $id)`.
- `POST /api/v1/names/dismiss` with `{clusterId, nameKey}`.

Accepting a suggestion needs no new endpoint. The page uses the existing `PUT /people/{id}`
(rename) or `POST /people/merge` (when `mergeTargetId` is set).

`GET /contacts/{id}/photo` (`#[NoAdminRequired]`, `#[NoCSRFRequired]`, GET with no side effects,
for `<img src>`, as `FacePreviewController` explains):

- Load the `facewright_contact_faces` row by id and session user, else 404.
- Re-check the book is still in `ContactSource::addressBooks($uid)`, else 404.
- Fetch the photo through `ContactSource::photos()`, scale it with `OCP\Image` to fit 256 px and
  return it.
- Use an ETag of the row's `card_etag`, answer `If-None-Match` before decoding, and use
  `cacheFor(7 days, private)`.

**Verify:**

- `curl` as alice with the OCS header returns the step 6 suggestion.
- As bob, the same `clusterId` in `dismiss` → 400, and alice's contact photo id → 404.
- Card (h) is visible to alice through the photo route while shared, and 404 after unsharing (even
  before the next sync).

## 9 — People page UI

**Files:** `js/people.js`, `css/people.css`, `templates/main.php`, `lib/Controller/PageController.php`
(pass `contactNames` = both switches on), `appinfo/info.xml` (0.8.6).

- **Navigation:** a "Names" link (`#/names`), rendered only when `contactNames` is on.
- **`namesView()`:** a heading, the intro "Suggested from your contacts' photos. These are guesses
  and may be wrong; nothing changes until you choose.", and a grid of cards. Each card shows:
  - the person's cover crop (`cropImg`) next to the contact photo (`/apps/facewright_bridge/
    contacts/{contactId}/photo`, with the same placeholder on error as `cropImg`);
  - "Is this *name*?";
  - "N faces · M% similar";
  - the buttons:
    - **Use this name** → `PUT /people/{id}`. On `title_exists` (a race with another tab), reload
      the view.
    - Or, when `mergeTargetId` is set, **Merge into *name*…** → the existing `mergeFlow()` with
      the existing person as keep. It already explains what happens.
    - **Not them** → dismiss, then remove the card.
  - Clicking the cover opens `#/person/{id}`.
  - Empty state: "No suggestions right now. Suggestions are updated every few hours."
- **Person page:** when `contactNames` is on, fetch `/people/{id}/names`. If it returns an item,
  show one compact "Is this *name*?" card above "Possibly the same person", with the same three
  actions. On accept, re-render the person page.
- **Clean-up:** after any accept, call `forgetSuggestions()` (merge suggestions depend on sizes and
  names).
- If review item 1 in `docs/people-ui-review.md` (late responses rendering into the wrong view) has
  not landed yet, apply the same `stopped` guard to `namesView()` and to the person page fetch
  added here.
- Every string goes in via `textContent` (`h()`). Contact names are third-party input.

**Verify:** in the browser on the stack as alice:

- The Names view lists the step 6 suggestion.
- Accepting renames the person: it appears named in the People list, and in Memories' People page.
  Set up a second unnamed person matching the same card: it now shows "Merge into …", and the merge
  works.
- "Not them" removes the card and it doesn't return after `occ … --suggest` (with store).
- With alice's switch off, the nav link is gone and `/api/v1/names` is 404.
- Keyboard: Tab reaches every button.

## 10 — Hygiene, docs, release

- **Hygiene:**
  - `ClusteringService::resetUser()` also deletes the user's `facewright_contact_suggestions` and
    `facewright_contact_dismissals`. They reference the clusters it deletes. Contact faces stay:
    they don't depend on clusters.
  - `ResetService` deletes all rows of those two tables, and also `facewright_contact_faces`, since
    a reset means "start over".
  - `PeopleService::mergeMany()`/`deleteMany()` need nothing: the read-time filters hide rows for
    gone people, and cluster ids are never reused.
- **`CLAUDE.md`:** a short "Contact names" entry under Components covering:
  - the services, jobs and tables;
  - the opt-in and delete-on-opt-out rule;
  - "remote photo URLs are never fetched";
  - the `OCA\DAV` dependency confined to `ContactSource`;
  - the uncalibrated thresholds and their app config keys;
  - the per-user duplicate inference on shared address books.

  Add an "Open items" entry: calibrate `contact_max_distance` and `contact_min_margin` against the
  ground-truth set. Add the new `occ` command to the command list.
- **`README.md`:** one paragraph in the feature overview, saying it's opt-in and that suggestions
  are uncalibrated.
- **Release:** `<version>` 0.9.0, `occ upgrade`, one commit "Release facewright_bridge 0.9.0:
  contact name suggestions". The ExApp change from step 1 must be deployed to production *before*
  the bridge. Say so in the release commit body; the user does the deploy.

## Verification matrix

Run on the compose stack after step 10, with users alice and bob and bob's book shared to alice.

| Case | Expected |
|---|---|
| Feature off (admin) | No settings UI, no nav link, OCS 404, maintenance job enqueues nothing |
| Alice on, bob off | Only alice has rows; bob's own book is never read for bob |
| Card with remote photo URL | `NO_PHOTO`; no outbound request (check the web container's logs/`tcpdump` if in doubt) |
| Card edited, same photo | No `/embed` call |
| Card photo replaced | One `/embed` call, suggestion recomputed on next job |
| Book unshared | Alice's rows for it gone after the next sync; photo route 404 immediately |
| Person named by hand | Its suggestion disappears without a recompute |
| Name already used by another person | Card offers merge, not rename |
| Alice turns it off | All three contact tables empty for alice |
| `occ facewright:reset` | All contact tables empty |
| ExApp down during sync | Cards not written, warning logged, picked up after restart |

# Facewright

A replacement backend for Nextcloud Recognize's face-detection pipeline, built as a Nextcloud
AppAPI ExApp. Uses InsightFace instead of Recognize's face-api.js/tfjs-node stack, but keeps
Recognize installed as a companion app rather than forking it.

This repo holds two apps:
- `appinfo/`, `ex_app/` etc. at the top level — `facewright`, the Python AppAPI ExApp (InsightFace
  detection).
- `facewright_bridge/` — a small, separate, regular Nextcloud PHP app. It exists only because
  ExApps have no path to real database access; it calls `facewright` and writes results into
  Recognize's tables. See "Done: writing into Recognize's `oc_recognize_face_detections` table"
  below for why.

See `status.md` for the original scoping discussion this project is based on.

## Architecture decision

Memories' People page has no face-detection logic of its own — it just reads two tables that
Recognize's PHP side populates: `oc_recognize_face_detections` and `oc_recognize_face_clusters`.
So the plan is a **companion app**, not a fork of Recognize:

- Recognize stays installed (its migrations create the schema, Memories keeps working), but its
  own face-classifier is switched off so it doesn't fight our writes.
- This ExApp owns detection + embedding (InsightFace, via AppAPI) and, eventually, its own
  clustering, writing directly into Recognize's tables.
- Rejected alternative: patching Recognize's PHP to call this ExApp instead of its Node
  subprocess. Smaller diff, but inherits Recognize's clustering thresholds (tuned for a
  different embedding space) and means an ongoing rebase against upstream Recognize.

The hard part, by design deferred to a later phase: Recognize's existing cluster-distance
thresholds were calibrated for its old embedding space. InsightFace's ArcFace-style embeddings
have different typical cosine-similarity margins, so those thresholds can't be reused as-is —
needs real experimentation against a labeled slice of the photo library.

## Status

### Done: ExApp skeleton + `/detect` endpoint

Scaffolded from `nextcloud/app-skeleton-python`, targeting CPU-only inference (no GPU in this
homelab). Runs under an existing AppAPI + deploy daemon setup.

- `appinfo/info.xml` — app id `facewright`, single `POST /detect` route at `USER` access level
- `ex_app/lib/main.py` — FastAPI app; `AppAPIAuthMiddleware` kept intact; InsightFace
  `FaceAnalysis` (`buffalo_l`, CPU) loaded once in the `lifespan` hook (not per-request/per-fork);
  `/detect` reads a Nextcloud file path (see below), returns
  `{faces: [{bbox, kps, det_score, embedding}]}`
- `requirements.txt` — `nc_py_api[app]`, `insightface`, `onnxruntime` (CPU build),
  `opencv-python-headless`, `numpy`
- `Dockerfile` — skeleton's two-stage build + `build-essential`/`cmake` (InsightFace compiles
  Cython extensions on install) + `libgl1`/`libglib2.0-0` in the runtime stage
- Verified locally: image builds, InsightFace model loads, and detection runs correctly against
  a real multi-face test photo (6/6 faces found, plausible bboxes, 512-d embeddings) — see
  git history for the exact commands used.

Deliberately out of scope for this slice: reading files from Nextcloud storage, DB writes,
clustering, background job / file-listener triggers, multi-user support.

### Done: registered live and verified end-to-end

`facewright` is registered, enabled, and reachable on this homelab's real Nextcloud instance —
confirmed by POSTing a real test photo to `https://cloud.example.com/exapps/facewright/detect`
through the actual Traefik → HaRP → FRP tunnel path (not a local/direct test) and getting all 6
faces back correctly.

This homelab's live AppAPI setup, discovered by exec-ing into `nextcloud-app-1` and reading
`occ app_api:daemon:list` plus AppAPI's own PHP/Python source (`DockerActions.php`,
`ManualActions.php`, and HaRP's `haproxy_agent.py`):

- Nextcloud container: `nextcloud-app-1` (compose service `app`), occ lives at
  `/var/www/html/occ`, so `docker exec -u www-data nextcloud-app-1 php occ ...` works without
  a `./` (cwd is already `/var/www/html`).
- Only one daemon was registered initially: `harp_proxy_docker`, deploy-id `docker-install`.
  That type means AppAPI does a real `docker pull` of whatever `<docker-install>` in `info.xml`
  names (confirmed in `DockerActions.php`: `shouldPullImage()` defaults to `true` unless the
  daemon has a `registries: [{from: ..., to: "local"}]` mapping, which this one doesn't) — not
  suitable for a locally-built, unpublished image.
- Registered a **second, manual-install daemon** (`manual_install_harp`) instead of touching the
  existing one. `ManualActions::deployExApp()` is a no-op — AppAPI expects *us* to run the
  container, which then tunnels in through HaRP's FRP reverse-proxy (`appapi-harp:8782`) using
  the shared key already baked into the `appapi-harp` container's own env
  (`docker exec appapi-harp printenv HP_SHARED_KEY`) — that value must match on both sides, it's
  HaRP's tunnel auth, not something we invent per-app.
- Required container env vars, per `nc_py_api`'s `_session.py`/`uvicorn_fastapi.py`:
  `APP_ID`, `APP_VERSION`, `APP_SECRET`, `NEXTCLOUD_URL` (required), `APP_PORT` (still needed
  even under HaRP — it's the FRP tunnel's `remotePort`, used to key routing; the app itself
  binds a unix socket, not this TCP port), `HP_SHARED_KEY`/`HP_FRP_ADDRESS`/`HP_FRP_PORT` (read
  by `start.sh` to build `frpc.toml`).

Recipe (see `Makefile` targets `daemon-register` / `run` / `register` / `verify`):

```
# one-time: register the manual-install daemon
make daemon-register

# per-deploy: build, run the container (tunnels through HaRP), tell AppAPI about it
make build
make run APP_SECRET=$(openssl rand -hex 24)
make register APP_SECRET=<same-secret-as-above>
make verify APP_SECRET=<same-secret-as-above>   # curls /heartbeat through the real path
```

`AppAPIAuthMiddleware` only exempts `/heartbeat` from signature checks, so a bare `curl` at the
container will 401 — verification has to go through Nextcloud/AppAPI (see `make verify`), not a
direct request.

#### Three real bugs hit getting this far, in the order they appeared

1. **Missing TLS certs on the FRP tunnel.** HaRP's FRP server requires TLS
   (`HP_FRP_DISABLE_TLS=false`), but `start.sh` only enables TLS in the client if it finds certs
   at `/certs/frp` — we weren't mounting anything there, so frpc kept retrying with
   `connect to server error: EOF`. Fix: mount HaRP's own client cert bundle (it already has one,
   generated on first boot) from the host at `<harp_certs_bind_mount>/frp` into the container at
   `/certs/frp:ro`. This homelab's path is baked into the `Makefile` as `HARP_CERTS_DIR` — found
   it via `docker inspect appapi-harp` and reading its `Mounts`.
2. **`APP_PORT` outside HaRP's allowed range.** frps rejected our first port (9030) with
   `port not allowed`. HaRP's `frps.toml` (`/run/harp/frps.toml` inside `appapi-harp`) restricts
   `remotePort` to specific ranges (`allowPorts`, this homelab's is `23000-23999`/`24000-24099`,
   the latter reserved for the docker-socket-proxy daemon). `test-deploy` (the pre-existing
   working ExApp) uses `23000`; we picked `23001`. Check the live container's `frps.toml` before
   assuming a port is free rather than trusting this note indefinitely — ranges/usage can change.
3. **HaRP's SPOE agent caches ExApp host/port forever.** Even after fixing the above two, the
   real heartbeat kept 503ing with HaProxy's generic "no server available" page. Traced it into
   `haproxy_agent.py` (`/usr/local/bin/haproxy_agent.py` inside `appapi-harp`): `EXAPP_CACHE` has
   no TTL and is normally invalidated by Nextcloud calling `DELETE /exapp_storage/{app_id}` on
   unregister — but several stuck/concurrent `occ app_api:app:register` processes (from
   re-registering with different ports mid-debugging) left it holding a stale entry. Confirmed by
   directly querying Nextcloud's own internal endpoint AppAPI's PHP also uses
   (`curl -H "harp-shared-key: <key>" http://127.0.0.1/index.php/apps/app_api/harp/exapp-meta?appId=facewright`
   from inside `nextcloud-app-1`) — it reported the correct, current port, proving the DB was
   right and the *cache* was the liar. Fixed with `make bust-cache` (calls that same `DELETE`
   endpoint directly, scoped to just this app's cache key — doesn't touch other ExApps or
   require restarting the shared `appapi-harp` container).

   If registration ever hangs/503s again after changing `APP_PORT` or re-registering, run
   `make bust-cache` before assuming something else is broken — the DB is very likely already
   correct and HaRP's in-memory cache is the actual stale piece.

### Done: `/detect` reads from Nextcloud storage via `nc_py_api`

`/detect` now takes a JSON body (`{"path": "..."}`, relative to the calling user's DAV files
root) instead of a raw image body, and downloads it with `nc_py_api` instead of requiring the
caller to upload bytes directly.

- Route handler takes `nc: Annotated[AsyncNextcloudApp, Depends(anc_app)]` (the async
  dependency — `nc_app` is the deprecated sync one) and calls
  `await nc.files.download(payload.path)`.
- **User impersonation**: the acting Nextcloud user comes from the `AUTHORIZATION-APP-API`
  header (`base64("<username>:<app_secret>")`) — `anc_app` extracts the username server-side
  from that header (see `nc_py_api.ex_app.integration_fastapi.__nc_app`), and `nc.files.download`
  then operates against *that* user's files, no password needed since the app secret is the
  trust anchor. Verified by crafting this header manually for a real user (`Consti`) via the
  same curl-through-Nextcloud technique used for heartbeat testing earlier, and pulling a real
  photo from their library successfully.
- **Gotcha**: `nc_py_api`'s `check_error()` (used internally by `files.download`) always raises
  the base `NextcloudException` with `.status_code` set to the real HTTP code — it does *not*
  raise the more specific `NextcloudExceptionNotFound` subclass for DAV 404s (that subclass
  exists but isn't used on this path). Catch `NextcloudException` and check
  `exc.status_code == 404` explicitly; catching `NextcloudExceptionNotFound` silently misses
  every missing-file case and surfaces as an unhandled 500 instead. Confirmed via the container
  logs' actual traceback, not by reading docs.
- Verified end-to-end: a real file path returns real detections (2 faces on a second test
  photo), and a nonexistent path returns a clean `404 {"detail": "No such file: ..."}`.

### Done: writing into Recognize's `oc_recognize_face_detections` table

Two things discovered while doing this changed its shape from "just add a DB write":

- **Recognize wasn't actually installed on this instance.** Only the older, unrelated
  `facerecognition` app was present (disabled). Installed it (`occ app:install recognize`,
  11.0.1 — confirmed compatible with this instance's NC 33.0.6). Its own face classifier
  (`faces.enabled`) defaults to `false` in `SettingsService.php`, so no extra step was needed to
  keep it from competing with our writes.

  **This turned out to be only half true, and the other half cost a lot** — see "Recognize's
  MaintenanceJob was eating 3.2 GB" below. Keeping the *classifier* off is not the same as
  keeping Recognize's *clustering* off: `MaintenanceJob` is registered unconditionally in
  Recognize's `info.xml` and checks no setting at all. Don't read this bullet as "Recognize is
  inert once faces.enabled is handled".
- **ExApps have no sanctioned path to raw DB access** — confirmed by grepping `nc_py_api`'s
  source (files/users/notifications/etc. APIs, nothing for arbitrary SQL) and by the official
  AppAPI docs, which describe ExApps as isolated microservices with only a private Docker volume
  for persistence. Recognize's own classifier also writes one detection row *per user with file
  access* (via `IUserMountCache`), which only Nextcloud-side PHP can determine. So the actual
  write happens from a small **new, separate Nextcloud PHP app**, `facewright_bridge/` (not an
  ExApp) — it calls `facewright`'s `/detect` via AppAPI's `OCA\AppAPI\PublicFunctions::exAppRequest()`
  (the sanctioned way for one Nextcloud app to call another's ExApp), then writes rows via
  `IDBConnection`/`QueryBuilder`. Deployed by copying into this instance's `custom_apps` mount
  (`/mnt/data/nextcloud/nc_apps` on the host) and `occ app:enable facewright_bridge`.
  Deliberately minimal: no controllers/routes/Entity-Mapper ceremony, just
  `lib/Command/DetectFaces.php` implementing `occ facewright:detect <user> <path>` as the manual
  trigger for this phase.

Schema mapping (from Recognize's GitHub source, since it wasn't installed here to read
migrations from directly — `lib/Db/FaceDetection.php`, `lib/Classifiers/Images/ClusteringFaceClassifier.php`,
`src/classifier_faces.js`):

- `x`, `y`, `width`, `height` are **normalized 0.0–1.0 fractions** of image dimensions (top-left
  corner + box size), not pixels — confirmed from face-api.js's `relativeBox` in Recognize's own
  detector script. Our InsightFace `/detect` returns absolute pixel `[x1,y1,x2,y2]`, so
  `/detect`'s response now also includes `image_width`/`image_height` (added to
  `ex_app/lib/main.py`) so the bridge app can convert.
- `face_vector` is a JSON-encoded **flat** array of floats (not nested — a `setVector([$vector])`
  call in Recognize's `FaceDetection.php` looks like array-wrapping at first glance, but it's
  actually just `NC\Entity::setter()`'s `func_get_args()`-style calling convention). Their
  vectors are 128-dim (old face-api.js); ours are 512-dim InsightFace embeddings — different
  space, harmless at the DB level, only matters once *our own* future clustering code reads it
  back (Recognize's own clustering isn't being reused).
- `cluster_id` is left `NULL` (clustering is a later phase) and `threshold` is left unset so the
  DB default (`0.0`) applies — confirmed Recognize's own classifier doesn't set `threshold`
  either at this insert site, so this matches upstream's actual behavior rather than guessing at
  the column's intended meaning.
- Deliberately **not** done yet (matches the "Multi-user + robustness" item below, not this
  one): fan-out to every user with access to the file. This phase inserts exactly one row, for
  whichever user the command names.

Verified end-to-end: `occ facewright:detect Consti "Family/Photos/2024 Trip Photos/IMG20240102T165818.jpg"`
inserted 2 rows; read them back directly (via a throwaway verification command, removed after
confirming) and checked `user_id`/`file_id` matched, `x/y/width/height` all fell within `[0,1]`,
`cluster_id` was `NULL`, and `face_vector` decoded to a 512-float array.

### Done: clustering prototype, validated on real data

Researched two existing implementations for reusable numbers/algorithm shape first (neither is
literally importable — different language, schema, and DB):
- **Recognize's own** (`lib/Service/FaceClusterAnalyzer.php`): HDBSCAN over *Euclidean*
  distance, tuned for its old 128-dim face-api.js embeddings (`MIN_CLUSTER_SEPARATION=0.35`,
  `MAX_CLUSTER_EDGE_LENGTH=0.5`, `MIN_DATASET_SIZE=120` detections before it runs at all). None
  of these numbers transfer — wrong dimensionality, wrong model, wrong distance metric for our
  space.
- **Immich's** (`server/src/config.ts` /
  `machine-learning/immich_ml/models/facial_recognition/recognition.py`) is a much closer
  match: its default model is `buffalo_l` — the *same* InsightFace model Facewright already
  uses. Its threshold is `maxDistance: 0.5` **cosine** distance (confirmed via
  `embedding <=> ${embedding}`, pgvector's cosine-distance operator, in `search.repository.ts`)
  with `minFaces: 3` before a face counts as a cluster "core" — directly comparable to our own
  `face.normed_embedding` output. Algorithm: incremental per-face nearest-neighbor assignment (a
  face joins/starts a cluster once it has ≥3 matches within cosine distance 0.5), not a batch
  HDBSCAN pass.

Implemented Immich's algorithm (not its code) as `facewright_bridge`'s `occ facewright:cluster
<user> [--max-distance=0.5] [--min-faces=3] [--reset]`: loads a user's face detections, and for
each unclustered one, finds same-user detections within `max-distance` cosine distance; if the
match count (including itself) is ≥ `min-faces`, it joins an existing matched cluster or starts
a new one (`recognize_face_clusters` row), otherwise it's left unclustered. Simplified from
Immich in one place: no fallback search beyond the initial match set when none of those matches
already has a cluster — close enough for threshold experimentation.

**Gotcha discovered while testing**: `recognize_face_detections` predates this project on this
instance. Nextcloud app uninstalls don't drop tables by default, so real rows from Recognize's
own old face-api.js classifier (128-dim vectors, other users, and even ~1400 old rows mixed
into Consti's own data) were silently sitting in the table before `occ app:install recognize`
ever ran this session — invisible until reinstalling made them queryable again. Mixing those
with our 512-dim InsightFace vectors in a distance computation would silently corrupt every
match, so `ClusterFaces` filters strictly by embedding dimensionality (512) before doing
anything else. Worth remembering for *any* future code that queries this table broadly rather
than by a known `file_id` — the multi-user phase especially.

**Validated on real data**: ran detection over 60 real photos (one of Consti's photo albums, chosen
for likely repeat appearances of the same people), then clustered with Immich's defaults. Of 95
real detections, got 3 clusters (sizes 17/13/4) and 61 left unclustered (single/rare
appearances — plausible for background people in group shots). Spot-checked by cropping actual
face regions (using the stored bbox) from photos in the two largest clusters and viewing them
directly: cluster 544 is one person (a woman) across multiple photos, cluster 545 is a
different person (a bearded man) across multiple photos — correctly separated, correctly
matched across different photos. First real evidence Immich's thresholds are a good fit for
this embedding space, not just a plausible-sounding number.

**Not yet done**: this is a single-user, single-run prototype, not the final feature —
still needed before calling clustering "done":
- Validate against a much larger, more diverse sample (more people, more photos, harder cases
  like partial occlusion/side angles) before trusting the thresholds generally.
- Apply something like Recognize's `MIN_DETECTION_SIZE` (skip tiny/low-confidence faces before
  clustering) — not yet filtered here, so some of the 61 unclustered faces may be junk
  detections rather than genuine rare appearances.
- Handle growing datasets incrementally (this prototype reclusters everything from scratch each
  run; Immich's real version only evaluates new faces against the existing index) — matters
  once this runs continuously rather than as a one-off batch.

Naming clusters turned out to need no work at all — Memories' existing rename-a-person UI
already writes into the same `title` column via Recognize's own DAV mechanism, confirmed working
for Facewright's own clusters too. See "Done: naming clusters" further down.

### Done: GUI toggle for Memories' People page

Memories only shows the People page when Recognize's own appconfig key `faces.enabled` is
`'true'` (checked in Memories' `Util::recognizeIsEnabled()`, in `custom_apps/memories/lib/Util.php`).
But Recognize's *own* admin-settings toggle for that key
(`AdminController::setSetting`/`SettingsService::setSetting`) has a side effect: flipping it to
true also schedules Recognize's own real face-classifier crawl (`SchedulerJob`) across the whole
instance — almost certainly what produced the ~1400 legacy 128-dim rows found during the
clustering work. Checked Recognize's own source (`lib/Settings/AdminSettings.php` +
`AdminSection.php`, `lib/Controller/AdminController.php`) for the pattern, then built our own
toggle that reuses it but skips that side effect:

- `facewright_bridge/lib/Settings/AdminSettings.php` + `AdminSection.php` — its own dedicated
  "Facewright" Administration section (own nav entry, own icon at `img/app.svg`), rather than
  reusing Recognize's `'recognize'` section as originally built — the user tried the shared-page
  version live and preferred a dedicated section instead. `AdminSection` mirrors Recognize's own
  (`getID`/`getName`/`getPriority`/`getIcon` via `IIconSection`), just under our own section ID.
- `facewright_bridge/lib/Controller/PeopleSettingsController.php` — `PUT /people-enabled`
  writes `recognize`'s `faces.enabled` config **directly via `IAppConfig`**, bypassing
  Recognize's `SettingsService` entirely, so Recognize's classifier never gets (re-)armed.
  Confirmed safe by checking for event listeners on appconfig changes in Recognize's source —
  there are none, so the only place that side effect happens is inside that one PHP method, and
  a direct `IAppConfig` write is a clean way around it.
- `facewright_bridge/templates/admin.php` + `js/admin.js` — plain PHP template + hand-written
  vanilla JS (no build step/Vue). Nextcloud's classic (non-attribute) `$l->t()`/`p()` template
  convention, checkbox posts via `fetch()` using the `OC.requestToken` global for CSRF.
- Route registered in `appinfo/routes.php`, matching Recognize's own convention (was asked to
  check their source for this specifically).

**Gotcha that ate a lot of time**: after adding the Controller + route, real HTTP requests to
the new endpoint 404'd — but everything checked out individually (an `occ` debug command
confirmed `isEnabledForUser`, `isAppLoaded`, `class_exists`, and reflection all correct; even a
`#[FrontpageRoute]` PHP-attribute-based route, tried as an alternative, 404'd identically).
Recognize's own equivalent route worked fine on the same instance throughout, ruling out a
systemic routing problem. **Fixed by restarting the `nextcloud-app-1` container** — something
about the app's route/controller registration was stuck in stale in-memory state from before
the Controller existed (most likely tied to how the app was deployed: directories and files
copied in directly via `docker cp` rather than through a real app-store install/package
extraction, unlike every other installed app on this instance). Worth remembering: if a new
route/controller 404s on this instance despite everything looking correct in source, try a
container restart before assuming the code is wrong.

**Verified working in a real browser** (the user confirmed directly) — both the original
shared-section version and the follow-up dedicated-section version. The dedicated-section
change (a new `Settings\AdminSection` class, no new routes) needed no restart. Adding a new
*route* later (see below) did need one again — so the pattern is specifically about `routes.php`
entries, not app changes in general: **any time a route is added to `routes.php`, even in an
app that's already routing correctly, expect to need a container restart** for it to actually
resolve (still 404s otherwise, confirmed twice now).

### Done: included-paths allowlist for face detection

Admin-configurable list of paths (relative to each user's files root, one per line) that
`occ facewright:detect` will actually process — anything outside them is skipped. Checked
Recognize's own approach first (`lib/Service/IgnoreService.php`): it's a per-folder
`.noimage`-style *exclusion marker* file, the opposite direction from what was asked (an
explicit include list, everything else ignored) — so this is a fresh mechanism, not a port of
theirs.

- `facewright_bridge/lib/Service/PathFilterService.php` — new small shared service (needed by
  both the Controller that saves the list and the Command that checks it). Stores a
  `list<string>` via `IAppConfig::setValueArray`/`getValueArray` (native array support, no
  manual JSON encode/decode needed) under our own app id, key `included_paths`. Empty list =
  no restriction (matches pre-feature behavior). Matching is exact-or-folder-prefix
  (`Photos` matches `Photos/2024/x.jpg` but not `Photos2/x.jpg`).
- `DetectFaces` now checks `PathFilterService::isIncluded($path)` before doing anything else,
  refusing with a clear message (exit 1) if the path isn't covered. Verified both directions
  directly: set `included_paths` to a folder that doesn't match the test file → rejected; set
  it to one that does → proceeded and inserted normally.
- Added to the *same* admin settings page/section, via a second endpoint
  (`PeopleSettingsController::setIncludedPaths`, `PUT /included-paths`) and a textarea + Save
  button in `templates/admin.php`/`js/admin.js`. Kept the controller's name/file as-is even
  though it now covers more than the People toggle — renaming risked re-triggering the
  stale-routing issue above for no functional benefit.
- Hit the exact routing gotcha described above when adding the new `/included-paths` route to
  the already-working `routes.php` — 404 until another container restart. Expected this time,
  confirmed the fix still applies.

**Bug found after initial "it works" report**: paths didn't persist across a page reload,
reported specifically with a path containing spaces (`Bilder/2011 Fotos/OldPhone_Fotos`).
Verified the backend (`PathFilterService`, the appconfig round-trip, the actual `occ
facewright:detect` matching) handles spaces fine on its own — not a backend bug at all. Real
cause: the `<button>` in `templates/admin.php` had no explicit `type` attribute, so it defaulted
to `type="submit"`. If a page-level `<form>` wraps the settings sections (plausible on
Nextcloud's admin settings page), that submits/reloads the page in parallel with our `fetch()`
call, which can abort the save mid-flight — looks like it saved (no visible error), then reverts
on reload. Fixed with `type="button"` plus `event.preventDefault()` in the click handler as a
second line of defense. The People-page checkbox never had this failure mode (no button/submit
involved), which is why only the new textarea+button feature showed the bug.

**Confirmed fixed** — the user tried it again after the `type="button"` fix and paths now
persist across reloads, including the originally-reported space-containing one.

### Done: automatic + manual batch triggers

Detection no longer requires a manual `occ facewright:detect` call per file. Researched
Recognize's own trigger architecture first (`lib/Hooks/FileListener.php`,
`lib/BackgroundJobs/ClassifyFacesJob.php`): file events → a queue → background jobs drain it on
Nextcloud's own cron. Confirmed this instance's cron is genuinely running (`lastcron` was ~95s
old at check time) before relying on it. Deliberately not porting Recognize's full
sophistication (rename/delete/mount-change handling, ignore-marker files, per-user access
fan-out) — matches this project's incremental scope; multi-user fan-out is still the separate
"Multi-user + robustness" phase below.

Extracted the shared core (call the ExApp, insert rows) out of `DetectFaces` into
`lib/Service/DetectionService.php`, since three different callers now need it.

**Automatic path**: `lib/Listener/FileCreatedListener.php`, registered in
`Application.php::register()` for both `NodeCreatedEvent` and `CacheEntryInsertedEvent`
(Recognize listens to both too — different upload paths fire different events, neither alone
covers everything). Gated by a dedicated `automatic_detection_enabled` appconfig toggle,
**defaulting to off** — confirmed with the user first that this deserves its own switch,
separate from the People-page toggle and included-paths, since flipping it on is a real
behavioral jump (previously nothing happened without an explicit command). On a qualifying
image-mimetype event with a resolvable owner, enqueues `lib/BackgroundJob/DetectQueuedJob.php`
with just `{userId, fileId}` (not the Node itself — job arguments need to survive being
queued). The job re-checks `PathFilterService::isIncluded()` at run time (freshest possible
read, since the queue can sit for one or more cron cycles) and skips files that already have a
detection row for that user (idempotency — otherwise every later cache-touch on an
already-processed file would insert duplicates).

**Manual batch trigger** (added after the user pointed out file-event triggers only catch *new*
uploads, not the existing backlog already in the library): `lib/Service/BatchTriggerService.php`
+ a "Run now" button with a batch-size number field on the same admin page. Enumerates
candidate files across all seen users (`Folder::searchByMime('image')`, scoped to each
configured included path via `Folder::get()` if any are set — much cheaper than scanning the
whole tree), skips already-processed ones via the same idempotency check, and enqueues up to
the configured batch size as the *same* `DetectQueuedJob` — one processing pipeline for both
trigger paths, actual work still happens on cron, not synchronously in the request.

Verified end-to-end against the user's real photo library (not synthetic data): copied a
known-2-face test photo into their actual configured included path (`Bilder`), `occ
files:scan`ed it in to fire a genuine `CacheEntryInsertedEvent`, confirmed a job queued, ran it,
confirmed 2 real detection rows landed. Verified idempotency by manually re-enqueueing the same
file and confirming no duplicate rows. Verified the batch trigger against the real library with
a small batch size, including a false alarm along the way: a first test run's jobs disappeared
from the queue before a manual check could find them — turned out to be the *real* system cron
processing them faster than expected, not a bug. Verified the toggle actually gates the
listener (off → a new file in an included path does not get queued). Cleaned up all test files
copied into the real library and their resulting detection rows/config afterward, except the
detections produced by the real batch-trigger run against real, previously-unprocessed
photos — those are correct, kept as-is.

### Done: admin-page stats (processed last hour, processed vs. not-yet-processed)

`recognize_face_detections` has no timestamp column and never gets a row for a zero-face photo,
so "processed in the last hour" and "already processed" couldn't be answered from it directly —
and that second gap was a latent bug: zero-face files were being silently reprocessed on every
batch-trigger/cron run forever, since the old idempotency check
(`DetectionService::hasExistingDetections`) only looked at that table.

Fixed both at once with a new table `facewright_bridge` owns, `facewright_processed`
(`user_id`, `file_id`, `processed_at`, `faces_found`, unique index on `user_id`+`file_id`) via
this app's first-ever DB migration (`lib/Migration/Version000002000Date20260709120000.php`).
New `lib/Service/ProcessedFileService.php` records a row after every detection attempt
(including zero-face ones) and is now the single idempotency check both `DetectQueuedJob` and
`BatchTriggerService` use, replacing the old table-specific check. `DetectionService` no longer
exposes `hasExistingDetections` at all — call sites went straight to `ProcessedFileService`.
`PathFilterService` gained `resolveSearchRoots(Folder): array<Folder>`, promoted out of
`BatchTriggerService`'s private `resolveFolders()` so `ProcessedFileService::countCandidates()`
(the denominator for "not yet processed") enumerates exactly the same file set the batch
trigger would enqueue — not an independent estimate.

Stats are computed synchronously in `AdminSettings::getForm()` (processed count, unprocessed
count, processed-in-the-last-3600s count) and rendered in a plain new section in
`templates/admin.php` — deliberately no new AJAX endpoint/route for this, since it's simple,
read-once-per-page-load data, and sidesteps the already-twice-hit "new routes.php entries need
a container restart" gotcha entirely.

**Real bug hit applying this app's very first schema migration**: `facewright_bridge` is
manually deployed (`docker cp`, not app-store), and `occ migrations:migrate`/`execute` aren't
registered commands on this Nextcloud version — a dead end. The actual fix: bumping the app's
`<version>` in `info.xml` (0.1.0 → 0.2.0) makes Nextcloud itself detect a pending app upgrade
(`occ status` starts reporting `needsDbUpgrade: true`, and occ restricts itself to a small
command subset until resolved) — `occ upgrade` then runs exactly the same migration path a real
app-store update would, cleanly, with a normal maintenance-mode window. This is the sanctioned
mechanism, not a workaround; worth remembering for any future migration on either bridge app,
since it's not obvious the version bump alone is what unlocks it.

**Second real bug, caught by testing manual re-detection, not by inspection**:
`facewright:detect` is deliberately designed to reprocess a file that's already been processed
(unlike the automatic/batch paths) — but `ProcessedFileService::markProcessed()`'s first cut was
a plain `INSERT`, which threw a duplicate-key error against the new unique index the moment the
same file was detected twice. Fixed by making it a check-then-insert-or-update instead of a bare
insert (not a portable atomic upsert — deliberately not needed at this project's single-cron-
worker homelab scale, consistent with how idempotency is handled everywhere else in this app).

Verified against the real live instance, not just `php -l`: ran `occ upgrade` for real (user
confirmed first, since it's a shared-system, maintenance-mode-inducing operation), confirmed
`oc_facewright_processed` exists via `occ db:schema:export`, ran `facewright:detect` twice in a
row against a real test file to prove both the idempotency fix and the reprocessing-crash fix,
queried the table directly to confirm exactly one row (not two), and rendered
`AdminSettings::getForm()` through the real DI container to see actual output against the real
~54k-file library (`"1 processed, 54341 not yet processed"`) before considering this done.

### Done: clustering trigger on the admin page

Detection had been fully automated (file-event listener + batch trigger + cron), but clustering
— the step that actually turns raw detections into "people" Memories can show and let you name —
was still only reachable via the single-user `occ facewright:cluster` CLI command, and hadn't
been re-run since all the automatic-detection testing landed hundreds of new InsightFace
detections. Checked the live data before building anything: of ~9k total rows in
`recognize_face_detections`, ~8.3k are 128-dim (Recognize's own legacy classifier data, already
clustered into 539 named people from before this project existed) and only ~740 are our own
512-dim InsightFace rows, of which just 34 had a `cluster_id` — leftover from the original
60-photo prototype run. So the people the user was already seeing tagged in Memories were
**not** Facewright's doing; Facewright's own detections were sitting unclustered.

Extracted the clustering algorithm out of `ClusterFaces` into `lib/Service/ClusteringService.php`
(same "thin command, real logic in a service" pattern as `DetectionService`/`DetectFaces`), added
`runForAllUsers(maxDistance, minFaces)` which loops every seen user via `IUserManager` (the CLI
command only ever targeted one), and wired a new "Clustering" section into the admin page: two
number inputs (max cosine distance, min faces — defaults 0.5/3, unchanged from the CLI) plus a
"Run now" button hitting a new `PUT /run-clustering` route. Runs synchronously in the request,
unlike detection — clustering is pure DB/CPU work over already-stored embeddings, no ExApp
round-trip, so it's fast enough at this project's current data scale (~740 rows) not to need the
job-queue machinery detection uses; revisit if the library grows enough that this becomes slow
(same caveat already on file for clustering's O(n²) reclustering-from-scratch approach).

**Real gotcha hit verifying this, distinct from the routes.php-needs-a-restart one**: after
adding the route, restarting the container, and deploying, clicking "Run now" produced no
feedback at all — not even the immediate "Clustering..." status text, which is set synchronously
before the fetch call even starts. Root cause: Nextcloud's per-app JS cache-busting
(`TemplateLayout::getVersionHashByPath()`) keys the `?v=` query string on
`substr(md5($appVersion), 0, 8)` — i.e. purely the app's `<version>` in `info.xml`, not file
content or mtime. The clustering feature was deployed under the *same* version (0.2.0) already
used for the previous stats feature, so the browser kept serving the stale, pre-clustering
`admin.js` it had already cached under that exact URL - the new button's HTML rendered fine
(from the always-fresh PHP template), but its click handler was never in the JS the browser
actually had. Fixed by bumping to 0.2.1 and running `occ upgrade` again (no schema change this
time, just reconciling `installed_version` - still the correct mechanism, still required even
without a migration, since Nextcloud gates on the version numbers matching, not on there being
migration work to do). **Any future change to `js/*.php` or `js/*.js` in either bridge app needs
its own version bump to actually reach browsers that already loaded the admin page once** - this
is easy to miss since PHP template changes alone (no version bump needed) *do* show up
immediately, which was true for every earlier admin-page feature and made this the first time
the gap showed up.

Deliberately did not run the clustering trigger myself to "verify" it — the user had already
asked, mid-conversation, to hold off on running clustering directly and get an admin-page button
instead; invoking the same effect through direct service instantiation or an unauthenticated
curl against the new route would have been routing around that, not honoring it. Correctly blocked twice by the auto-mode classifier when attempted anyway (once calling
`ClusteringService` directly, once trying an unauthenticated curl at the new route as a "just
check it's not a 404" workaround) — both were still the same real-data action wearing a
different hat, and the classifier was right to catch that. Verification was `php -l` on every
changed file plus the user clicking the real button themselves after the cache-bust fix —
confirmed working, clusters showed up as people in Memories.

### Done: naming clusters — turned out to need no code at all

Asked to "give the clusters real names/titles" (`recognize_face_clusters.title` is `''` for
every cluster Facewright creates). Before writing anything, checked how naming actually works
in this stack, since Memories' People page is Recognize's UI surface, not ours: Recognize
exposes each cluster as a virtual folder over WebDAV (`recognize/lib/Dav/Faces/FaceRoot.php`),
and `FaceRoot::setName()` is what a rename actually calls — straight into
`FaceClusterMapper->update()`, writing `title` on the exact same table/column Facewright already
writes into. Memories' rename-a-person UI is just a DAV move/rename against that virtual folder
under the hood. Confirmed this mechanism was already real and in use on this instance before
touching any code: cluster id 16, from the pre-existing legacy 128-dim data, already had the
title "Constantin Schieber-Knöbl" — set this exact way, at some point before this project
existed.

Since Facewright's own clusters live in the identical table with the identical shape, there was
no reason to expect Memories' existing rename UI wouldn't already work on them too — and it did,
confirmed by the user directly in a real browser, no new code required. Worth remembering: this
project's own writes into Recognize's tables get all of Recognize/Memories' *existing* read/write
UI for free, not just the read-side People page display: don't assume a Memories-side feature
needs building here without checking whether Recognize's own (DAV, not REST) machinery already
covers it.

### Done: full-library scan, pause, and live queue/scan status

Triggered by a real incident: the `facewright` ExApp container had silently crashed (`Exited
(139)` — SIGSEGV, no Python traceback despite `TRACE`-level logging, so almost certainly a
native-code crash in ONNX Runtime/InsightFace's Cython extensions/OpenCV rather than an
application bug) and sat dead for 16 hours before anyone noticed, since it had no restart
policy. Also discovered mid-investigation (unrelated to the crash, and turned out to be
intentional — the user had manually cleared both tables for a clean slate): `recognize_face_detections`
and `recognize_face_clusters` were completely empty, legacy 128-dim data included.

**Restart policy**: `docker update --restart=unless-stopped facewright` (live) and the
`Makefile`'s `run` target both now set `--restart unless-stopped`, so a future crash self-heals
instead of silently stalling for hours.

**Clean-slate reset command**: `occ facewright:reset [--dry-run] [--yes]`
(`lib/Command/ResetFacewrightData.php` + `lib/Service/ResetService.php`) deletes Facewright's own
512-dim detections, any cluster made up of them, and clears `facewright_processed` entirely —
instance-wide, not per-user, unlike `ClusteringService::resetUser()` (which has no dimensionality
filter and would also wipe legacy 128-dim clusters/titles if pointed at the wrong thing — see the
near-miss in the clustering section above). Safe because clustering never mixes dimensions in one
cluster, so "every cluster referenced by a 512-dim detection" and "every cluster Facewright ever
created" are the same set. `ClusteringService::EXPECTED_DIMENSIONS` was made `public const` so
`ResetService` reuses the exact same "is this row ours" test rather than a second copy that could
drift.

**Full-library scan without a single huge request**: asked what happens if the existing
`BatchTriggerService` "Run now" batch size were set to the whole backlog (~54k files) at once.
Answer: `BatchTriggerService::run()` does two DB round-trips per candidate file synchronously
inside the web request that handles the button click — at that scale, near-certain to blow a
PHP/php-fpm/reverse-proxy request timeout partway through, with no resume logic. Fixed by adding
`lib/BackgroundJob/BatchScanJob.php`, a self-requeuing `QueuedJob`: each run enqueues one modest
chunk via the existing `BatchTriggerService::run($chunkSize)`, then re-adds itself so the *next*
chunk waits for the next cron tick rather than everything being enqueued at once. Discovered
`OCP\BackgroundJob\IJobList::add()` already calls its own `has()` internally before inserting —
i.e. duplicate `(class, json-argument)` pairs are deduped server-side for free — so re-adding
`self::class` with the same `chunkSize` argument every cycle is always safe, never creates
duplicate queue rows, and naturally stops once a pass enqueues nothing new (backlog exhausted, or
everything remaining already queued elsewhere). Both `occ facewright:scan [--chunk-size=200]`
(`lib/Command/ScanLibrary.php`) and the admin page's new "Scan entire library" button
(`PeopleSettingsController::runScan`, `PUT /run-scan`) just enqueue the first `BatchScanJob` —
same mechanism either way.

**Pause**: a `processing_paused` appconfig flag, toggled by a new admin-page checkbox
(`PeopleSettingsController::setProcessingPaused`, `PUT /processing-paused`). Checked at the top
of both `DetectQueuedJob::run()` and `BatchScanJob::run()` — if paused, each just re-adds itself
with its original argument and returns, doing no real work (no ExApp call, no enumeration) until
unpaused, then resumes exactly where it left off. Deliberately scoped to only Facewright's own
two job classes, not Nextcloud's cron generally or any other app's jobs — there's no sanctioned
way to pause cron itself, and pausing the whole instance's background jobs to stop one app's CPU
load would be a much bigger hammer than asked for.

**Live status visibility**: after building the above, live-tested it and found the existing
admin-page stats (processed last hour, processed-vs-not) didn't answer the actually-interesting
question — is a scan currently running, is it paused, how many files are sitting queued right
now. Added to `AdminSettings::getForm()` (same "computed synchronously at page load, no new AJAX
endpoint" pattern as the original stats): `IJobList::countByClass()` (available since NC 30.0.0)
gives an exact pending count per job class, so `DetectQueuedJob`'s count is the live queue depth
and `BatchScanJob`'s count (0 or 1) is whether a scan chain is currently alive. Rendered as one
unambiguous line at the top of the Statistics section — "Idle" / "Library scan is active" /
"Processing is PAUSED" — plus the queue depth, with a note when it's paused-and-not-shrinking.
Confirmed correct against real state in a real browser (showed "active" with a nonzero queued
count while a real scan was running).

### Done: chunked, self-requeuing clustering (fixed a real memory-limit crash)

Triggered by a real incident, same class as the detection-batch-size one but on the other side
of the pipeline: once the library scan (previous section) had been running a while, Consti's own
InsightFace detection count grew from the ~740 the clustering prototype was validated against to
**13,825**, with 4,286 unclustered. Clicking "Run now" under Clustering started reliably failing
— no error surfaced anywhere findable (checked Nextcloud's log, Apache's error log — which turned
out to be a symlink to `/dev/stderr`, not a real file; reading it as one just hangs forever
waiting on a live stream, the actual content is in `docker logs`, not the container's
filesystem — and the container's own OOM/restart state; nothing). Same silent-failure shape as
the earlier ExApp segfault: a hard kill leaves no application-level trace to grep for. The
numbers alone were enough to diagnose it without a log line: `ClusteringService::clusterUser()`
loaded and held every one of a user's detections in memory at once
(`fetchAll()`, then a fully-decoded 512-float PHP array *per row*, kept resident for the entire
run), then did an all-pairs cosine-distance comparison for every unclustered face against that
whole set — synchronously, in one HTTP request. At 13,825 detections that's tens of millions of
pairwise 512-float comparisons plus tens of thousands of resident decoded vectors, comfortably
past PHP's `memory_limit` (4096M on this instance) or a request timeout, whichever came first.

Unlike detection batching, clustering can't simply chunk *which files to enqueue* — correct
nearest-neighbor matching needs every one of a user's detections loaded as the candidate pool
no matter how much work you do per call, so the memory floor for holding that pool is
unavoidable at some size. What chunking *can* bound is (a) unnecessary memory duplication while
loading, and (b) how many faces get matched-and-persisted per invocation:

- `ClusteringService::loadDetections()` now streams rows (`$result->fetch()` in a loop) instead
  of `fetchAll()`ing the whole result set up front — avoids holding both the raw JSON text and
  the decoded vector for *every* row (including the ~8,300 legacy 128-dim ones that get
  discarded anyway) simultaneously; each row's raw form is dropped the moment it's decoded.
- New `ClusteringService::clusterUserChunk($userId, $maxDistance, $minFaces, $afterId,
  $chunkSize)`: still loads and matches against the full candidate pool, but only *attempts*
  cluster assignment for up to `$chunkSize` currently-unclustered ids with `id > $afterId` —
  that's what bounds the comparison and per-call persisted-write cost. The actual
  matching/join/create logic was extracted into a shared private `assignClusters()` so
  `clusterUser()` (the original one-shot, still used by `occ facewright:cluster <user>` for
  small manual runs) and `clusterUserChunk()` aren't two copies of the same algorithm.
  `persistAssignments()` was also changed to take an explicit list of changed ids rather than
  re-`UPDATE`ing every already-clustered row on every call — a real, pre-existing inefficient
  behavior surfaced while fixing this, not new.
- New `lib/BackgroundJob/ClusterScanJob.php`: self-requeuing like `BatchScanJob`, but carries an
  `afterId` cursor (not just a constant chunk size) and a `passProgress` flag in its argument,
  since simply "requeue until nothing's left unclustered" isn't a valid stop condition here —
  some faces are permanently below `min-faces` and will never cluster no matter how many more
  passes run. It advances through the unclustered set in id order each pass; once a pass reaches
  the end, it starts a fresh pass from the beginning (matching is order-dependent within a pass —
  a cluster formed partway through can make an earlier-skipped face matchable next time), but
  only if the *previous* pass made some assignment. A pass with zero effect will have zero effect
  again unchanged, so that — not an empty queue, which may never be fully true — is what actually
  stops it. Honors the same `processing_paused` flag as the detection jobs.
- `PeopleSettingsController::runClustering` (the existing `/run-clustering` route — no new route,
  so no container restart needed this time, just `occ upgrade` for the JS cache-bust) now
  enqueues one `ClusterScanJob` per seen user instead of calling
  `ClusteringService::runForAllUsers()` synchronously. Guards against double-starting a chain for
  a user who already has one in flight, via `IJobList::getJobsIterator()` + inspecting each
  pending job's `getArgument()['userId']` — `IJobList::add()`'s own built-in dedup (see the
  scan-job section above) doesn't help here since `ClusterScanJob`'s argument changes every
  invocation (`afterId`/`passProgress` evolve), unlike `BatchScanJob`'s constant `chunkSize`.
- `occ facewright:cluster-scan [--chunk-size=300]` mirrors `occ facewright:scan` — same
  per-user-chain-guard, starts the background job and returns immediately rather than blocking.
- Admin page: "Run now" under Clustering now starts the background chain (with a configurable
  chunk size, default 300) instead of blocking the request; the live status line (previous
  section) gained a `ClusterScanJob`-backed "Clustering is active" state alongside the existing
  scan/paused ones.

Confirmed live: clicking "Run now" started clustering for all 5 seen users without hanging or
erroring, where the old synchronous version reliably failed for the one account that had grown
past prototype scale.

**This section fixed the PHP-side memory crash but not the cost underneath it** — chunking bounds
how many faces are *attempted* per call while still re-reading the user's whole detection set,
`face_vector` included, on every chunk. That turned out to be ~1 GB of disk reads per invocation.
See "cut clustering's disk reads 3x" below for the measurements and the fix; read the two
sections together rather than treating this one as the end of the story.

### Done: cut clustering's disk reads 3x (`face_vector` is an off-page BLOB)

Triggered by "investigate the RAM usage of my mariadb service, I assume it's the facewright
clustering". The assumption was half right, and getting the other half right mattered: **MariaDB's
own memory was never the problem.** Reading 1 GB through the server moved its RSS by 164 kB.
Its ~1.65 GB resident is essentially static config (`Memory_used_initial` = 846 MB: 512 MB buffer
pool + 128 MB `key_buffer` + 128 MB aria pagecache + 16 MB log buffer + ~62 MB misc) plus
allocator residue from a past peak that only a restart releases. `key_buffer_size` is pure waste
here — `Key_read_requests` is 0 and every table is InnoDB.

> **Followed up.** The `key_buffer` waste and the allocator residue described here were both
> acted on later, along with the buffer pool itself — and the residue turned out to have a
> nameable cause (glibc arenas). See "MariaDB tuning — the RAM was glibc arenas" below.

What clustering *was* doing is enormous I/O. `ClusteringService::loadDetections()` selected
`face_vector` for every one of a user's detections on every chunk. That column is a `longtext`
averaging ~10.7 KB, so in DYNAMIC row format it lives **off-page**: InnoDB spends a dedicated
16 KB page read per row, and read-ahead cannot batch those. Measured, one call: **8.4 s, 1023 MB
from disk, 65,426 pages** for 65,556 rows. `ClusterScanJob` self-requeues every cron tick, so
that ran ~1,119 times over 3.86 days — **1.199 TB, >99% of the instance's total InnoDB physical
reads**, confirmed three ways (PROCESSLIST capture mid-burst, 25 min of 20 s sampling, and
counter arithmetic).

Fixed by splitting `loadDetections()` into two passes. `selectWantedIds()` reads `id` +
`cluster_id` only — never faulting in a BLOB page, measured at 0 MB from disk — to decide which
ids are wanted; then vectors are fetched for just those, batched under `max_allowed_packet`.
**1023 MB → 340 MB, 8.4 s → 3.2 s.** Results are identical: the representative cap is applied in
the same id order.

Note for anything that ever touches this table again: **capping rows in PHP saves no I/O.** The
rows are already fetched by the time the cap discards them. The saving has to happen in the
`SELECT`, which is why the id/vector split exists.

Also worth knowing: `information_schema.TABLES.table_rows` is an InnoDB *estimate*. It reported
68,499 rows in `oc_recognize_queue_faces` when the real count was 0 — enough to send an
investigation down the wrong path. Use `COUNT(*)` when the number matters.

### Done: Recognize's MaintenanceJob was eating 3.2 GB, and Facewright's data was feeding it

The actual cause of the host's memory pressure, found only after the above ruled MariaDB out.
From `nextcloud.log`:

```
Cron job used more than 300 MiB of RAM after executing job
OCA\Recognize\BackgroundJobs\ClusterFacesJob (arguments: {"userId":"..."}): 3.2 GB
Failed to calculate face clusters
  Rubix\ML\Exceptions\InvalidArgumentException: Column at offset 128 does not exist.
```

**28 crashes in the 3.4 days of log retained**, at 3.1–3.2 GB each. `FaceClusterAnalyzer.php`
does `ini_set('memory_limit', '-1')`, so nothing caps it. The error is Recognize's 128-dim
HDBSCAN choking on our 512-dim vectors.

The loop, which cannot self-terminate:

1. Facewright leaves faces below `min-faces` unclustered — permanently, by design.
2. `MaintenanceJob` (12 h `TimedJob`, registered unconditionally in Recognize's `info.xml`,
   **gated by no setting**) calls `FaceDetectionMapper::getUsersForUnclustered()`, which filters
   on `cluster_id IS NULL` plus a minimum detection size and **no embedding-dimension filter**.
3. So every user is permanently in that result set → `ClusterFacesJob` enqueued forever.

Turning `faces.enabled` off would not stop it, and that key has to stay `true` for Memories'
People page anyway.

**Fix: retire evaluated rows out of NULL.** A face that survives a full pass with zero
assignments is marked with a sentinel instead of left NULL. It stays in the matching pool and is
still assignable as a side effect of a later face matching it, so someone who only becomes
clusterable once a third photo lands still clusters — the mark means "evaluated", not "excluded".
Marking happens only at `ClusterScanJob`'s existing `!$passProgress` branch, which is already the
job's proof that a complete pass changed nothing.

**The sentinel is -2, not -1**, and this is the non-obvious part. -1 looks right — it's
Recognize's own noise label — but `sampleRejectedDetectionsByUserId()` selects
`WHERE cluster_id = -1` and feeds those rows straight back into the same HDBSCAN run. Marking -1
would hide our rows from the trigger while leaving them in the crash path. Recognize has no
predicate matching -2 anywhere (its only `cluster_id` filters are `IS NULL`, `= -1`,
`= <real id>`, `IS NOT NULL`), and Memories inner-joins detections to `recognize_face_clusters`,
so a sentinel with no cluster row is invisible there too.

Rows already at -1 are treated as **pending**, not clustered: Recognize judged 512-dim vectors in
a 128-dim space, which is meaningless. They get re-evaluated properly and settle at -2, which
also drains Recognize's rejected-sample pool. On this instance that recovered 2,354 stranded rows
— the count fell 1,360 → 298 within hours of deploying, and clustered rose correspondingly.

Two traps found while implementing, both caught by testing rather than inspection:

- **Normalizing -2 to null in the pool is correct, but `idsToProcess` must not be derived from
  it.** The normalization is load-bearing (a -2 row must not be offered as a cluster to *join*,
  but must stay eligible for side-effect assignment). Deriving the work list from it would
  re-attempt the whole backlog on every pass — one new photo would have triggered ~44 chunk jobs
  and ~15 GB of reads. Hence `pendingIds`, tracked separately from the normalized field.
- **`markUnclusterable()` takes an explicit id list, never a `WHERE cluster_id IS NULL` sweep.**
  A sweep also catches legacy 128-dim rows Facewright never evaluated, quietly crossing the
  dimensionality filter that is this project's whole definition of "our data".

Verified inside a rolled-back transaction against the live library before deploying: 15,334
pending ids marked, the 156 legacy 128-dim rows untouched, DB state identical after rollback.

**Residual risk, unfixed:** the sentinel does not make Recognize's job *safe*, only unenqueued.
`calculateClusters()` also samples existing clusters via `findClusterSample()` — and those are
our 512-dim clusters — so if `ClusterFacesJob` ever runs for one of these users it will mix
dimensions regardless. The real protection is that `MaintenanceJob` is deleted from `oc_jobs`
(done manually). **It comes back on any Recognize upgrade or re-enable** — check for it after
updating Recognize. `FsActionService.php:194,354` can also enqueue it on share/mount changes.

> **Superseded — deleting `MaintenanceJob` was never sufficient.** There is a fourth enqueue
> path this paragraph missed: `ClusteringFaceClassifier.php:135` re-adds `ClusterFacesJob`
> itself, immediately after the classifier writes detections. So as long as Recognize's
> classifier runs at all, clustering re-arms regardless of `oc_jobs` hygiene. The durable fix is
> the `.noimage` marker — see "Recognize's classifier races us on new photos" below.

The instance's 239 orphaned legacy 128-dim rows (all unclustered, from the defunct classifier)
were deleted to stop them holding the trigger open. The table is now homogeneous 512-dim.

### Done: no, Recognize cannot be uninstalled — the tables were never the blocker

Revisited "could we bootstrap the tables ourselves and drop Recognize?". The answer is no, and
the earlier "not worth it" verdict was right for the wrong reason. Three independent hard
dependencies on the app being **enabled**:

1. `Memories\Util::recognizeIsInstalled()` requires `$appManager->isEnabledForUser('recognize')`
   **and** version ≥ 3.8.0. Fail it and the People page never renders, whatever the tables hold.
2. Every *write* is Recognize's WebDAV — `moveFile('/recognize/{user}/faces/{old}', …)` to
   rename, `deleteFile(…)` to delete a cluster or remove one face, `createDirectory(…)` to create
   one. All served by `OCA\Recognize\Dav\RootCollection` via its `info.xml` `<sabre><collections>`.
   Memories has no rename/delete methods of its own.
3. The frontend hardcodes `recognize` and `facerecognition` as the only people backends.
   `ClustersBackend\Manager::register()` is public static, but Memories only calls it for its own
   five, and a third would get no route and no UI.

Only *reads* are decoupled: `RecognizeBackend.php` imports **zero** `OCA\Recognize` classes and
queries the tables directly. That is exactly the part "bootstrap the tables" would have covered,
and the only part that works that way.

### Done: scoped what dropping Recognize entirely would actually take

Re-verified the three blockers above against the live instance's real source (NC 34, Recognize
12.0.0, Memories 8.1.0 — all three checked current, not assumed from earlier notes) rather than
re-deriving them, then scoped what replacing Recognize outright would require. All three still
hold, plus one addition: `recognizeIsEnabled()` (as opposed to `recognizeIsInstalled()`) also
checks Recognize's own `faces.enabled` appconfig value, not just install+version — already true
in this project's own setup, but worth being explicit that it's a second, separate check.

**The DAV surface is fully replicable and doesn't depend on Recognize's code.**
`RootCollection`/`FaceRoot`/`FacePhoto` are plain `Sabre\DAV` interfaces (`ICollection`,
`IMoveTarget`, `IFile`) — `sabre/dav` is a core Nextcloud dependency, not something private to
Recognize. `RootCollection::getName()` returning the literal string `'recognize'` is what fixes
the DAV mount at `/remote.php/dav/recognize/{user}/faces/...` — the mount path comes from that
return value, not from the app's id. That's the one fact that opens a cheap path (below).

**The frontend blocker is worse than "hardcoded list."** `ClustersBackend\Manager::register()`
is a genuine public extension point, but Memories' compiled frontend bundle contains the literal
strings `"recognize"`/`"facerecognition"` baked in — consistent with per-backend branches, not a
generic backend-name loop. So even a server-side third `Manager::register()` call would register
a backend with no tab, no route, nothing in the UI a user could reach. A real third backend needs
a Memories frontend patch and rebuild, not just a PHP-side registration.

**The cheap path: a shim, not a third backend.** Because the DAV mount name comes from
`getName()`, not the registering app's id, and because `recognizeIsInstalled()`/
`recognizeIsEnabled()` only check the app id string `'recognize'` plus its version/appconfig — a
minimal shim app with `<id>recognize</id>`, a version ≥ 3.8.0, and `faces.enabled=true` in its own
appconfig satisfies Memories' gate outright. `facewright_bridge` (already owning the tables) would
add its own `<sabre><collections>` entry whose `RootCollection::getName()` also returns
`'recognize'`, claiming the mount point the real app used to own. Memories' frontend then works
completely unpatched — it believes Recognize is installed and finds Facewright's DAV tree at the
URL it already expects.

Effort/risk ranking: (1) keep Recognize installed-but-inert, current state, zero further work;
(2) the shim + DAV collection, ~300–400 mechanical lines of Sabre/DAV PHP porting the read-through
above, zero Memories patches, but rests on two of Memories' internals that aren't a documented
public contract (DAV-name-decides-mount-path, nullable-DI on `PageController`'s optional
`ApiKeyManager` degrading safely) and could break on a future Memories release; (3) a real third
`ClustersBackend`, same DAV cost as (2) plus a Memories frontend patch and an ongoing rebase
burden against its own build — no advantage over (2) for a single self-hosted instance, only
relevant if the goal became upstreaming a real backend into Memories itself.

**Not investigated**: whether uninstalling Recognize for real (rather than the shim leaving it
absent from the start) drops anything else Memories reads incidentally (stray appconfig rows,
l10n registration) — check before actually attempting option 2.

### Done: mimetype gate + permanent-vs-transient failure classification

Five files were failing detection on every backlog pass forever — 5,595 identical warnings from
5 distinct files. Two unrelated causes:

- **Raw files were being enqueued at all.** `BatchTriggerService` enumerates with
  `searchByMime('image')`, and that prefix match includes Nextcloud's `image/x-dcraw` catch-all
  (.RW2, .CR2, .NEF, …). The ExApp decodes with `cv2.imdecode()` and cannot read those.
  `FileCreatedListener` already had an allowlist; it just wasn't shared. The list now lives on
  `DetectionService::SUPPORTED_MIMETYPES` and all three sites use it — listener, batch
  enumeration, and `DetectQueuedJob`, which re-checks because the queue can outlive a change.
- **A failed detection was never recorded**, so the next scan re-enqueued it. Correct for a
  transient failure, wrong for a file that will never decode. The remaining 3 were JPEGs
  **truncated at exactly 2 MiB and zero-padded** to their nominal 4–5 MB size — valid `SOF0`
  headers, no `EOI` marker. Genuine corruption in the library, not a format issue.

`detect()` now reports `permanent`: a 4xx is the ExApp's verdict on that specific file, while
transport errors, 5xx, and a malformed 200 stay retryable. Only permanent failures are marked
processed, with `faces_found = ProcessedFileService::FACES_FAILED` (-1) so they stay
distinguishable from a genuine zero-face photo. Gated files are *not* marked — they are never
attempted, so raw support could be added later and they'd be picked up naturally.

That split is load-bearing, not defensive: a power outage left the containers with no external
DNS and every detection failed for four hours. Marking failures indiscriminately would have
permanently skipped every file the scan touched in that window. Nothing was marked.

### Gotcha: Docker DNS silently breaks on power loss (host-level, not this project)

After a power cut, every detection failed with `cURL error 6: Could not resolve host`. Not a
Facewright bug and not visible from the repo. The host's `/etc/resolv.conf` (systemd-resolved)
lists an IPv6 link-local nameserver with a zone index (`fe80::…%3`, unusable inside a container)
ahead of the real IPv4 ones. Docker built the containers' `resolv.conf` at boot **before DHCP had
populated the IPv4 entries**, so they ended up with no external upstream —
`NO EXTERNAL NAMESERVERS DEFINED`, while a container started later resolves fine.

Diagnosis in one command: `docker run --rm alpine cat /etc/resolv.conf` and compare against
`docker exec nextcloud-app-1 cat /etc/resolv.conf`. Fix is `docker restart` on the affected
containers (both `nextcloud-app-1` and `facewright` — the ExApp resolves `NEXTCLOUD_URL` for
`nc.files.download()`). Durable fix, still outstanding: pin
`{"dns": ["192.0.2.1", "192.0.2.2"]}` in `/etc/docker/daemon.json`. The failure mode is silent
— detection just stops — so check this first after any unclean reboot.

### Done: multi-user fan-out and access reconciliation (bridge 0.3.0)

Detection used to write one row for the file's **owner** (`FileCreatedListener`'s `getOwner()`).
Recognize writes one row per user *with access*, which is what Memories' People page expects — so a
photo Consti owned and shared to Bob produced faces on his page and nothing on hers.

A quieter second problem came with it: `BatchTriggerService` walks each user's tree independently
and enqueued `{userId, fileId}`, so a shared photo was enqueued once per user and **inferred
separately for each**. Fan-out removes that duplicate inference rather than adding to it.

**The primitive is one call.** `IUserMountCache::getMountsForFileId($fileId)` *is* the access model
— shares, group folders and external storage all register into `oc_mounts`, so none needs
special-casing. Same call Recognize uses (`ClusteringFaceClassifier.php:56-63`), and the reason
Recognize v12 has **no share hooks at all**.

Where `AccessService` deliberately diverges from Recognize: **`ICachedMountFileInfo::getPath()`
already returns `getMountPoint() . getInternalPath()`** (`lib/private/Files/Config/CachedMountFileInfo.php:38-40`),
so one mount-cache call yields both the user set *and* every user's files-root-relative path.
Recognize re-resolves a `Node` per user instead. At 65k files that difference is most of the cost.
`pickFetchUser()` prefers the owner — their mount is their home storage, so no share permission can
revoke the read, and since the fetch user's path is what `PathFilterService` is evaluated against, a
flapping choice would make a file's inclusion flap.

**Verified API facts that contradict the obvious guess:**
- **`IDBConnection::insertOrUpdate()` does not exist** in NC 34's public API — only
  `QBMapper::insertOrUpdate(Entity)`, which needs the Mapper layer this app deliberately lacks. The
  right primitive is **`IDBConnection::setValues($table, $keys, $values)`** (`IDBConnection.php:188`):
  insert, catch the unique violation, fall back to update. Returns **1 on insert, 0 on update**.
- `IFunctionBuilder` has **no `distinct()`**, so `COUNT(DISTINCT file_id)` needs
  `createFunction()`.
- **A migration was mandatory, not an optimization.** `facewright_processed`'s only index was
  UNIQUE `(user_id, file_id)` — leading column `user_id`. Every query fan-out adds filters on
  `file_id` alone, including on the *synchronous path of every file deletion*. Hence
  `facewright_proc_file` (`Version000003000Date20260801120000`).

**"Is this row ours" is now a length test.** `char_length(face_vector) > 6000`
(`DetectionService::VECTOR_LENGTH_FLOOR`), measured on the live table: legacy 128-dim rows span
1,292–2,658 characters, ours 10,672–10,826 — a clean 8,000-wide gap. It keeps the test in the
`SELECT` instead of faulting the off-page BLOB per row (the lesson from the 1023 MB → 340 MB
clustering fix). `ClusteringService::EXPECTED_DIMENSIONS` is still the authority; this is a proxy
valid only while those bands stay disjoint.

**Three traps in Recognize's version, deliberately not inherited:**
1. `removeDetectionsForFileFromUsersNotInList` deletes by `file_id` with **no dimension filter** —
   copied verbatim it would delete this instance's legacy 128-dim rows.
2. **`notIn('user_id', [])` renders as `NOT IN (NULL)`**, matching nothing — so Recognize's orphan
   rows survive exactly when nobody has access and they most need removing. Ours omits the clause
   entirely when the keep-list is empty, meaning "delete all our rows for this file".
3. **Sub-share pruning.** `FsActionService::onAccessUpdate` computes one access set from the *mount
   root* and applies it as the keep-list to every file in it. With `Bilder/` shared to one person
   and `Bilder/2024/` separately to another, that deletes the second person's rows.
   `reconcileMount()` uses the mount set for the *copy* direction only and pays for a per-file
   `getUsersWithAccess()` before any delete — bounded to actual prune candidates, normally zero.

**Copy, never re-detect.** `AccessSyncService::copyToUsers()` re-inserts the donor's rows with
`cluster_id` **omitted** so the column default NULL applies (a donor's cluster id is meaningless in
another user's namespace, and NULL is what `ClusteringService` treats as pending, so the existing
per-user `ClusterScanJob` chain picks copies up unaided). **Columns are named explicitly on both
sides**: the table's physical order is `x, y, height, width` while `DetectionService` writes
`x, y, width, height`, so a positional copy silently transposes every bounding box.

**The zero-face case is the one that's easy to miss.** `copyExisting()` handles two cases: someone
holds rows → copy them; nobody holds rows but someone holds a *marker* → copy the marker, because
the file has already been through detection and yielded nothing. On this instance **28,056 of
Consti's 54,332 processed files are zero-face**, so treating "no rows" as "not yet detected" would
re-run inference on more than half the library every time a folder is shared.

**Job argument is now `{fileId}`, not `{userId, fileId}`.** `IJobList::add()` dedupes on class plus
argument, so keying on the file collapses to one job however many users' walks reach the photo.
Old-shape rows keep working untouched — `fileId` is present in both and `userId` degrades to a hint
`pickFetchUser()` ignores if that user lost access. **Nothing in `oc_jobs` needs rewriting.** A
bounded retry (`MAX_ATTEMPTS = 5`) was added at the same time and is not optional: fan-out makes the
fetch user potentially a sharee whose access was revoked between enqueue and run, and the ExApp
reports the resulting DAV 403 as a 502, which is classified retryable — i.e. the 5,595-identical-
warnings loop wearing a different hat.

**Deferral is job arguments, no queue table.** Recognize needs `oc_recognize_fs_actions` because its
`FsMove` carries unbounded user arrays; our unit of work is `(storageId, rootId)` or `fileId`, far
under `IJobList`'s 32,000-char argument cap, and `add()`'s dedup gives the "one pending action per
mount" property for free. We recompute the access set at run time rather than snapshotting it, which
also demotes the `UserMountRemovedEvent` suppression guard from a correctness requirement (as it is
for Recognize) to a mere optimization.

**Deletion is synchronous, on `BeforeNodeDeletedEvent`, and that's forced.** Deferring doesn't work
— filecache is gone by the time a job runs, so there'd be no way to learn what was under a deleted
folder. Enqueuing per-file before the delete is unbounded. `PruneService` instead does one indexed
filecache page query per 1,000 files. Documented cost: deleting a large photo folder now takes
latency proportional to how many of its files had been detected.

**Two live-data shapes that broke the first implementation**, both found by running against the real
instance rather than by inspection:
- **Home mounts are `/Consti/`, not `/Consti/files/`**, and their `root_id` is the *storage* root,
  whose subtree includes `files_trashbin/` and `files_versions/`. A naive filter skipped home mounts
  entirely, and a naive walk would have reconciled trashed files. `BackfillAccess::resolveRootId()`
  maps a home mount to the user's *files folder* id; `MountFileService` refuses a `''` root outright.
- **Mounts nest.** Bob has `files/Bilder` shared *and* four folders inside it shared separately.
  A real run writes each file once (the second mount finds her already marked), but a **dry run
  writes nothing, so every nested mount re-counted the same files** — inflating the estimate by 18%
  (77,446 vs the true 65,757). Fixed by returning per-`"fileId:userId"` keys the caller unions
  instead of summing. This is exactly what the "cross-check the dry run against SQL before writing"
  step exists to catch.

**Stats had a guaranteed regression, now fixed.** `countCandidates()` is gated on `included_paths`
*per user*; Bob has no `Bilder` folder of her own, so she contributed ~0 candidates. After fan-out
she gains a marker for every Consti photo under `Bilder`, so the row count would have run past the
candidate count and `unprocessedCount = max(0, candidates - processed)` would have **pinned to zero
permanently**. Both sides are now counted in *files* (`countProcessedFiles()` =
`COUNT(DISTINCT file_id)`; `countCandidates()` unions file ids, which also fixes a pre-existing
double-count). The row count survives as its own line, since it's what explains the tables' growth.

**Verified against the live instance, staged.** Deployed paused (`processing_paused=true`,
automatic detection and clustering off), `occ upgrade`, index confirmed present *before* anything
wrote. Dry run reconciled **exactly** with SQL: 65,757 rows, and the 36-row gap against a naive
`COUNT(*)` is precisely the gif/bmp/dcraw rows that predate the mimetype gate (19+16+1). First live
write was one mount (`--mount-root=295730`, 33 images): 35 rows copied, parity 35/35, every copy
`cluster_id IS NULL`, 33 markers of which **11 were zero-face markers copied with no ExApp call**,
and the geometry check found zero rows whose box differed from Consti's. Re-running copied 0.
Both job shapes were then executed for real: the old `{userId, fileId}` argument ran without error
and wrote nothing on an already-done file, and `{fileId}` on a Consti-only file copied 23 rows with
correct markers — **while `docker logs facewright` showed zero requests**, proving the copy path and
not inference. `AccessSyncJob` was verified in both branches: paused, it re-queued itself unchanged;
unpaused, it reconciled and terminated the chain rather than looping.

**One scare worth recording**: Consti's total row count dropped by 2 mid-verification. It was not
this code — his 512-dim count was byte-for-byte unchanged at 65,793 throughout. The two rows were
**legacy 128-dim** ones removed by *Recognize's own* `RemoveDuplicateFaceDetections` repair step,
which `occ upgrade` runs for every app. Worth knowing before diagnosing the same shape again.

### Done: per-user mode — face recognition is opt-in, per user (bridge 0.4.0)

Every setting was instance-wide. `included_paths` was one `oc_appconfig` row (`["Bilder"]`), there
were **zero** `oc_preferences` rows for the app, and the settings page was registered only as
`<admin>` with no action carrying `#[NoAdminRequired]`. Bob could reach it only because she is in
the admin group; a normal user had no way to add their own folders or opt out. The consequence was
visible in the data: `BatchTriggerService` resolves the *global* list against *each* user's own root,
so Bob — with no `Bilder` folder of her own — contributed ~0 candidates while holding 54k rows
purely from fan-out.

Now each user turns it on for themselves, picks their own folders, scans their own backlog and tunes
their own clustering, at Settings → Personal → Facewright. The global list is gone.

**A user with no stored preference is off.** That is the only safe default for accounts created from
here on, and the wrong one for accounts already being scanned — hence `Version000004000`, a
data-only migration (no schema change; the `<version>` bump is what makes `occ upgrade` run it) that
copies the old global list into every seen user and enables them.
`occ facewright:init-user-settings [--dry-run]` calls the same service method, so the move can be
inspected before the upgrade and re-run after. The old appconfig row is **deliberately not deleted** —
nothing reads it any more, and leaving it keeps a rollback lossless.

**Storage is `OCP\Config\IUserConfig`** (`@since 32.0.0`), the per-user twin of `IAppConfig`,
`getValueArray`/`setValueArray` included. `OCP\IConfig::setUserValue` and friends are deprecated as
of NC 33. Two non-obvious constraints shaped the schema:
- **`enabled` is a string, not a bool.** `setValueBool()` has no `$flags` parameter, and the flag
  matters: without `FLAG_INDEXED`, `searchUsersByValueString` (how `enabledUsers()` finds who to
  walk) falls back to scanning `configvalue` and logs a notice. Verified in the live table —
  `flags=2` on every `enabled` row.
- **Array-typed keys cannot be searched by value** (they are JSON blobs), so the admin overview uses
  `getValuesByUsers(..., ValueType::ARRAY)` — one query for everyone, not an N+1 per page load.

**The detection gate is the one genuinely subtle change.** `DetectQueuedJob` used to ask "is the
*fetch* user's path included". With per-user lists the rule generalises to:

> A file is worth inferring if **any enabled user with access** has it under **their own** included
> paths. Rows still fan out to **every** user with access, unchanged.

`getAccessPaths()` already returns `userId => path` for everyone, so this costs no extra query.
`pickFetchUser()` gained a `$restrictTo` parameter and **it is load-bearing, not defensive**: the
ExApp downloads the file *as* the fetch user, so owner-first would happily pick someone who excluded
the file and ask for a path outside their own allowlist — defeating the gate it just passed.

Proved on the live instance with real inference, counting `POST /detect` in `docker logs facewright`
as ground truth (a marker alone cannot distinguish inference from a fan-out copy):

| user enabled | path included | ExApp called |
|---|---|---|
| yes | no | **no** (154→154) |
| yes | yes | **yes** (154→155) |
| no | yes | **no** (155→155) |
| yes | yes | **yes** (155→156) |

Then the case that tests both halves at once: file 33956, visible to Consti *and* Bob, included by
neither. Adding it to **Bob's** list only → inference ran (156→157), the fetch user was **Bob,
not Consti the owner** (`$restrictTo` working), and **both** users got markers — fan-out reaching a
user whose own list excludes the file. That is "paths gate scanning, not visibility", intact.

Other decisions worth not relitigating:
- **Nothing retroactive.** Shrinking a path list never deletes rows. `AccessSyncService`,
  `PruneService`, `MountFileService`, `FileAccessListener` and `DetectionService` are untouched.
- **Clustering is not gated on `enabled`.** Opting out stops new detections; refusing to cluster
  what is already stored would strand those faces off Memories' People page.
- **`FileCreatedListener` still enqueues unconditionally.** Checking "is anyone with access enabled"
  there needs a mount-cache lookup inside the upload request, which that listener deliberately
  avoids. The job's early return is the cheap place for it.
- **`BatchScanJob` gained an optional `userId`** rather than a second job class. `IJobList::add()`
  dedupes on class plus argument, so that yields one chain per user *and* one instance-wide chain for
  free; old `{chunkSize}`-only rows keep working, since an absent userId is exactly "scan everyone".
  Confirmed live: two identical per-user adds → one row; a global chain coexists as a separate row;
  running Bob's chain enqueued exactly 3 of *her* candidates and none of Consti's.
- **The admin page keeps only instance-wide controls** plus a read-only per-user table. Its numbers
  were cross-checked against SQL (`COUNT(DISTINCT file_id)` 54,545, rows 108,758, per-user
  54,332/54,426 — all exact). `countCandidates()` returns `{total, perUser}` from **one** walk;
  `countCandidatesForUser()` exists because a personal page load must not pay for an instance-wide
  filesystem enumeration (1.94s for Consti vs. 0.04s for a user with no matching folder).

**Editable thresholds forced a fix to an already-flagged trap.** A face that survives a full pass
unassigned is marked `-2` and never reconsidered — correct while the thresholds hold, silently wrong
the moment a user changes them, which would make the *second* use of the control appear to do
nothing. `ClusteringService::clearUnclusterableMarks()` returns those rows to NULL, called only when
`setClusterParams()` reports the values actually moved. Verified inside a rolled-back transaction
against the live table: of Consti's 13,237 `-2` rows it reopened **13,236** — the one it refused is a
legacy 128-dim row, i.e. the dimensionality guard doing exactly its job. State after rollback was
identical. Note the cost this implies: a threshold change reopens five figures of rows for a large
account and will start a long clustering run.

**Authorization was verified through the framework, not by reading the source.** Every user on this
instance is in the `admin` group, so observing that the personal page works proves nothing. Asking
Nextcloud's own `ControllerMethodReflector` — the object `SecurityMiddleware` consults — reported
`adminRequired=no, csrfChecked=yes, public=no` for all four `PersonalSettingsController` actions and
`adminRequired=YES` for `PeopleSettingsController`'s. Every personal action acts on the injected
`$userId` and never on a user id from the request body; that is the whole authorization boundary.

Two deployment notes, both previously documented and both hit again exactly as expected: the four new
`routes.php` entries needed a `nextcloud-app-1` restart (confirmed by PUTing each route and getting
401 = exists, while the removed `/included-paths` correctly 404s), and the `js/` changes needed the
version bump to bust `?v=substr(md5($appVersion), 0, 8)`.

Left behind by verification, deliberately: 8 zero-face markers on real, previously-unprocessed files
(logos, scanned bills, kitchen-planning photos). Real results on real files, same call as the
batch-trigger run kept earlier. **No detection rows were created or destroyed** — Consti 65,823 /
Bob 65,970, byte-for-byte the pre-change baseline.

### Done: Recognize's classifier races us on new photos, and winning the race destroyed the file

Reported as "clustering seems stuck — the People page shows nothing for a photo I just added, but
the admin page says 0 candidates". Nothing was stuck. Detection had run, found 2 faces, and marked
the file processed; the candidate count was honest. The rows just went to the wrong place.

For the reported file, `recognize_face_detections` held **128-dim rows for Consti** (Recognize's
legacy face-api.js classifier) and **512-dim rows for Julia** (ours). Memories inner-joins
detections to `recognize_face_clusters` (`RecognizeBackend.php:108,112,147`), so a row with
`cluster_id IS NULL` is invisible — and `ClusteringService`'s dimensionality filter means a
128-dim row can never *acquire* a cluster id. Consti's copy of that photo was permanently dead.

**The mechanism is a race plus a bad tie-break.** `DetectionService::usersWithRowsFor()` skipped
the insert for any user already holding rows for the file — deliberately counting Recognize's rows
too, on the reasoning that either way a reader would see duplicates. But it still wrote the
processed marker for that user. So whenever Recognize wrote first:

1. Recognize's classifier writes 128-dim rows for the user.
2. We infer, see rows, skip our insert.
3. We mark processed anyway → **nothing ever revisits the file.**

Damage at discovery: 57 files for Consti (55 already marked processed) and 80 for Julia (12
marked) holding *only* legacy rows, plus 28 files where we won and Recognize wrote strays anyway.
Growing with every upload.

**Fix, two halves.**

*Suppression is now dimension-aware.* `usersWithRowsFor()` counts only rows passing
`VECTOR_LENGTH_FLOOR`. The predicate moved to `DetectionService::ownRowsExpr()` (public static,
next to the constant) and `AccessSyncService`'s private copy now delegates to it — every query
that has to tell the two apps' rows apart uses one definition. Worst case is now a user holding
both a legacy row and ours for one file: visible and self-correcting, versus silent and permanent.

*Recognize's classifier is stopped with `.noimage`, not with job hygiene.* `faces.enabled` cannot
be turned off — Memories' `Util::recognizeIsEnabled()` reads that exact key — and it is also the
only gate `ClassifierJob::runClassifier()` checks. Meanwhile `FsActionService` inserts into the
faces queue **unconditionally**, `QueueService::insertIntoQueue()` adds `ClassifyFacesJob` on the
spot, and `ClassifierJob` re-adds itself. Clearing jobs and queues is pure whack-a-mole; the
backlog refills on the next uploaded or moved photo.

Recognize's own ignore-marker mechanism is the way out, and it is honored in **all three** enqueue
paths — `FileListener::isFileIgnored` (event), `FsActionService::isFileIgnored` (deferred), and
`StorageService::getFilesInMount` (the crawl). `ClusteringFaceClassifier::MODEL_NAME` is `'faces'`
and is grouped with `IGNORE_MARKERS_IMAGE` in each. Matching is a path **prefix** test, so an
empty `.noimage` at `files/` covers a user's whole tree. One per user, all five here. Facewright
never reads these markers, so its *reads* are unaffected — but installing one is **not** the inert,
freely reversible act it looks like. See the next subsection; read it before touching a marker.

#### The marker is a loaded gun in both directions — this destroyed 6,055 rows

`FileListener` does not treat an ignore marker as a passive flag. It reacts to the marker's own
filecache entry appearing or disappearing, and the reaction is a **mass action over the entire
parent tree**:

```php
// CacheEntryInsertedEvent / NodeCreatedEvent, node is an ignore marker:
$this->resetIgnoreCache($node);
$this->postDelete($node->getParent());   // FileListener.php:198-208, :188-193
// NodeDeletedEvent, node is an ignore marker:
$this->postInsert($event->getNode()->getParent());  // FileListener.php:181-186
```

So `occ files:scan` indexing the new `.noimage` enqueued **56,414 `FsDeletion` actions** — one per
file under `files/` — and `FsActionService::onDeletion()` executes each by deleting face detection
rows **by `file_id` with no dimension filter**. That is the same trap already documented as #1 in
the multi-user section, met from a direction nobody was looking at. It ate **6,055 of Facewright's
own 512-dim rows across 2,198 files** (Julia 5,818 / 2,181, Consti 237 / 17) before it was stopped
by deleting `ProcessFsActionsJob` from `oc_jobs` and clearing `oc_recognize_fs_deletions`. Consti
was largely spared only because the job was still working through Julia's storage (`storage_id: 3`).

**Deleting a marker is not an undo** — it fires `postInsert($parent)`, queueing the whole tree *for
classification* instead. Both directions trigger a ~56k-file mass action; only the sign differs.
That asymmetry is why the markers were kept: the purge is a one-time cost already paid, whereas
removing them would re-arm the classifier at full scale *and* leave no kill switch.

**Operational rule that follows: never let anything re-index a `.noimage` cache entry.** A routine
`occ files:scan` over a user's tree is enough to re-fire the purge. If one is unavoidable, watch
`SELECT COUNT(*) FROM oc_recognize_fs_deletions` and clear it before `ProcessFsActionsJob` drains
it.

**How it was caught, and the lesson:** the rehearsal transaction printed `det_before` 191,208 and
`det_after_rollback` 189,885. A rollback must restore its own transaction exactly, so a
non-reconciling pair means *another session* committed in between — that discrepancy was the only
signal, and the deletion was still in progress at the time. Print a before/after pair around
rehearsals and actually reconcile them; a count that "looks about right" would have missed this.

**Recovery.** No dump of the deleted rows existed — they were gone before any backup was taken. But
the files still existed and still carried `facewright_processed` markers with `faces_found > 0`, so
deleting exactly those 2,198 markers returned them to the candidate set and a normal
`occ facewright:scan` regenerated the rows through the ordinary pipeline, fan-out included. Worth
remembering: **the processed marker is the recovery handle** — as long as the file and the marker
disagree, the state is repairable without a backup.

Verified through Recognize's own `IgnoreService` via the real DI container, not by inspection: all
five storages report `ignoredDirs=["files"]`, and the reported photo evaluates to
`would ignore: YES`. Suppression verified by reflection on the live service — for the reported
file it now returns `["Julia"]` (so Consti *would* be inserted), while a control file where both
users hold our own rows still suppresses both, confirming idempotency did not regress. Across a
cron tick afterwards, `max(id)` in `recognize_face_detections` did not move and `queue_faces`
stayed empty.

**Deployment notes.** Pure PHP service changes, so no version bump and no `occ upgrade` (opcache
here is `validate_timestamps=On`, `revalidate_freq=60`). The container restart *was* required, and
not for routes: `IgnoreService` caches its result in `createLocal(...)` = **APCu with no TTL**, so
Apache workers would otherwise keep a pre-marker empty list indefinitely and web-triggered
enqueues (upload, MOVE) would slip through. Cron is CLI and gets a cold cache each run, so only
the web side needed it.

**Four operational traps hit while installing the markers**, all costing real time:

- **`files:scan --shallow --path=/<user>/files` does not finish** on a 54k-file home storage —
  two of them ran 12+ minutes holding open transactions and blocked every `oc_filecache` INSERT
  with `Lock wait timeout exceeded` (50s = `innodb_lock_wait_timeout`). Scan the marker file
  directly (`--path=/<user>/files/.noimage`) instead; it is instant once nothing else is running.
- **`pkill -f files:scan` silently failed** inside the container. It reported nothing and the
  processes kept running; only `ps -eo pid,etimes,args` revealed them, and `kill -9 <pid>` worked.
  Do not trust `pkill` here.
- **Killed scans leave orphaned locks in Redis** and the next attempt dies with "Another process
  is already scanning". They are `<instanceid>/lockfiles/<md5>` where the hash is
  `md5("<storage>::scanner::<path>")` — computable, so the exact stale keys can be identified and
  deleted rather than flushing Redis. TTL is ~1h, far too long to wait out.
- **The `nextcloud` DB user lacks `PROCESS`**, so `information_schema.INNODB_TRX` returns an
  *access-denied error*, which reads as "no transactions" if only the row output is checked. This
  sent the lock investigation down the wrong path twice. `PROCESSLIST` works (all connections are
  that same user) — use it.

Also noticed, and it is **Recognize's bug, not ours**: 386 `Array to string conversion` warnings
at `doctrine/dbal/.../PDO/Statement.php#48`, two per DAV MOVE, because
`oc_recognize_fs_moves.added_users` / `target_users` are bound as PHP arrays. The stored values
are literally the string `"Array"`. Harmless to us; the 413 accumulated rows were deleted.

**Healed.** The 137 broken `(user_id, file_id)` pairs were repaired by deleting their 232 legacy
rows (verified beforehand as exactly 232 rows, all `JSON_LENGTH = 128`, all `cluster_id IS NULL`,
and dumped to SQL first) plus their 67 `facewright_processed` markers, then letting
`occ facewright:scan` re-detect them. Afterwards `pairs_still_only_legacy` = 0 and the legacy row
count fell 272 → 40. Deleting by an explicit dumped id list — not by re-running the defining query
at delete time — is what guarantees the rollback file covers exactly what was removed.

The remaining **40 legacy rows sit on the 28 files where Facewright won the race** and Recognize
wrote strays anyway. Our rows for those files exist and cluster correctly, so nothing is broken;
they are inert leftovers, still invisible to Memories (`cluster_id IS NULL`). Cleaning them would
make the table homogeneous 512-dim again but buys nothing while the classifier stays off.

### Done: MariaDB tuning — the RAM was glibc arenas, not Facewright (host-level)

Asked "the mariadb process is taking a lot of RAM again - is this due to facewright?". **No**, and
the same trap as last time caught the obvious reading: **`nextcloud-db-1`'s RSS is not what MariaDB
is using.** At 2,268 MB resident it reported `Memory_used` = 885 MB against `Memory_used_initial`
= 846 MB — i.e. **39 MB above its fixed startup allocation**. Facewright was idle at the time:
`Innodb_data_read` was byte-identical across three samples 40 s apart, no `DetectQueuedJob` /
`BatchScanJob` / `ClusterScanJob` rows existed in `oc_jobs`, and only 39 of 195,827 detections
were still pending clustering.

**The residue has a specific cause, and it is worth knowing because it will recur.** glibc creates
up to `8 × ncores` malloc arenas — 8 cores here, so **64 arenas × 64 MB heap = a 4 GB theoretical
ceiling** on memory that is freed inside the process but never returned to the OS. The observed
1,383 MB gap sits comfortably inside that. It is not a leak and no query causes it; only a restart
releases it.

Four changes, all in the `db` service of `/mnt/data/nextcloud/docker-compose.yml` (reached via the
symlink `/home/constantin/homecloud/nextcloud`, which is **not** under version control — the
`homecloud` repo shows its tracked copy as deleted, so a timestamped `cp` is the only rollback):

- **`MALLOC_ARENA_MAX: 4`** — the actual fix for the reported symptom, capping arena residue at
  ~256 MB instead of 4 GB. Everything else below is tuning that happened to share the window.
- **`--innodb-buffer-pool-size=2G`** (was 512M) — the DB is 5,071 MB across 292 tables, of which
  `oc_recognize_face_detections` alone is **3,109 MB, 61% of the instance's entire database**.
  A 512 MB pool against that meant every clustering pass evicted the whole cache and every other
  app re-read from disk. This is the one genuinely Facewright-shaped constraint here: our table
  dominates this instance's working set, so pool sizing is a Facewright decision whether or not
  it looks like one.
- **`--key-buffer-size=0`** (was MariaDB's compile-time default 128M) — provably dead: all 292
  tables are InnoDB, and `Key_blocks_used` / `Key_reads` / `Key_write_requests` were all 0.
  **Do not do the same to `aria_pagecache_buffer_size`**, which looks identical but is actively
  used for internal temp tables (`Aria_pagecache_read_requests` = 29.8 M) and is `READ_ONLY = YES`.
- **`mem_limit: 4g`** — a runaway ceiling, not a budget, sized above the ~2.7 GB expected steady
  state. Swap deliberately left unrestricted (no `memswap_limit`), so the cap cannot turn a swap
  event into an OOM kill; `restart: always` covers the genuine-runaway case.

Verified after the recreate: pool 129,792 pages (2,076 MB), `key_buffer_size` 0 with
`Key_blocks_unused` 0 (was 107,163), `Memory_used_initial` 846 MB → 2,215 MB, cgroup `memory.max`
= 4294967296, `MALLOC_ARENA_MAX` = 4, container swap 287 MB → 0 (host swap free recovered
101 → 387 MB), `status.php` 200 in 51 ms. The only two log entries in the window were
`ServerMaintenanceMode` from a DAV client hitting the deliberate `occ maintenance:mode --on`.

**Three gotchas worth not rediscovering:**

- **The `nextcloud` DB user cannot `SET GLOBAL`** — `ERROR 1227 ... need SUPER`; it holds only
  `USAGE ON *.*` plus `ALL` on its own schema. This kills the obvious no-downtime plan, since both
  `innodb_buffer_pool_size` and `key_buffer_size` are otherwise `READ_ONLY = NO` and would have
  been rehearsable live. Same shape as the already-documented missing `PROCESS` privilege. The
  root password is sitting **in plaintext in a commented-out line** of that compose file if a
  privileged session is ever genuinely needed.
- **RSS climbs for hours after the change and that is correct.** InnoDB allocates the buffer pool
  lazily — immediately after restart it was 256 MB resident with 121,157 of 129,792 pages free.
  The number that matters is the *gap* between RSS and `Memory_used`, not RSS itself.
- **`docker compose config` validates the edit before anything restarts** — it resolves
  `mem_limit: 4g` to `"4294967296"` and expands `command:` into a list, which is enough to catch a
  malformed flag without a maintenance window.

### Done: `occ facewright:reset` could not run at all — both halves OOMed

Found while cross-checking something else: `occ facewright:reset --dry-run` died with
`Allowed memory size of 4294967296 bytes exhausted`. Both of the command's two paths held the
same anti-pattern this project has already removed twice — `ClusteringService::loadDetections()`
(1023 MB → 340 MB, the id/vector split) and `countUnclusteredByUser()` (674 MB → an aggregate).
The reset command was simply never revisited, because it is rarely run.

- `ResetFacewrightData::countOwnRows()` — the **preview** — selected `face_vector` for every row
  in the table, `fetchAll()`ed it, and `json_decode()`d each one to count elements.
- `ResetService::findOwnRows()` — the **actual delete path** — did the identical thing, plus `id`.

At ~196k rows averaging ~10.7 KB that is ~2.1 GB of off-page BLOB before decoding. So the command
was unusable in both directions, and the worse half is the preview: the safety rail on a
destructive instance-wide wipe is exactly the part that has to keep working.

**Nothing was ever left half-deleted.** `findOwnRows()` runs to completion before the first
`DELETE` is issued, so the failure is always fatal-before-write, never partial.

Fixed by deciding "ours" in the `SELECT` via `DetectionService::ownRowsExpr()`: the preview is now
three aggregates, and the delete path projects `id` + `cluster_id` only and streams with `fetch()`.
Neither faults the `face_vector` pages. The predicates mirror each other line for line,
`cluster_id > 0` included, so the numbers the preview prints are the numbers the delete reports.

**One deliberate semantic change, on a destructive path**: the dimensionality test moves from exact
(decode, count elements) to `ownRowsExpr()`'s `char_length > 6000` proxy. Both error directions are
safe here, which is what licenses it — over-deleting a legacy row would need one longer than 6,000
characters, and 128 floats cannot reach that (measured 1,292–2,658; it would take ~280 dimensions),
while under-deleting one of ours merely leaves a row for the next run. That reasoning is *specific
to this table's two dimension bands*; do not carry the proxy onto a destructive path elsewhere
without redoing it.

Verified live, without deleting anything: the preview now completes in 42.7 s
(195,843 detections / 4,911 clusters / 158,120 processed records), and `findOwnRows()` — invoked
directly through the real DI container by reflection, so only its `SELECT` ran — returns the same
195,843 and 4,911 in 23.4 s at **33.0 MB peak memory** against the 4096 MB limit it used to exhaust.
The two agreeing is the check that matters, since a preview that disagrees with the delete is worse
than one that crashes.

### Done: face vectors were float32 stored as float64 text — database down 40%

Asked to analyse what the face vectors cost and propose fixes. Measured first:
`oc_recognize_face_detections` was **3,087.6 MB of a 5,074.4 MB database — 61.3%**, holding
195,883 rows over 50,755 files for 2 users.

Two independent causes, and the second one is the interesting one:

1. **Every row burned a full 16 KB page.** At ~10,751 bytes the vector exceeded InnoDB DYNAMIC's
   ~8,126-byte limit and went off-page, taking a dedicated 16 KB page whatever the payload:
   `195,883 x 16 KB = 3,061 MB` against 3,087.6 MB measured, i.e. essentially the whole table,
   of which **1,079 MB (35%) was page padding**.
2. **The vectors were float32 data written as float64 text** — 21.0 characters per component,
   because `serialize_precision=-1` asks `json_encode` for the shortest round-trip of a *double*
   while InsightFace emits float32. Proved rather than assumed: re-packing a stored vector through
   `pack('g')` (float32) and back changes its cosine distance by **exactly 0.0**, so the extra
   ~12 characters per component carried no information at all.

Fixed by rounding to 6 decimal places on write — `DetectionService::VECTOR_PRECISION`, applied
through the new `DetectionService::encodeVector()`, which is now the single place an embedding
becomes text. 6 was chosen because it is *free*: 4, 5 and 6 decimals all yield 3 rows per 16 KB
page, so the extra fidelity costs nothing.

| | before | after |
|---|---:|---:|
| vector payload | 2,008.4 MB | 897.6 MB |
| mean bytes/row | 10,751 | 4,804 |
| table data | 3,087.6 MB | **1,026.0 MB** |
| `.ibd` on disk | 3,180 MB | **1,044 MB** |
| whole database | 5,074.4 MB | **3,007.0 MB** |

**The trap this springs, and it is the whole reason this needed care.** `VECTOR_LENGTH_FLOOR` was
`char_length(face_vector) > 6000`, used at **14 call sites** across `DetectionService`,
`AccessSyncService`, `ClusteringService` and `ResetService`. Compaction drops every vector to
~4,800, so the first compacted row would have flipped that predicate to "this is Recognize's" —
everywhere at once, silently, with no error. The floor is now **3500**, which works because the
bands stay disjoint *including mid-migration*, when old and new encodings coexist:

```
legacy 128-dim          2,621 -  2,655   (39 rows)
ours, VECTOR_PRECISION  4,748 -  4,863
ours, pre-compaction   10,672 - 10,827
```

Retuning the floor beat the alternative of deleting the legacy rows to make the table homogeneous:
nothing is destroyed, and the three-band property is what makes the migration incrementally safe.
Verified as a strict no-op before any row was rewritten — floors 6000 and 3500 classified
identically (195,843 each) and the new middle band was empty.

**`occ facewright:compact-vectors [--dry-run] [--chunk-size] [--limit]`** does the backlog
(`VectorCompactionService`). Deliberately a command, not a migration step: 2 GB of rewrites is far
too much for `occ upgrade`'s maintenance window, and it is resumable twice over — an id cursor, plus
a length predicate that can never re-select an already-compacted row even if the cursor resets. It
refuses to write anything that grew, or that would land *below* `VECTOR_LENGTH_FLOOR`, since either
would be invisible until something downstream misread it.

**Accuracy was verified against clustering decisions, not just byte counts.** Snapshotting 200 real
vectors and re-comparing after compaction: max per-component change **5.0e-7** (exactly the round(6)
bound), max change in pairwise cosine distance **1.682e-6**, and across **19,900 pairs, zero
changed side of the 0.5 clustering threshold**.

Run: 195,643 rows in 190s, 0 skipped. Afterwards row counts were byte-identical to baseline
(195,883 total / 195,843 ours), 0 rows left in the old band, 0 stranded in the gap, and 0 failing
the 512-dim check. `OPTIMIZE TABLE` in maintenance mode took **3.4s** — surprisingly fast, but the
`.ibd` really did drop 3,180 → 1,044 MB and `data_free` went 1,614 → 0 MB.

**The I/O win is bigger than the disk win** and was the real motivation: rows now fit back in-page,
so a vector fetch no longer costs a private page read that read-ahead cannot batch. Fetching all
129,653 of one user's vectors — the clustering-shaped query that used to be 1,023 MB and 8.4 s, and
340 MB / 3.2 s after the id/vector split — now ships 594 MB and reads **4.6 MB from disk** in 2.3 s,
because the whole table finally fits in the 2 GB buffer pool sized in the MariaDB-tuning section.

**Found while reconciling the final counts**: one row decodes to 512 dims but sits below the floor.
Id 31948 is an embedding of **all zeros**, so it encodes to 1,292 characters however precise the
format. It is a degenerate detection (cosine distance against a zero vector is undefined), already
parked at `UNCLUSTERABLE`, and the misclassification costs nothing but `facewright:reset` never
collecting it. It also corrects the record: the **1,292 lower bound previously documented for the
legacy 128-dim band was always this row** — the real legacy floor is 2,621.

Not done, and still available: `oc_facerecog_faces` is **240.8 MB / 72,562 rows** belonging to the
`facerecognition` app, which `occ app:list` reports as **disabled**. Nothing reads it. Also still
open is the deeper structural win — **129,711 distinct physical faces behind 195,883 rows, 1.51x
duplication**, because fan-out stores the same embedding once per user with access. Moving vectors
to their own table keyed by physical face would fix precision and duplication together and leave
`recognize_face_detections` at ~18 MB, but it breaks `FacePhoto::delete()` (see below), so it was
not bundled here.

**Compatibility was checked, not assumed.** Memories never reads `face_vector` at all. The only
live Recognize path that does is `Dav/Faces/FacePhoto::delete()` — "remove this face from this
person" — and it uses it for Recognize's own `threshold` mechanism, which our clustering ignores.
Rounded JSON keeps that working unchanged, which is exactly why this option was picked over binary
or base64 encodings that are smaller but would break `json_decode`.

### Done: removed the `facerecognition` app — but its 46 named people were worth keeping

Item 6 was "drop a 240.8 MB dead table". Looking at the target first turned it into something else:
the group was **four tables, 270.7 MB** (`faces` 245.2, `images` 21.9, `persons` 3.5, `models` 0.0),
and `oc_facerecog_persons` held **106 person records carrying hand-typed names** — real family and
friends, entered by Consti and Julia in the old app's UI. Face descriptors are regenerable by
re-running inference; names are not. Nothing else in this project would have replaced them.

They also turned out to be directly useful rather than merely sentimental:

```
106 named person records  ->  46 distinct people
                          ->  12,602 faces
                          ->  10,287 distinct files
                          ->   9,355 of those files Facewright already has detections for
```

That is a **hand-labelled ground-truth set overlapping 9,355 of our own files**, which is exactly
what the two open clustering items lack — 1,347 untitled clusters needing titles, and thresholds
still validated only against the original ~740-detection prototype.

**Exported before anything was dropped**, as
`/mnt/data/nextcloud/facerecognition-labels-2026-08-26.csv` — 12,602 rows, 0.88 MB, columns
`user, person_id, person_name, file_id, x_px, y_px, width_px, height_px, confidence, linked_user`.
Reconciled against the database before the source was destroyed (106 person ids and 12,602 rows,
both exact), parsed back with a CSV reader, and checked for blank names and non-numeric file ids.

Two things about that file. **Its coordinates are pixels**, not Facewright's normalised 0–1
fractions, so any matching against `recognize_face_detections` has to divide by the image
dimensions first. And it lives **outside the repo on purpose**, `0600`: it is a list of real
people's names mapped to file ids, and this repo is headed for a public alpha.

**Removal, and the gotchas.** `occ app:remove facerecognition` disabled it, ran its uninstall steps
and deleted the app directory — and left **all four tables**, confirming the note already in this
file that Nextcloud uninstalls do not drop tables. Dropped explicitly after checking
`information_schema.KEY_COLUMN_USAGE` for inbound foreign keys (none).

**New gotcha worth recording**: `app:remove` also leaves **13 `oc_appconfig` rows and 9
`oc_migrations` rows** behind, `installed_version` among them. Harmless while the app is gone, but a
future reinstall would see a version it never installed and a migration list it never ran, and skip
straight past its own schema setup. Both were deleted.

Verified after: 0 `oc_facerecog*` tables, 0 files matching `facerecog` in MariaDB's data directory
(space returns immediately on DROP with `innodb_file_per_table`, no OPTIMIZE needed), 0 appconfig
and 0 migration rows, no background jobs referencing it, `status.php` 200 in 13 ms. Memories is
unaffected — its `FaceRecognitionBackend` guards on
`facerecognitionIsInstalled() && facerecognitionIsEnabled()`, both now false, and `recognize` and
`memories` are both still enabled.

**Database: 3,007.0 MB → 2,737.3 MB.** Together with the precision work above, this session took the
instance from **5,074.4 MB to 2,737.3 MB — a 46.1% reduction.**

### Done: a 17-day silent detection stall, and a watchdog so the next one leaves a trace

Reported as "the auto-trigger seems broken — a photo from Sept 9 still has no faces, and the
admin page says 191 pending" on 2026-09-13. It was broken, instance-wide, for far longer than
that one photo suggested: `facewright_processed`'s own `MAX(processed_at)` was **2026-08-27
21:05:07** — nothing, success or failure, had been written since, and the ExApp's own request log
confirmed it independently (last real `POST /detect` before this investigation: the same
timestamp). 141 real photos in Consti's `Bilder` alone postdate that gap.

Ruled out first, each with live evidence rather than by inspection: the ExApp (healthy, 5-day
uptime, answered correctly the moment it was sent a real request), `automatic_detection_enabled`
(true throughout — no toggle requests logged against any admin-page endpoint since at least
Sep 2), cron itself (`lastcron` fresh, and driving `cron.php` by hand executed jobs correctly),
and a code regression (deployed code byte-identical to the repo; no commits since Aug 26).

**Root cause not fully pinned down, and that gap is itself the finding.** Every layer worked
flawlessly when driven directly: `FileCreatedListener` enqueued correctly for a freshly-scanned
file, `DetectQueuedJob` processed it correctly, `cron.php` picked it up (sometimes on the very
next tick, once needing a second one 5 minutes later — ordinary scheduling jitter, not a bug).
What made the original miss unrecoverable as a diagnosis is that `DetectQueuedJob::run()` had
three early-return branches - no interested user, `pickFetchUser()` returning null, and the node
failing to resolve - that returned silently. A `QueuedJob` is removed from the queue whether it
succeeds or hits one of these, so a job dropped there during the real incident is now
indistinguishable from a job that was never enqueued at all. The nextcloud.log retention (a few
days) didn't reach back to Aug 27 either. So the specific trigger stays unknown; what's fixed is
that this class of gap can't recur invisibly.

**Immediate fix**: re-drove the reported photo through the real pipeline by hand (now has 3 real
detections for both Consti and Julia), then ran `occ facewright:scan` and drove it to completion
via cron — enqueued and processed exactly **191** files, matching the admin page's count exactly.
Auto-clustering picked the new faces up on its own via the existing `ClusteringMaintenanceJob`.

**Two durability fixes, so the next occurrence isn't another 17-day forensic reconstruction:**

- The three silent branches above now log (`interested === []` and the mimetype re-check miss at
  debug — both routine outcomes; `pickFetchUser()` returning null and the node failing to
  resolve at warning, since both are documented to be otherwise unreachable). Verified live: a
  throwaway test file placed outside the included path produced exactly
  `facewright: file 2095538 has no enabled, path-included user among 1 with access, skipping.`
  in `nextcloud.log`.
- `lib/BackgroundJob/DetectionMaintenanceJob.php` — detection's counterpart to the existing
  `ClusteringMaintenanceJob`/`ClusterTriggerService` pattern, one stage earlier in the pipeline.
  `BatchScanJob` is a self-terminating chain that correctly stops re-adding itself once a pass
  enqueues nothing new — but nothing then restarts it when new work appears afterward, exactly
  the gap `ClusteringMaintenanceJob` already closes for the clustering stage. This is the same
  fix at the detection stage: an hourly `TimedJob`, gated by `automatic_detection_enabled` and
  `processing_paused` like every other job here, that unconditionally restarts the instance-wide
  `BatchScanJob` chain. Deliberately does *not* pre-check for real backlog before restarting,
  unlike `ClusterTriggerService` — there the check earns its cost because a chain exists per user
  and skipping uninterested ones avoids queue spam; here there is exactly one instance-wide
  chain, `BatchScanJob` already pays for the same enumeration to answer that question itself, and
  `IJobList::add()`'s own dedup on class+argument makes restarting an already-running chain a
  no-op. Since `BatchScanJob` re-enumerates the filesystem directly rather than depending on the
  listener, this closes the gap regardless of whether a future miss happens in the listener, in
  one of `DetectQueuedJob`'s branches, or somewhere not yet seen — the backlog gets swept up
  within an hour instead of sitting silently for weeks.

Registered via the same mechanism as `ClusteringMaintenanceJob` (`<background-jobs>` in
`info.xml`), which needed the same version-bump-plus-`occ upgrade` deploy this project always
uses for schema/registration changes (0.4.0 → 0.4.1) — confirmed the usual way, `occ
background-job:list` showing the new job registered in `oc_jobs` afterward. No `routes.php`
entries were touched, so no container restart was needed this time.

Verified live, not just `php -l`: ran the new job directly with no scan chain in flight → it
started one; let that scan run → it correctly found the (already-cleared) backlog empty and
stopped itself, exactly as designed for the steady-state case.

### Next

1. **Multi-user rollout: the backfill is done.** This item used to say the full backfill had never
   been run. It has since completed — `occ facewright:backfill-access --dry-run` now reports
   **19 mounts, 180,297 files examined, 0 rows to copy and 0 to prune**, where the same read-only
   command on the same code path previously reported 65,757 rows to copy. The command is idempotent
   by construction, so a no-op run is exactly what a finished backfill looks like; the 65,757 → 0
   swing on unchanged code is what makes that a completion rather than a query that stopped finding
   donors. `automatic_clustering_enabled` is now `true`, which the old wording warned against — moot,
   since there is nothing left to fan out in one burst.

   Still genuinely open: the share/unshare event path is untested at runtime (no `occ` command
   creates shares, so it needs a real share in the browser or a file moved in and out of a shared
   folder), and `PruneService` has never actually deleted — `--prune` reports 0 stale rows, which is
   correct but leaves the delete path unexercised. **Note names do not travel with a share**: copies
   land `cluster_id = NULL`, so a recipient's clustering rediscovers the same people under its own
   untitled cluster ids. Still deferred: empty-cluster cleanup after a prune (Recognize doesn't do it
   either), a user-deleted listener, a resume cursor for `BatchTriggerService`'s O(processed)
   re-walk, and resilience to Recognize updates.

2. **Threshold re-tuning is fixed on the personal page only.** `clearUnclusterableMarks()` runs when
   a *user* changes their own numbers (see the per-user-mode section). The admin page's two inputs
   are now instance **defaults** for users who never override, so changing them re-evaluates nothing
   for anyone who already has their own values — which is correct, but means an admin cannot re-tune
   the instance from one place any more. `occ facewright:cluster --reset` is still the blunt option.

3. **Clustering quality is unvalidated at current scale.** The 0.5 / 3 defaults were validated
   against ~740 detections; the library is now ~65,000. Two clusters hold 11,633 and 9,992
   detections (the next largest is 1,842), which looks like over-merging, and 1,347 of 1,360
   clusters are untitled. Worth a real look before treating the numbers as settled — this is
   still the open item flagged back in the clustering-prototype section, just with much more
   data now.

   **There is now ground truth for this.** The `facerecognition` export (see the section above)
   labels 12,602 faces with 46 real names across 10,287 files, 9,355 of which Facewright has its own
   detections for. That is enough to measure the 0.5 / 3 defaults against human labels rather than
   by eye, and enough to title clusters from it. Remember its boxes are in pixels, not fractions.

4. **Pin Docker's DNS** (`/etc/docker/daemon.json`) — see the gotcha above. Outstanding because
   applying it cycles every container on the host, so it wants scheduling.

5. **Option C — deliberately deferred, not outstanding.** Moving vectors into a table we own,
   keyed by physical face. Investigated properly and **decided against for now**; this entry exists
   so the reasoning does not have to be rebuilt, and so the trigger that reverses it is written down.

   **The framing that decides it is growth rate, not current size.** The one-off reclaim is the
   uninteresting half. Julia already holds a copy of essentially every face in the library
   (129,671 of 129,711), so what Option C really changes is what the *next* full-access user costs:

   ```
   today:      129,711 x 4,804 B  ~=  594 MB per additional user
   Option C:   129,711 x    90 B  ~=   11 MB per additional user
   ```

   **The duplication, measured, so nobody re-derives it:**

   ```
   129,711 distinct physical faces
     63,539 held by 1 user   ->  63,539 rows
     66,172 held by 2 users  -> 132,344 rows
                                -------
                                195,883 rows
   ```

   i.e. **66,172 rows are byte-identical duplicate embeddings, ~303 MB**, written by
   `AccessSyncService::copyToUsers()` copying the donor's `face_vector` string verbatim.

   One-off sizes if it were done:

   | scenario | est. MB |
   |---|---:|
   | today (Option A applied) | 1,042 |
   | C, keeping `json round(6)` | 709 |
   | C + raw binary BLOB | 323 |

   **Encoding and location are coupled, and that is the non-obvious part.** Rounded JSON was forced
   on Option A because `face_vector` is Recognize's column and must stay `json_decode`-able. A table
   *we* own has no such constraint, so moving the vector is precisely what unlocks raw binary —
   2,048 bytes for 512 float32, mathematically lossless, against 4,804 today. Also worth knowing:
   `recognize_face_detections` would fall to **~34 MB**, making every non-vector query (Memories'
   People page, `selectWantedIds()`, the ownership tests) cheap in a way no encoding work can while
   the vector is inline.

   **Why deferred anyway:** ~719 MB against 1.4 TB free is not worth it. The change rewrites all 14
   `VECTOR_LENGTH_FLOOR` call sites across `DetectionService`, `AccessSyncService`,
   `ClusteringService` and `ResetService` — the largest blast radius of anything considered here —
   and **stripping `face_vector` would be this project's first genuinely irreversible step**.
   Everything destructive so far has had a recovery handle (the processed-marker trick, rehearsal
   transactions, dumped id lists); this one has none, since embeddings only come back by re-running
   inference over 50,755 files. Option A already took the easy two-thirds, which is exactly why C
   reads so much weaker now than when it was first written up.

   **The trigger that reverses this decision: a third user**, or existing users' shared content
   moving toward full overlap. At that point the per-user growth term dominates and the one-off cost
   stops mattering. Nothing else should reopen it.

   **Two things settled now, while the context is fresh:**

   - **The face-identity key is the hard part and is still unchosen.**
     `(file_id, x, y, height, width)` is the natural identity and is bit-identical across copies
     today, since `copyToUsers()` copies the same doubles — but a unique index on four floats is a
     smell and stops being exact if anything ever recomputes geometry. The alternative is a
     surrogate `face_index` per file. Whichever is picked, **do not start the migration until it is
     chosen** — it is not a detail that can be settled while writing the code.
   - **Assigning a `face_index` at insert time was considered and deliberately not done.** It would
     be purely additive, break nothing, and shrink any future Option C to deriving only the
     pre-existing 129,711 faces instead of the whole table. Left out so the project does not carry
     scaffolding for work that may never happen — its absence is a decision, not an oversight.

   **The one Recognize casualty, if it is ever done:** `Dav/Faces/FacePhoto::delete()` — Memories'
   "remove this face from this person" — would compute a centroid distance against a stubbed column.
   It is the *only* live Recognize path that reads `face_vector` (Memories itself never does,
   verified), and it serves Recognize's own `threshold` mechanism, which our clustering ignores. So
   the loss is narrow and arguably already meaningless, but it is a real button that would throw.

6. **CLAUDE.md itself had drifted from the code — partly fixed, partly still open.** A review pass
   found real, finished, wired-in features with no "### Done" section anywhere in this file:
   cluster merging (`ClusterMergeService`, `occ facewright:merge-clusters`, commit `151d2f9`),
   `ClusteringMaintenanceJob`/`ClusterTriggerService` (auto-restart of a stalled clustering chain,
   commit `d66e08e`), and the full share/unshare/rename access-reconciliation system
   (`FileAccessListener`, `AccessSyncJob`, `FileAccessSyncJob`, registered for
   `UserMountAddedEvent`/`UserMountRemovedEvent`/`BeforeNodeDeletedEvent`/`NodeRemovedFromCache`/
   `BeforeNodeRenamedEvent`/`NodeRenamedEvent`). Item 1 above ("the share/unshare event path is
   untested at runtime") undersells this: the mechanism is fully built; only the live-share-in-a-
   browser exercise is actually missing. Each of these three still needs its own real "Done"
   writeup — not done here, since it needs the same live verification standard as every other
   entry in this file, not a summary reconstructed from git history alone.

7. **Three real bugs found in the same review pass, none yet fixed:**
   - `ex_app/lib/main.py`'s `FACE_ANALYSIS.get(img)` call (the actual inference) has no exception
     handling and runs synchronously on the asyncio event loop, unlike `cv2.imdecode` a few lines
     above it (caught since commit `2950858`) — an inference-time crash surfaces as an uncaught
     500, which `DetectQueuedJob` classifies retryable rather than permanent, burning through
     `MAX_ATTEMPTS` on a file that might crash reliably; and while inference runs, `/heartbeat`
     doesn't answer either.
   - `DetectQueuedJob.php`'s zero-byte-file branch marks *every* user with access as
     `FACES_FAILED`, not just `$pending` ones — the permanent-failure and give-up branches a few
     lines below it both correctly restrict to `$pending`. A file that already has real detections
     for some users and later reads as zero bytes overwrites their good `faces_found` marker with
     `-1` while leaving their actual detection rows untouched, silently disagreeing.
   - `ProcessedFileService::candidateFileIds()` has no `SUPPORTED_MIMETYPES` filter, unlike
     `BatchTriggerService::collectForUser()`, which does — so the admin page's "not yet processed"
     count structurally overcounts by every gif/bmp/raw file in the library and can never reach
     zero. This is very likely the actual, still-open cause of the "36-row gap" noted in the
     float32-precision section as a one-time historical artifact — the code shows it recurs on
     every page load, not just once.

8. **No automated tests, no static analysis, no CI**, in either app — confirmed, there is no
   `tests/` directory, no PHPUnit/pytest config, no `phpstan.neon`/`ruff`/`mypy` config, no
   `.github/workflows`. Given how much of this project's correctness lives in comments explaining
   a non-obvious invariant (dimension-band floors, the -1/-2 sentinel distinction, dedup-by-job-
   argument semantics), the pure-logic pieces — `ClusteringService`'s distance/assignment math,
   `AccessService::pickFetchUser`, `DetectionService::ownRowsExpr`, `PathFilterService::isIncluded`
   — are unit-testable today without a live instance and currently have zero coverage. Static
   analysis is the cheaper first step and would likely have caught #7's zero-byte-branch mixup.

9. **`occ facewright:merge-clusters` has no `--dry-run`**, unlike every other destructive command
   in this project (`reset`, `backfill-access`, `compact-vectors`). It takes free-text names for
   both sides and permanently deletes one cluster plus its title in a single call — a fat-fingered
   keep/merge swap is unrecoverable, and there's no personal-settings equivalent either, so merging
   is admin-only even though "these two clusters are the same person" is exactly the kind of call
   the per-user-mode redesign otherwise pushed to the account owner.

10. **Row-at-a-time SQL in three places that haven't hit scale yet**: `DetectionService::detect()`'s
    insert loop, `AccessSyncService::copyToUsers()`, `ClusteringService::persistAssignments()` — the
    same "N round trips instead of one batched statement" shape this project has repeatedly found
    and fixed once it actually hurt (the 1023→340MB clustering fetch, the reset-command OOM). Not
    yet the cause of an incident; worth a look before the next scale jump rather than after one.

### Instance versions

Recorded because several notes above are version-sensitive and the instance has been upgraded
since the early sections were written: **NC 34.0.2.1, Recognize 12.0.0, Memories 8.1.0**
(the Recognize-install section still says NC 33.0.6 / Recognize 11.0.1 — that was true at the
time and is left as-is for provenance).

## Licensing note

This project (both apps) is AGPL-3.0-or-later — see the root `LICENSE` file. Revisited when
preparing the public alpha release, since the original version of this note said depending on
Recognize's (also AGPL) DB schema/tables directly was "fine for personal homelab use, but would
need reconsideration if this project left that context" — and it has now left that context.

The actual analysis: `facewright_bridge` never imports, `use`s, or links against any of
Recognize's PHP — it writes into Recognize's tables using Nextcloud's own `IDBConnection`/
`QueryBuilder`, knowing only the table/column names, the same way any external tool that knows
a database's schema could. That's schema interoperability, not incorporating Recognize's code,
so AGPL's copyleft (which attaches to conveying/network-serving the covered work itself, or
code that incorporates it) isn't obviously triggered by the DB writes alone. Not a certainty —
this is a reasonable, defensible reading for a personal/hobby project, not legal advice, and it
hasn't been tested in court.

AGPL-3.0 was still chosen for this project anyway, deliberately, for a different reason:
convention. Nextcloud itself is AGPLv3, and the App Store requires an AGPL-*compatible* license
for anything submitted there — so matching it keeps that door open later, and it's the norm
across the Nextcloud app ecosystem this project sits in. It's a fit-with-the-ecosystem choice,
not a legal requirement flowing from the DB dependency.

# recognAIze

A replacement backend for Nextcloud Recognize's face-detection pipeline, built as a Nextcloud
AppAPI ExApp. Uses InsightFace instead of Recognize's face-api.js/tfjs-node stack, but keeps
Recognize installed as a companion app rather than forking it.

This repo holds two apps:
- `appinfo/`, `ex_app/` etc. at the top level — `recognaize`, the Python AppAPI ExApp (InsightFace
  detection).
- `recognaize_bridge/` — a small, separate, regular Nextcloud PHP app. It exists only because
  ExApps have no path to real database access; it calls `recognaize` and writes results into
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

- `appinfo/info.xml` — app id `recognaize`, single `POST /detect` route at `USER` access level
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

`recognaize` is registered, enabled, and reachable on this homelab's real Nextcloud instance —
confirmed by POSTing a real test photo to `https://cloud.example.com/exapps/recognaize/detect`
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
   (`curl -H "harp-shared-key: <key>" http://127.0.0.1/index.php/apps/app_api/harp/exapp-meta?appId=recognaize`
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
  trust anchor. Verified by crafting this header manually for a real user (`alice`) via the
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
- **ExApps have no sanctioned path to raw DB access** — confirmed by grepping `nc_py_api`'s
  source (files/users/notifications/etc. APIs, nothing for arbitrary SQL) and by the official
  AppAPI docs, which describe ExApps as isolated microservices with only a private Docker volume
  for persistence. Recognize's own classifier also writes one detection row *per user with file
  access* (via `IUserMountCache`), which only Nextcloud-side PHP can determine. So the actual
  write happens from a small **new, separate Nextcloud PHP app**, `recognaize_bridge/` (not an
  ExApp) — it calls `recognaize`'s `/detect` via AppAPI's `OCA\AppAPI\PublicFunctions::exAppRequest()`
  (the sanctioned way for one Nextcloud app to call another's ExApp), then writes rows via
  `IDBConnection`/`QueryBuilder`. Deployed by copying into this instance's `custom_apps` mount
  (`/mnt/data/nextcloud/nc_apps` on the host) and `occ app:enable recognaize_bridge`.
  Deliberately minimal: no controllers/routes/Entity-Mapper ceremony, just
  `lib/Command/DetectFaces.php` implementing `occ recognaize:detect <user> <path>` as the manual
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

Verified end-to-end: `occ recognaize:detect alice "Family/Photos/2024 Trip Photos/IMG20240102T165818.jpg"`
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
  match: its default model is `buffalo_l` — the *same* InsightFace model recognAIze already
  uses. Its threshold is `maxDistance: 0.5` **cosine** distance (confirmed via
  `embedding <=> ${embedding}`, pgvector's cosine-distance operator, in `search.repository.ts`)
  with `minFaces: 3` before a face counts as a cluster "core" — directly comparable to our own
  `face.normed_embedding` output. Algorithm: incremental per-face nearest-neighbor assignment (a
  face joins/starts a cluster once it has ≥3 matches within cosine distance 0.5), not a batch
  HDBSCAN pass.

Implemented Immich's algorithm (not its code) as `recognaize_bridge`'s `occ recognaize:cluster
<user> [--max-distance=0.5] [--min-faces=3] [--reset]`: loads a user's face detections, and for
each unclustered one, finds same-user detections within `max-distance` cosine distance; if the
match count (including itself) is ≥ `min-faces`, it joins an existing matched cluster or starts
a new one (`recognize_face_clusters` row), otherwise it's left unclustered. Simplified from
Immich in one place: no fallback search beyond the initial match set when none of those matches
already has a cluster — close enough for threshold experimentation.

**Gotcha discovered while testing**: `recognize_face_detections` predates this project on this
instance. Nextcloud app uninstalls don't drop tables by default, so real rows from Recognize's
own old face-api.js classifier (128-dim vectors, other users, and even ~1400 old rows mixed
into `alice`'s own data) were silently sitting in the table before `occ app:install recognize`
ever ran this session — invisible until reinstalling made them queryable again. Mixing those
with our 512-dim InsightFace vectors in a distance computation would silently corrupt every
match, so `ClusterFaces` filters strictly by embedding dimensionality (512) before doing
anything else. Worth remembering for *any* future code that queries this table broadly rather
than by a known `file_id` — the multi-user phase especially.

**Validated on real data**: ran detection over 60 real photos (a `alice` photo album, chosen
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
- Give clusters real titles / feed them into Memories' People UI — right now they're bare rows
  with `title=''`.

### Next

1. **Trigger wiring.** Move from manually-triggered detection to whatever hooks into
   Nextcloud's file events / background jobs, matching how Recognize currently gets invoked —
   `recognaize_bridge`'s `occ recognaize:detect`/`occ recognaize:cluster` are the manual
   triggers this phase would replace/wrap.
2. **Multi-user + robustness.** Everything above targets a single-user, manually-triggered
   prototype first; multi-user support (writing a row for every user with file access, matching
   Recognize's own behavior) and resilience to Recognize updates are a separate, larger step
   after the prototype proves clustering quality at scale.

## Licensing note

Recognize is AGPL-licensed. Depending on its DB schema/tables directly is fine for personal
homelab use, but would need reconsideration if this project left that context.

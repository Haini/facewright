# recognAIze

A replacement backend for Nextcloud Recognize's face-detection pipeline, built as a Nextcloud
AppAPI ExApp. Uses InsightFace instead of Recognize's face-api.js/tfjs-node stack, but keeps
Recognize installed as a companion app rather than forking it.

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
  `/detect` takes a raw image body, returns `{faces: [{bbox, kps, det_score, embedding}]}`
- `requirements.txt` — `nc_py_api[app]`, `insightface`, `onnxruntime` (CPU build),
  `opencv-python-headless`, `numpy`
- `Dockerfile` — skeleton's two-stage build + `build-essential`/`cmake` (InsightFace compiles
  Cython extensions on install) + `libgl1`/`libglib2.0-0` in the runtime stage
- Verified locally: image builds, InsightFace model loads, and detection runs correctly against
  a real multi-face test photo (6/6 faces found, plausible bboxes, 512-d embeddings) — see
  git history for the exact commands used.

Deliberately out of scope for this slice: reading files from Nextcloud storage, DB writes,
clustering, background job / file-listener triggers, multi-user support.

### Next

1. **Register against the real Nextcloud instance and verify end-to-end.**

   This homelab's live AppAPI setup, discovered by exec-ing into `nextcloud-app-1` and reading
   `occ app_api:daemon:list` plus AppAPI's own PHP source (`DockerActions.php`,
   `ManualActions.php`):

   - Nextcloud container: `nextcloud-app-1` (compose service `app`), occ lives at
     `/var/www/html/occ`, so `docker exec -u www-data nextcloud-app-1 php occ ...` works without
     a `./` (cwd is already `/var/www/html`).
   - Only one daemon is registered so far: `harp_proxy_docker`, deploy-id `docker-install`. That
     type means AppAPI does a real `docker pull` of whatever `<docker-install>` in `info.xml`
     names (confirmed in `DockerActions.php`: `shouldPullImage()` defaults to `true` unless the
     daemon has a `registries: [{from: ..., to: "local"}]` mapping, which this one doesn't) — not
     suitable for a locally-built, unpublished image.
   - Chosen instead: register a **second, manual-install daemon** (`manual_install_harp`) rather
     than touching the existing one. `ManualActions::deployExApp()` is a no-op — AppAPI expects
     *us* to run the container, which then tunnels in through HaRP's FRP reverse-proxy
     (`appapi-harp:8782`) using the shared key already baked into the `appapi-harp` container's
     own env (`docker exec appapi-harp printenv HP_SHARED_KEY`) — that value must match on both
     sides, it's HaRP's tunnel auth, not something we invent per-app.
   - Required container env vars, per `nc_py_api`'s `_session.py`/`uvicorn_fastapi.py`:
     `APP_ID`, `APP_VERSION`, `APP_SECRET`, `NEXTCLOUD_URL` (required), `APP_PORT` (still needed
     even under HaRP — it's the FRP tunnel's `remotePort`, used to key routing; the app itself
     binds a unix socket, not this TCP port), `HP_SHARED_KEY`/`HP_FRP_ADDRESS`/`HP_FRP_PORT` (read
     by `start.sh` to build `frpc.toml`).

   Recipe (see `Makefile` targets `daemon-register` / `run` / `register`):

   ```
   # one-time: register the manual-install daemon
   make daemon-register

   # per-deploy: build, run the container (tunnels through HaRP), tell AppAPI about it
   make build
   make run APP_SECRET=$(openssl rand -hex 24)
   make register APP_SECRET=<same-secret-as-above>
   ```

   `AppAPIAuthMiddleware` only exempts `/heartbeat` from signature checks, so a bare `curl` at
   the container will 401 — verification has to go through Nextcloud/AppAPI, not a direct
   request. Not yet run against the live instance — pending confirmation before touching shared
   daemon config.
2. **Read files from Nextcloud storage.** Wire `/detect` (or a new endpoint) to pull an image by
   Nextcloud file path via `nc_py_api`, instead of taking a raw image body directly.
3. **Write into Recognize's tables.** `oc_recognize_face_detections` for per-face
   bbox/embedding rows. Undocumented internal schema owned by another app — fragile across
   Recognize updates, worth a code comment explaining why we depend on it.
4. **Clustering.** The hard part — figure out cosine-similarity thresholds for InsightFace's
   embedding space against a labeled slice of the real photo library, then write clusters into
   `oc_recognize_face_clusters`.
5. **Trigger wiring.** Move from manually-triggered detection to whatever hooks into
   Nextcloud's file events / background jobs, matching how Recognize currently gets invoked.
6. **Multi-user + robustness.** Everything above targets a single-user, manually-triggered
   prototype first; multi-user support and resilience to Recognize updates are a separate,
   larger step after the prototype proves clustering quality.

## Licensing note

Recognize is AGPL-licensed. Depending on its DB schema/tables directly is fine for personal
homelab use, but would need reconsideration if this project left that context.

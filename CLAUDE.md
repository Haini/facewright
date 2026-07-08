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

### Next

1. **Read files from Nextcloud storage.** Wire `/detect` (or a new endpoint) to pull an image by
   Nextcloud file path via `nc_py_api`, instead of taking a raw image body directly.
2. **Write into Recognize's tables.** `oc_recognize_face_detections` for per-face
   bbox/embedding rows. Undocumented internal schema owned by another app — fragile across
   Recognize updates, worth a code comment explaining why we depend on it.
3. **Clustering.** The hard part — figure out cosine-similarity thresholds for InsightFace's
   embedding space against a labeled slice of the real photo library, then write clusters into
   `oc_recognize_face_clusters`.
4. **Trigger wiring.** Move from manually-triggered detection to whatever hooks into
   Nextcloud's file events / background jobs, matching how Recognize currently gets invoked.
5. **Multi-user + robustness.** Everything above targets a single-user, manually-triggered
   prototype first; multi-user support and resilience to Recognize updates are a separate,
   larger step after the prototype proves clustering quality.

## Licensing note

Recognize is AGPL-licensed. Depending on its DB schema/tables directly is fine for personal
homelab use, but would need reconsideration if this project left that context.

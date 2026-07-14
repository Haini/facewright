# Facewright

An InsightFace-based replacement for the face-detection half of [Nextcloud
Recognize](https://github.com/nextcloud/recognize)'s pipeline, built as a companion app rather
than a fork.

**Status: early public alpha.** This is a single-user-tested prototype, not a finished product -
see "Current limitations" below before relying on it. But it **does show** that **proper** face 
recognition for Nextcloud is actually very feasible and easily implementable in a flexible way
using an ExApp that deploys the actual image recognition library. In this case we use the same 
library as [Immich](https://github.com/immich-app/immich) as this works very well already.

**AI and State Disclaimer.** Additionally, this project is built using Claude Code, including 
lots of architectural decisions that come with using an ExApp setup (e.g., split into PHP based
Nextcloud App and external Docker container for image recognition).
It actually does work for my setup and produces great results with the CPU only build on over
54000 pictures that span over 15 years.
But since I directly build upon recognizes tables there is no guarantee that past results will
actually survive properly. Expect to perform some (should only be one table) manual table 
cleanup when you delploy this to your productive instance.

## What this is

Nextcloud's [Memories](https://github.com/pulsejet/memories) app has a People page, but no
face-detection logic of its own - it just reads two tables that Recognize's PHP side populates:
`oc_recognize_face_detections` (bounding boxes + embeddings) and `oc_recognize_face_clusters`
(the grouped "people"). Recognize's own detector uses face-api.js/tfjs-node; this project
replaces that one piece with [InsightFace](https://github.com/deepinsight/insightface), while
leaving Recognize installed (so its migrations still create the schema, and Memories keeps
working normally for everything else Recognize does).

Two apps live in this repo to make that possible:

- **`facewright`** (top-level `appinfo/`, `ex_app/`) - a Nextcloud AppAPI ExApp: a small Python
  service running InsightFace, reachable over HTTP as `POST /detect`. It knows nothing about
  Recognize's database - it downloads one file via Nextcloud's own API and returns bounding
  boxes + 512-d embeddings.
- **`facewright_bridge`** - a regular Nextcloud PHP app. ExApps have no sanctioned path to raw
  database access, and per-user fan-out needs real Nextcloud-side APIs
  (`IUserMountCache` etc.), so this small app is what actually calls `facewright`, then writes
  the results into Recognize's tables via Nextcloud's `QueryBuilder`. It also owns clustering
  (turning raw detections into "people"), file-event/background-job triggers, and an admin
  settings page.

Rejected alternative: patching Recognize's PHP to call an ExApp instead of its Node subprocess.
Smaller diff, but it would inherit Recognize's clustering thresholds (tuned for a different
embedding space) and mean an ongoing rebase against upstream Recognize. See `CLAUDE.md` for the
full design history and every incident/gotcha hit building this, if you want the detail.

## Current limitations

- **Single-user prototype.** Detection is keyed to a file's owner, not fanned out to every user
  with access to a file (unlike Recognize's own behavior).
- **Clustering thresholds are borrowed, not yet broadly validated.** They come from
  [Immich](https://github.com/immich-app/immich)'s InsightFace defaults (cosine distance 0.5,
  min 3 faces), since Immich uses the same `buffalo_l` model. Spot-checked against real photos
  from one library and looked correct, but not yet validated against a large, diverse dataset.
- **No resilience to Recognize's own schema changing.** This project depends directly on
  Recognize's internal tables, which are undocumented and could change in a future Recognize
  release.
- Rename/delete/mount-change handling for the automatic file-event listener is not implemented.

## Quickstart: try it locally

A `docker-compose.yml` spins up a throwaway Nextcloud + DB for testing the PHP side
(`facewright_bridge`) and the `facewright` ExApp together, without touching a real instance:

```
docker compose up -d
bash dev-setup.sh   # first run only: installs AppAPI + recognize + memories, registers the ExApp
```

Then open <http://localhost:8080> (`admin` / `dev-admin-password`), enable the People page and
set an included-paths list under Administration settings > Facewright, and upload some photos.

This registers the ExApp as a **manual-install daemon without HaRP** - on the compose network,
Nextcloud and the `facewright` container can reach each other directly by container name, so the
FRP-tunnel layer the real deployment recipe below uses (to cross a network boundary) isn't
needed here. See `dev-setup.sh`'s comments for a caveat: that specific no-HaRP registration
command is a best-effort translation of the real recipe below, not yet verified end-to-end the
way the HaRP one has been - if `occ app_api:app:list` doesn't show `facewright` as enabled after
running it, compare against `occ app_api:daemon:register --help` on your stack.

## Deploying against a real Nextcloud instance

The real recipe (verified end-to-end against a live AppAPI + HaRP setup - see CLAUDE.md for the
full discovery process and every gotcha hit) is driven by the `Makefile`:

```
cp deploy.env.example deploy.env    # fill in your Nextcloud URL, HaRP cert path, a real secret
make daemon-register                # one-time: registers the manual-install daemon
make build
make run APP_SECRET=$(openssl rand -hex 24)
make register APP_SECRET=<same-secret-as-above>
make verify APP_SECRET=<same-secret-as-above>
```

`make help` lists every target, including troubleshooting ones (`bust-cache`) for a specific
HaRP caching gotcha documented in `CLAUDE.md`.

This recipe assumes an existing AppAPI + HaRP manual-install-daemon setup, matching how AppAPI
is commonly self-hosted outside Nextcloud's official AIO bundle. Adjust `deploy.env` if your
setup differs (different container names, a docker-based daemon instead of manual, etc.).

Once the ExApp is running, `facewright_bridge` needs to be installed the same way any
non-app-store Nextcloud app is: copy (or bind-mount, for active development) the
`facewright_bridge/` directory into your instance's `custom_apps/`, then
`occ app:enable facewright_bridge`. Configure the People-page toggle, included-paths allowlist,
and clustering/scan triggers from Administration settings > Facewright.

## License

AGPL-3.0-or-later - see `LICENSE`. Chosen to match the Nextcloud/Recognize ecosystem this
project sits in, not because it's strictly required by depending on Recognize's database schema
(see `CLAUDE.md`'s "Licensing note" for the actual reasoning).

## More detail

`CLAUDE.md` is this project's running development log - every design decision, incident, and
gotcha hit while building it, in the order it happened. Worth reading if you're extending this
project or hit something that looks like a problem someone here already solved.

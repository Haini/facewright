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
  (`IUserMountCache` etc.), so this small app is what actually calls `facewright`, keeps the
  results in its own tables, and copies what Memories shows into Recognize's tables. It also owns clustering
  (turning raw detections into "people"), file-event/background-job triggers, and an admin
  settings page.

**A People page of its own.** `facewright_bridge` adds a "People" entry to the top bar for curating
what clustering produced: browse people as face crops, rename them, merge two that are the same
person (it suggests likely pairs), delete one, move a face to another person, mark a face as "not
a face", and do any of that to a whole selection at once. Edits made in Memories go through the
same code, so a removal sticks in either place. Memories remains the place to browse photos by
person. Merge suggestions are per person and use an uncalibrated distance threshold
(`suggest_max_distance`, default 0.6).

Rejected alternative: patching Recognize's PHP to call an ExApp instead of its Node subprocess.
Smaller diff, but it would inherit Recognize's clustering thresholds (tuned for a different
embedding space) and mean an ongoing rebase against upstream Recognize. See `CLAUDE.md` for the
full design history and every incident/gotcha hit building this, if you want the detail.

## Current limitations

- **Tested on one instance with two users.** Per-user fan-out (every user with access to a file
  gets rows, as Recognize does) exists, but share/unshare at runtime has only been exercised by
  `occ` command, never by a share created in the browser.
- **Clustering thresholds are borrowed, not yet broadly validated.** They come from
  [Immich](https://github.com/immich-app/immich)'s InsightFace defaults (cosine distance 0.5,
  min 3 faces), since Immich uses the same `buffalo_l` model. Spot-checked against real photos
  from one library and looked correct, but not yet validated against a large, diverse dataset.
- **Memories still needs Recognize's tables.** The data lives in this project's own tables, but
  Memories reads a copy in Recognize's internal tables and sends its edits to Recognize's DAV paths,
  which this app answers. Both are undocumented and could change in a future release (Recognize 10
  already renamed the vector column once); a change there breaks Memories' People page, not the
  data. `docs/independence.md` reviews the coupling, and `docs/own-tables.md` is the plan, ending in
  a change to Memories itself.
- **The People page has only been tried by hand, on a throwaway instance with generated data,**
  not yet on a real library. A face marked "not a face" is forgotten by a full reset
  (`occ facewright:reset`).
- Rename, delete and mount-change handling exists (`FileCreatedListener`, `FileAccessListener`)
  but `PruneService` has never deleted anything on a real instance.

## Requirements

- Nextcloud 33 to 35, with AppAPI (shipped enabled on recent versions), Recognize ≥ 3.8 and
  Memories. Recognize must stay installed and enabled: Memories only shows its People page when it is.
- Docker with BuildKit (the default since Docker 23) to build the ExApp image. Python dependencies
  are pinned exactly in `requirements.txt`, so a clean build gets the versions known to work.
- For a real instance: an AppAPI manual-install daemon behind HaRP (see below).

## Quickstart: try it locally

A `docker-compose.yml` spins up a throwaway Nextcloud + DB for testing the PHP side
(`facewright_bridge`) and the `facewright` ExApp together, without touching a real instance:

```
docker compose up -d   # first run builds the ExApp image, which takes a while
bash dev-setup.sh      # first run only: installs AppAPI + recognize + memories, registers the ExApp
```

This registers the ExApp as a **manual-install daemon without HaRP**: on the compose network,
Nextcloud and the `facewright` container reach each other directly by container name, so the
FRP tunnel the real deployment uses isn't needed. `occ app_api:app:list` should show `facewright`
as enabled afterwards. The InsightFace model (`buffalo_l`) is downloaded when the container first
starts, so the container needs internet access then.

Then open <http://localhost:8080> (`admin` / `dev-admin-password`) and:

1. Put an empty file named `.noimage` at the top of the user's Files (see "Keeping Recognize's
   own face detection off" below for why), **before** turning on the People page.
2. **Administration settings > Facewright:** tick "Show the People page", and either tick
   "Automatically detect faces in new uploads" or press "Scan entire library" there later. Automatic
   detection is off by default, so uploading photos alone does nothing.
3. **Personal settings > Facewright** (per user): tick "Find faces in my photos" and add the
   folders to look at under "Folders to look at". An empty list means nothing is scanned. Users
   are off until they turn this on (only users who had already logged in when the app was first
   enabled start switched on).
4. Upload some photos into one of those folders. Grouping into people needs at least three faces
   of the same person by default; results show on the People page in the top bar and in
   Memories.

## Keeping Recognize's own face detection off

Turning on the People page sets Recognize's `faces.enabled` to `true`, because Memories reads it.
Recognize also uses that same setting to decide whether to run its **own** face detection on new
uploads. Left alone, it then writes its own faces next to ours and starts its own clustering
job, which cannot handle Facewright's embeddings and can use several GB of memory. Two steps
prevent this:

- **An empty `.noimage` file at the root of every user's Files.** Recognize's ignore marker makes
  it skip everything below it; Facewright does not honour it and keeps working normally. Add it
  for each new user too. Do this before any photos are processed: on an instance where Recognize
  already has face data, adding or removing the marker makes Recognize delete or re-classify
  every file below it, so read "Coexisting with Recognize" in `CLAUDE.md` first.
- **Remove Recognize's `MaintenanceJob` after installing, upgrading or re-enabling Recognize**
  (it is registered again each time):

  ```
  occ background-job:list --class='OCA\Recognize\BackgroundJobs\MaintenanceJob'
  echo y | occ background-job:delete <id>
  ```

  Delete any `OCA\Recognize\BackgroundJobs\ClusterFacesJob` the list shows as well.

After adding markers on a running instance, restart the web container: Recognize caches the
ignore list in APCu with no expiry (background jobs run from cron are not affected).

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

`make verify` checks `/heartbeat` through the real Nextcloud/HaRP path; it does not run a
detection. `make help` lists every target, including troubleshooting ones (`bust-cache`) for a
specific HaRP caching gotcha documented in `CLAUDE.md`.

This recipe assumes an existing AppAPI + HaRP manual-install-daemon setup, matching how AppAPI
is commonly self-hosted outside Nextcloud's official AIO bundle. Adjust `deploy.env` if your
setup differs (different container names, a docker-based daemon instead of manual, etc.).

Once the ExApp is running, `facewright_bridge` needs to be installed the same way any
non-app-store Nextcloud app is: copy (or bind-mount, for active development) the
`facewright_bridge/` directory into your instance's `custom_apps/`, then
`occ app:enable facewright_bridge`. Then follow "Keeping Recognize's own face detection off"
above, and configure as in steps 2 and 3 of the quickstart: the People-page toggle, triggers
and clustering defaults under Administration settings > Facewright, and each user's on switch
and folders under Personal settings > Facewright.

## License

AGPL-3.0-or-later - see `LICENSE`. Chosen to match the Nextcloud/Recognize ecosystem this
project sits in, not because it's strictly required by depending on Recognize's database schema
(see `CLAUDE.md`'s "Licensing note" for the actual reasoning).

## More detail

`CLAUDE.md` is the developer reference: the architecture, the invariants that are easy to break
silently (dimension bands, cluster-id sentinels, job dedup semantics, column order), the rules for
coexisting with Recognize, and the deployment mechanics. Read it before changing anything that
touches the tables or the job queue.

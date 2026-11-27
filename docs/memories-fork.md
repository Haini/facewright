# Memories fork: Recognize stand-in (Stage G)

The implementation plan for Stage G of `docs/own-tables.md`, written for a session that has not seen this repository
before. Memories' Recognize backend gets one system setting that names the app whose Recognize-format tables and
WebDAV tree it uses. The bridge provides both: a copy of our people data in tables of its own, and a WebDAV tree that
answers Memories' requests. Recognize can then be disabled, and in the end uninstalled (Stage H).

## Where things stand

**M0 to M11 are done; the fork is live since 2026-11-20, and Recognize is disabled since 2026-11-21.** Live runs bridge
0.12.1 and the pinned fork build of Memories 11.1.4 with `memories.recognize.provider=facewright_bridge` and
`projection_targets=own`. Decided 2026-11-21: the Recognize-side machinery goes (Stages F and H of `docs/own-tables.md`,
see "Decision after M11" below and `docs/cleanup.md`). What stays is M12: rebasing the fork on each Memories release.
Each step below keeps what was measured under its "Done".

- **The fork:** https://github.com/Haini/memories (SSH remote `git@github.com:Haini/memories.git`). The branch
  `facewright` is v11.1.4 plus the commit `bbb4f38c` ("Recognize provider setting: read Recognize-format tables and DAV
  tree of another app", 9 files, +70/-20) and the commit `2adf50b6`
  (`scripts/facewright-build.sh`, M2). Both are pushed; the tag `facewright-v11.1.4` marks `2adf50b6`, the build on live.
- **The clone is `/home/constantin/memories-fork`** (`$FORK`), with the remote `upstream` and its tags. Its `build/`
  holds the release tarball, the unpacked release and the fork build.
- **The build on live and dev:** `build/memories-v11.1.4-facewright.tar.gz`, sha256
  `d540449f018bcc46215f8bdc801a9718e206e0ff5a7fac694a77dea3bd4d225c` (builds are not reproducible byte for byte).

## How to work this plan

- Read `CLAUDE.md` first, all of it. The rules in "Our own tables, and Recognize's as a copy" apply unchanged:
  "writers write ours, commit, then push", the sparse copy, shared ids, and "a query by `cluster_id` names `user_id`".
  Then read `docs/own-tables.md` "Where things stand" and Stage G, and `dev/README.md`.
- **Work on the compose dev stack only.** M11 is the only step that touches live, and each action there needs the
  user's go-ahead. Live's names and paths are in the operator's private notes, never in this repo.
- **Pushing to GitHub needs the user's go-ahead**, the first push and every force-push. Nothing is posted on
  pulsejet/memories.
- One commit per step in the repository it changes (this one, or the fork). Each step ends with its **Verify** block
  passing. The proof is the output, not the exit code.
- A checker counts only after it has been seen to fail. Plant the fault the step names, see the check fail, then
  remove the fault.
- Bridge `info.xml` changes (a migration, the Sabre collection) need a `<version>` bump and `occ upgrade`. Check
  `occ app:update --showonly` first: `occ upgrade` also applies pending App Store updates. Dev restores snapshots by
  replaying `DROP`/`CREATE TABLE`, so after a migration: `dev/snapshot.sh restore original`, upgrade, `dev/snapshot.sh
  save original`, keeping the old file under another name (see S1's "Done" in `docs/exapp-clustering.md`).
- `dev/run-all.sh` takes a lock: never start a suite by hand while it runs.
- **Stage F** (`docs/own-tables.md`) also changes `ProjectionService`, and may land first. If it does, rebase M6 onto it.
  Its category B and the legacy band belong to the `recognize` target only.
- At the end of every step, update "Where things stand" above. Write what was measured, not what was expected.

## The design

### The fork

- `github.com/Haini/memories`, a fork of `pulsejet/memories`. The branch `facewright` sits on a release tag (v11.1.4
  now) and carries the change as **one commit** (plus the build script and the admin UI, three commits in all, see below), rebased onto each new tag (M12). The app id stays `memories` and the
  version stays exactly upstream's (`docs/own-tables.md` G.0).
- The change (commit `bbb4f38c` of the fork, on the tag `facewright-v11.1.4`):
  - **The setting.** A system setting `memories.recognize.provider` (default `recognize`, an app id). It is in
    `SystemConfig::DEFAULTS`, and `recognizeProvider()` returns it if it matches `^[a-z0-9_]+$`, otherwise `recognize`.
  - **The tables.** `RecognizeBackend` reads `{provider}_face_detections` / `{provider}_face_clusters` through two
    private helpers, in all 8 places that named a table.
  - **Installed and enabled.** `recognizeIsInstalled()` tests the provider app. The version check (≥ 3.8.0) and
    Recognize's `faces.enabled` apply only to `recognize`.
  - **The API key.** `RecognizeController::apiKey()` returns an empty key for any other provider.
  - **The frontend.** `OtherController` sends `recognize_provider` to the frontend. Its WebDAV root is
    `/{recognize_provider}/{user}`, in `src/services/dav/face.ts` (8 paths) and `src/components/modal/FaceMergeModal.vue`
    (3 paths).
  - **Unchanged.** With no setting the table names and paths are exactly upstream's.
- **The admin UI** (a third commit on the branch, "Admin: choose the face provider from detected apps"; design in
  `docs/memories-fork-admin-ui.md`). Memories' admin page, section "Recommended Apps", gets a dropdown for the setting,
  so no `occ` is needed. It names no provider: `AdminController::getSystemStatus()` returns `face_providers`, the
  enabled apps that have both `{app}_face_detections` and `{app}_face_clusters` tables (plus the value that is set,
  whatever its state), and one note card says whether the chosen one works. `SystemConfig::set()` rejects a value that is
  not an app id (`^[a-z0-9_]+$`), and choosing `recognize` deletes the key, like the rollback below. It is a separate
  commit so it can be dropped or proposed upstream on its own.
- **Pin.** The deployed folder contains an empty `.git/` directory. `Installer::isUpdateAvailable()` returns false for
  such an app (`isInstalledFromGit()`), so the App Store never replaces the fork.
- **No signature.** The build has no `appinfo/signature.json`. Nextcloud verifies a non-shipped app only if it has
  one (`Checker::runInstanceVerification()`).
- **Build.** The official release tarball of the same tag, with `lib/`, `appinfo/`, `templates/`, `l10n/` and `img/`
  replaced from the fork and `js/` rebuilt from it. `bin-ext/` (exiftool, go-vod, vips) stays as released, so no
  binary is fetched or built by us.
  - **The manifest key.** Our `js/` manifest is signed with the default key, not the one Memories' native apps pin. The
    user does not use the Android app.

### The bridge's copy: projection targets

`ProjectionService` writes to one or more **targets**. Each target is a pair of tables in Recognize's format.

| Target | Tables | Who else writes them | Columns |
|---|---|---|---|
| `recognize` (today's) | `recognize_face_detections`, `recognize_face_clusters` | Recognize's handlers | all, `face_vector` `'[]'`, `threshold` 0 |
| `own` (new) | `facewright_bridge_face_detections`, `facewright_bridge_face_clusters` | nobody | only what Memories reads |

- **Which targets: app config.** `facewright_bridge` / `projection_targets` is a comma list. Unset means `recognize`;
  unknown names are ignored with a warning. It is read once per process.
- **Push.** It writes every configured target in the same order as now: people, faces, deleted people. Each target
  gets its own `try`, so a failure in one still writes the others. Any failure sets the one shared dirty flag.
- **Same projection in both.** Sparse (assigned and unassigned only), `cluster_id` the person or `-1`, same ids as ours,
  `INSERT ... SELECT ... ON DUPLICATE KEY UPDATE`.
- **Reconcile per target.**
  - `recognize` is unchanged: A, B, C including squatters and the legacy band, D.
  - `own` has no B, no squatters and no vectors. A is unchanged. C is any row whose id is not a visible face of ours.
    D is unchanged, except that D-stale needs no exemption for rows of Recognize's own.
- **`available()` is per target.** `own` needs only our tables and its own two. Hydrate is `recognize` only.
- **The `own` tables** mirror what `RecognizeBackend` reads, with Recognize's indexes, so Memories' query plans stay
  as they are today. There is no autoincrement, because ids are always written explicitly.
  - `facewright_bridge_face_detections`: `id` BIGINT PK, `user_id` STRING(64) not null, `file_id` BIGINT not null,
    `x`/`y`/`width`/`height` FLOAT (DOUBLE on MariaDB) not null, `cluster_id` BIGINT not null.
    Indexes: `(cluster_id)`, `(user_id)`, `(file_id)`.
  - `facewright_bridge_face_clusters`: `id` BIGINT PK, `user_id` STRING(64) not null, `title` STRING(4000) not null,
    default `''`. Index: `(user_id)`.
  - With the `oc_` prefix both names stay under Nextcloud's limit of 63 characters (`MigrationService::
    ensureNamingConstraints()`, checked on dev). Keep index names short, `fwb_` + up to 20 characters.
- **Why a copy and not a view.** A view over `facewright_user_faces` would need `cluster_id` as a `CASE` expression,
  which no index can serve, and Nextcloud's migrations do not manage views. So `own` is a copy like `recognize`. The
  difference is that only we write it.

### The bridge's WebDAV tree

`/remote.php/dav/facewright_bridge/{user}/...`: the root collection's name has to equal the app id, because the fork
builds the path from the provider setting. The tree has **reads only**. Writes keep going through the existing router
plugin, which learns this second prefix.

| Path | Node | PROPFIND depth 1 | Writes |
|---|---|---|---|
| `facewright_bridge/` | `RootCollection` (`AbstractPrincipalCollection`, `principals/users`) | principals | refused |
| `{user}` | `UserHome`; 403 unless `{user}` is the session user | `faces`, `unassigned-faces` | refused |
| `faces` | `FacesHome` | people with at least one assigned face (`PeopleService::listClusters`, `minCount: 1`), named by title, or id if untitled | router |
| `faces/{p}` | `PersonCollection`, `{p}` resolved by `PeopleService::resolveCluster()` (title or id), 404 otherwise | the person's faces | router |
| `faces/{p}/{id}-{file}` | `FaceFile`: `getChild()` takes the id before the first `-` (as Recognize does), 404 unless it is the user's and in `{p}` | | router |
| `unassigned-faces` | `UnassignedFacesHome` | **405** (Memories never lists it, and the list can be huge) | router |
| `unassigned-faces/{id}-{file}` | `FaceFile`, 404 unless the user's and unassigned | | router |

- **What Memories needs from the tree.** The merge (`FaceMergeModal`) lists `faces/{p}` and then sends `MOVE
  faces/{p}/{basename}` → `faces/{target}/{basename}` for each child. Each child's name must therefore be
  `{userFaceId}-{file name}`, the file name being the user's own name for it. `{userFaceId}` is
  `facewright_user_faces.id`, which is the detection id Memories knows.
- **Faces whose file the user cannot reach are left out.** The node comes from
  `IRootFolder::getUserFolder($uid)->getFirstNodeById($fileId)`, and a face is left out when that is null or a folder.
  This is the same rule Recognize applies.
  - **Known cost:** one lookup per face. The listing is made once per merge, as with Recognize.
- **`FaceFile` is a `Sabre\DAV\IFile`.** `getName()`, `getContentType()`, `getETag()`, `getSize()` and
  `getLastModified()` come from the file. `get()` returns `fopen('r')` on the user's own file. `put()`, `delete()` and
  `setName()` throw `Forbidden`.
- **Every collection's write methods throw `Forbidden`** (`createFile`, `createDirectory`, `delete`, `setName`), and
  no node implements `IMoveTarget`. If the router ever failed to answer a write, Sabre would reach a node and be
  refused.
- **Registration.** `info.xml` `<sabre><collections><collection>OCA\FacewrightBridge\Dav\Tree\RootCollection`, as
  Recognize does. This needs a version bump.
- **The router (`RecognizeWriteRouterPlugin`) learns a second root.**
  - Roots `recognize` and `facewright_bridge`. `applies()` matches either prefix.
  - `parse()` accepts either as `$source[0]`.
  - **The `Destination` must stay in the same tree**: `$destination[0] === $source[0]`. Today nothing compares the
    first segment, because there was only one tree.
  - Everything else stays: the five operations, the status codes, user scoping, `DeadlockRetry`.
  - The class keeps its name until Stage H drops the `recognize` root.

### What does not change

- Our tables, `FaceStore`, `PeopleService`, clustering and the People page.
- The OCS API, the `recognize/` tree and its router behaviour.
- Recognize's copy, as long as `recognize` is a target.
- **Memories' covers (`memories_covers`) stay valid**: they are keyed by cluster id and detection id, and both are
  our ids in either target.

## Steps

### M0 Baseline on dev

- `dev/run-all.sh --ui` passes on the current code. If it does not, stop and report: every later step compares against
  this.
- Note the versions: `occ app:list | grep -E 'memories|recognize|facewright'`, and `occ app:update --showonly`.
- `dev/snapshot.sh save original-m0`. This is the snapshot to go back to.

**Verify:** the run's last line reads `N of N suites passed`, and the versions are recorded in "Where things stand".

**Done (measured):** `dev/run-all.sh --ui` printed `29 of 29 suites passed`. Versions on dev: bridge 0.11.0, memories
11.0.2, recognize 12.0.3. `occ app:update --showonly` offers `memories` 11.1.4 only. The snapshot `original-m0` is
byte-identical to `original` (apart from the dump's own date line).

### M1 The fork repository — done 2026-11-20

Done: steps 1, 3, 4 (on the identical prototype), 5, 6 and the planted fault. The branch `facewright` (`bbb4f38c`)
is pushed. A cold session does only step 2, to get a working clone, and keeps the rest as the reference for M12's
rebases.

1. The user created the fork in GitHub's web UI: https://github.com/Haini/memories. `gh` is not installed here. Do
   not install tools to work around anything that needs the user on GitHub.
2. Clone it **outside this repository**, e.g. next to it, at `$FORK`: `git clone -b facewright
   git@github.com:Haini/memories.git "$FORK"`. Add the upstream as a remote, `git remote add upstream
   https://github.com/pulsejet/memories.git`, then run `git fetch upstream --tags`. The fork carries no tags, and
   M2 and M12 need them.
   - Check: `git log --oneline -2` shows `bbb4f38c` on top of "chore(release): bump to v11.1.4".
3. `git switch -c facewright v11.1.4`, `git cherry-pick bbb4f38c` (from the fork's remote).
4. Checks: `npx vue-tsc --noEmit -p .`, `npx prettier --check` on the five changed `src/` files, and `php -l` on the
   four changed PHP files.
   - There is no Node on the host: run them in `docker run --rm -u $(id -u):$(id -g) -e HOME=/tmp -v "$FORK":/src -w
     /src "$NODE_IMAGE" ...` (`NODE_IMAGE` as set at the top of the build script) after `npm ci --ignore-scripts`.
   - Run `php -l` through the dev Nextcloud container: `docker exec -i recognaize-nextcloud-1 php -l < file`.
5. **The two greps** that prove nothing escaped the setting:
   - `grep -rn "recognize_face_" lib | grep -v lib/Migration` prints **nothing**.
     - The two helpers in `RecognizeBackend.php` build the names as `recognizeProvider().'_face_detections'`.
     - `grep -rn "_face_detections\|_face_clusters" lib | grep -v lib/Migration` shows the helpers and a docblock in
       `SystemConfig.php`.
   - `grep -rn "/recognize/" src` names exactly two lines, and neither is the DAV tree:
     - `src/services/router.ts`'s `/recognize/:user/:name` is Memories' own page URL.
     - `src/services/API.ts`'s `` `${BASE}/recognize/api-key` `` is Memories' own API route (`appinfo/routes.php`).
6. Commit as **one** commit, "Recognize provider setting: read Recognize-format tables and DAV tree of another app".
   Push the branch with the user's go-ahead.

**Verify:**
- Both greps print what step 5 says, and `vue-tsc`, `prettier` and `php -l` are clean.
- `git diff --stat v11.1.4 facewright` shows 9 files, +70/-20.
- **Planted fault:** add a `'recognize_face_clusters'` literal back into `RecognizeBackend.php`. The first grep must
  show it. Remove it again.

**Checks on the patch's content (measured):** `vue-tsc` and `prettier --check` on the identical prototype; `php -l` and the M1
greps on the commit. The code has never run in Nextcloud.

### M2 The build script, in the fork — done 2026-11-20

`scripts/facewright-build.sh` in the fork; commit it as a second commit, which is also rebased in M12. It takes no
arguments and builds the tag the branch sits on (`git describe --tags --abbrev=0`). It also keeps `build/release/`
(the release, untouched, for the comparison below), refuses to start when `lib/ appinfo/ templates/ l10n/ img/ src/`
or the package files differ from `HEAD`, and deletes the previous package first, so a run that stops part-way cannot
leave an old one next to its error:

1. **The tarball.** Download `https://github.com/pulsejet/memories/releases/download/$TAG/memories.tar.gz` into
   `build/` (gitignored), unless it is already there. Print its sha256.
2. **Unpack** it into `build/memories/`.
3. **Build `js/`** in the script's `NODE_IMAGE` (`node:24` pinned by digest), run as the user: `npm ci`, `bash scripts/patch-external.sh`, `npm run build`. This is
   what `make js-init build-js-production` does; the Makefile is not used, so the host needs no `make` for Node.
4. **Replace from the checkout:** `lib/ appinfo/ templates/ l10n/ img/ js/`, each with `rm -rf` first, then copy.
5. **Assert there is no `appinfo/signature.json`**, and abort if there is. The `rm -rf` of step 4 should already have
   removed it. The store's signature file must not survive next to modified files, or the integrity check fails for
   every changed file.
6. **Write `build/memories-$TAG-facewright.tar.gz`** (top directory `memories/`) and print its sha256.

**Verify:**
- `diff -rq` between the unpacked release and `build/memories/` lists only `lib/` files from the patch, `js/` files
  (rebuilt; expected), and `appinfo/signature.json` (only in the release).
- `appinfo/info.xml`'s `<version>` equals the tag.
- `bin-ext/` is identical.
- **Planted fault:** in step 4, copy `appinfo/` over the release without the `rm -rf`. Step 5 must abort. Restore it.

**Done (measured):** the build takes about 40 s and produces `build/memories-v11.1.4-facewright.tar.gz` (58 MB). Against
the unpacked release only the four patched `lib/` files and `js/` (rebuilt) differ, `signature.json` is missing,
`bin-ext/` is identical, the version is 11.1.4. The rebuilt `js/` contains `recognize_provider` in 3 files and the
release's in none. The build is **not reproducible byte for byte** (two runs gave different sha256), so record the
hash of the one you deploy.

### M3 Dev: prove the pin, then the store update to v11.1.4 — done 2026-11-20

1. `occ app:update --showonly` lists `memories` (11.1.4 on 2026-11-19).
2. `docker exec -u www-data recognaize-nextcloud-1 mkdir /var/www/html/custom_apps/memories/.git`. `--showonly` no
   longer lists `memories`.
3. `rmdir` it. `--showonly` lists `memories` again.
4. **Update only Memories**: `occ app:update memories`, never `--all`.
   - If `--showonly` lists Recognize as well, leave it alone. A Recognize update re-arms its `MaintenanceJob`, and
     that needs CLAUDE.md's handling.
   - The update runs Memories' migration `Version900008Date20261104000000`, which drops two indexes of
     `memories_places`. That table is not in the dev snapshots.
5. `dev/run-all.sh --ui` on stock 11.1.4.
   - If a Memories UI flow fails because 11.1.4 changed its UI, fix `dev/memories-ui/flows.py` first. That is stock
     behaviour, not ours; commit it on its own.
6. **The oracle for every later step:** `dev/memories-api.py stable` must print `STABLE`, then `dev/memories-api.py
   capture dev/.work/fork/stock.json`. It captures, as Memories' API returns them, the people, each person's photos
   (`days`) and the unassigned faces (key `NULL`), for admin. Bob is not captured: he has no Memories timeline folder, so Memories refuses him. Ids are ours in every target, so the captures compare directly. **Take
   every capture in this plan right after `dev/snapshot.sh restore original`**, so that they all start from the same
   data. With `own` configured (M6 on), restore also refills the `own` tables.

**Verify:** steps 1 to 3 printed as described (paste the three outputs into "Where things stand"), `occ app:list` shows
`memories: 11.1.4`, and the run passes.

**Done (measured):** the `.git/` pin works. `--showonly` printed `memories new version available: 11.1.4`, then with
`custom_apps/memories/.git` present `All apps are up-to-date or no updates could be found`, then after `rmdir` the
`memories` line again. `occ app:update memories` printed `memories updated`; dev now runs **Memories 11.1.4** (bridge
0.11.0, recognize 12.0.3) and migration `900008Date20261104000000` is recorded. `dev/run-all.sh --ui` on it:
`29 of 29 suites passed`, after one fix to `dev/memories-ui/flows.py` (11.1.4 draws the person page's tiles later;
the flow now waits for the first tile instead of a fixed 4.5 s). The oracle `dev/.work/fork/stock.json`:
`STABLE: two captures identical`, then `captured admin: 297 people, 2713 photo entries`.

### M4 Dev: the fork with the default provider changes nothing — done 2026-11-20

1. **Keep the store build.** `dev/fork-swap.sh save-store dev/.work/fork/memories-store-11.1.4.tar` (a tar, so the
   ownership survives; `docker cp` of a folder would give the files the host user's id). It is kept on the host, not
   inside the container's apps directory: a second folder with the id `memories` under an apps path conflicts.
   `dev/fork-swap.sh` also does the swaps below (`fork TARBALL`, `store TAR`, `status`) and refuses a tarball that
   carries `signature.json`.
2. **Swap in the fork.** Remove the folder in the container. Unpack M2's tarball into
   `/var/www/html/custom_apps/` as `www-data`, then `mkdir .../memories/.git`.
   - This needs no `occ upgrade`: the version is unchanged.
   - Opcache revalidates within 60 s. `dev/lib.sh`'s `reload_web` makes it immediate.
3. **Checks:**
   - `occ app:list` shows `memories: 11.1.4`, enabled.
   - `occ setupchecks` reports no integrity problem for `memories`.
   - `occ app:update --showonly` does not list `memories`.
   - The frontend config (`/apps/memories/api/config`, as the admin) contains `"recognize_provider":"recognize"`.
   - The API-key endpoint (`/apps/memories/api/recognize/api-key`) still returns a non-empty key.
4. `dev/memories-api.py capture dev/.work/fork/m4.json`, then `compare stock.json m4.json`.
5. `dev/run-all.sh --ui`.
6. **Rollback rehearsal.** Swap the store build back (without `.git/`), capture, compare with `stock.json`. Then swap
   the fork in again.

**Verify:**
- Both compares are identical, and the run passes.
- **Planted fault:** in the deployed fork, make `recognizeProvider()` return `'nonexistent'`. The capture must differ:
  the People list is empty or errors. Restore it.

**Done (measured), dev now runs the fork:** the build deployed is `build/memories-v11.1.4-facewright.tar.gz`,
sha256 `d540449f018bcc46215f8bdc801a9718e206e0ff5a7fac694a77dea3bd4d225c`, pinned with `.git/`, no `signature.json`.
- `occ app:list`: `memories: 11.1.4`. `--showonly`: `All apps are up-to-date or no updates could be found`.
- `occ setupchecks`: `Code integrity: No altered files` (the store build said the same). `occ integrity:check-app
  memories`: `App signature not found, skipping app integrity check`.
- `/apps/memories/api/config` has `recognize_provider: recognize`; the API-key endpoint returns a key (292 characters).
- `compare stock.json m4.json`: `identical`. `dev/run-all.sh --ui`: `29 of 29 suites passed`.
- **Planted fault:** `recognizeProvider()` returning `'nonexistent'` made the capture stop with `people list is not a
  list: {'message': 'Recognize app not enabled or not the required version.'}` and no file. The restored fork
  captured `identical` again.
- **Rollback rehearsal:** store build back (not pinned, with `signature.json`, no `recognizeProvider` in it): capture
  `identical`; fork forward again: `identical`. The swap to the fork took 2.2 s including the web server restart (the swap back was not timed).

### M5 Bridge: the `own` tables — done 2026-11-20

- Migration `Version000009000Date2026MMDD000000` creating the two tables exactly as in "The design". No data. Bump the
  bridge's version to **0.12.0**.
- Dev: `dev/snapshot.sh restore original`, `occ upgrade`, `dev/snapshot.sh save original`. Keep the old file as
  `original-pre-m5`.
  - **The bridge is bind-mounted from this repository, so editing `info.xml`'s version takes effect at once**, and
    until `occ upgrade` has run, `occ facewright:*` does not exist: a restore then fails with `VERIFY FAILED`. Do the
    first restore **before** bumping the version, or restore again after the upgrade (done here: `restored original;
    verify clean`).
  - **Commit the step's code before `dev/run-all.sh`.** `rollback-drill` swaps the tracked files of `facewright_bridge`
    and refuses to start unless that directory is clean (`facewright_bridge has uncommitted changes`).

**Verify:**
- `SHOW CREATE TABLE` on both matches the design: columns, NOT NULL, the four indexes, no autoincrement.
- `occ facewright:own-tables --verify` is clean, and the run passes. Nothing writes the tables yet.

**Done (measured):** migration `Version000009000Date20261120000000`, bridge **0.12.0**, `occ upgrade` printed `Updated
<facewright_bridge> to 0.12.0`. `SHOW CREATE TABLE` on `oc_facewright_bridge_face_detections` and `_clusters` shows
exactly the design: `id` bigint NOT NULL without AUTO_INCREMENT, `x y width height` double NOT NULL, `cluster_id`
bigint NOT NULL, the keys `fwb_fdet_cluster` / `_user` / `_file` and `fwb_fclust_user`, `utf8mb4_bin` like
Recognize's tables, 0 rows in both. After the re-save the snapshot `original` equals `original-pre-m5` and
`original-m0` (the own tables are not in it). `dev/run-all.sh --ui`: `29 of 29 suites passed`.

### M6 Bridge: projection targets — done 2026-11-20

- **`ProjectionService` with targets**, as in "The design". Keep the SQL of the `recognize` target byte-for-byte where
  it does not need the table name as a parameter. `dev/project-concurrency.sh` fails on a degraded upsert, so the
  `INSERT ... SELECT ... ON DUPLICATE KEY UPDATE` shape and its `ORDER BY` stay.
- **`occ facewright:project --target=<name>`.**
  - Without the option it covers every configured target, one report per target, and the exit code is non-zero if any
    target has drift.
  - `--hydrate` refuses any target other than `recognize`.
  - `markClean()` runs only after a run with no `--user` and no `--target` left nothing.
- **`ProjectionMaintenanceJob`** reconciles every configured target. Its log line names the target.
- **The admin page's "Memories sync" line** stays one line over the shared flag.
- **`dev/lib.sh`.**
  - `verify_step` also runs the **independent oracle** (`own_oracle`, `own_oracle_counts`) when `own` is configured. Two
    `EXCEPT` queries must both be empty: `(SELECT id, user_id, file_id, x, y, width, height, cluster_id FROM
    oc_recognize_face_detections WHERE id IN (SELECT id FROM oc_facewright_user_faces WHERE state IN (1,2))) EXCEPT
    (SELECT ... FROM oc_facewright_bridge_face_detections)`, and the same the other way round, with the second side
    **not** restricted (a row in `own` that is not a visible face of ours is an error). Do the same for people, with
    `title`, the Recognize side restricted to ids in `facewright_clusters`.
    - **Why `state IN (1,2)` and not every row of ours:** Recognize's table still holds the hidden-state remnants of
      category B (6 on dev's snapshot), which `own` never has, so the plan's first wording failed at the baseline.
    - **`own_oracle` is not used by `persist-guard`:** that suite plants "a human changed it" with direct SQL, which
      leaves both copies stale by design (Recognize tolerates it as B, `own` repairs it as C). It checks the same two
      things for `own` that it checks for Recognize instead.
  - MariaDB 10.11 has `EXCEPT`. This checks the targets against each other without going through reconcile.
- **`dev/snapshot.sh restore`** truncates the two `own` tables if they exist. If `own` is configured, it then runs
  `occ facewright:project --target=own` and requires it to exit 0.
- **`dev/run-all.sh`** gets a `with_own` wrapper like `with_exapp`. It sets `projection_targets=recognize,own`, runs
  the suite, and deletes the key afterwards. Run under it: `writers-people`, `writers-cluster`, `writers-files`,
  `persist-guard`, `project-concurrency`, `dav-battery`.
- **A new suite, `dev/project-own.sh`:**
  1. `own` configured and the tables empty: the `--target=own` dry run reports D-missing equal to the number of our
     people and A equal to our visible faces. It writes nothing (count before and after).
  2. A repair, then a clean dry run.
  3. Plant one fault per category: delete a row (A), change a `cluster_id` (A), insert a row with an unknown id (C),
     insert a row with the id of an ignored face (C), change a title (D-missing), insert a person of nobody's (D-stale).
     The dry run must report exactly those counts. Repair, then clean.
  4. **Recognize's tables untouched**: a checksum of both before and after (`CHECKSUM TABLE`).
  5. `--hydrate --target=own` is refused.

**Verify:**
- `dev/run-all.sh --ui` passes, including the `with_own` runs and `project-own`.
- With `projection_targets` unset, `project-drift`, `project-failure`, `project-recognize-trap` and
  `project-recognize-copies` pass unchanged.
- **Planted faults:**
  - Make push skip `own` for people only: a `with_own` writer suite must fail in `verify_step`'s oracle.
  - Break one `own` statement (a wrong column name): the push must still write `recognize`, set the dirty flag and log
    one error naming the target, and a People API write must still succeed.

**Done (measured):** `ProjectionService` writes every configured target (`projection_targets`, unset = `recognize`);
`occ facewright:project` has `--target` and prints a `Target: <name>` line first; the hourly job and `--verify` cover
every configured target. Existing suites with the default config, **unchanged**: `project-drift` 24 passed,
`project-failure` 15, `project-recognize-trap` 21, `project-recognize-copies` 26. New `dev/project-own.sh`: 49 passed
(an empty copy reports 2790 visible faces and 311 people and writes nothing; the fill; planted drift reported as
exactly A 6, C 2, D 1 and 1; the repair; Recognize's `CHECKSUM TABLE` equal before and after; `--hydrate --target=own`
and an unknown target exit 2; a broken `own` column leaves Recognize's copy written, the flag set with `own: ...
cluster_idx`, one error logged, and the job repairs it). With `own` configured: `writers-people` 55, `writers-cluster`
23, `persist-guard` 13, `project-concurrency` 4, `battery` 45 passed. `dev/run-all.sh --ui`: `36 of 36 suites passed`
(the 29 before and `project-own` plus six `-own` runs).
- **Planted faults, both caught:** push skipping `own` entirely for people: the fill itself failed (`could not fill
  own`, reconcile cannot repair people). Push skipping `own` people only for a push with no faces (renames, creates):
  `writers-people` under `with_own` printed `FAIL verify after create` and `FAIL own oracle after create ... 0/0/1/0`,
  the same after rename and after an empty person exists (49 passed, 6 failed). Code restored byte for byte.

### M7 Bridge: the WebDAV tree — done 2026-11-20

- `lib/Dav/Tree/`: `RootCollection`, `UserHome`, `FacesHome`, `PersonCollection`, `UnassignedFacesHome`, `FaceFile`,
  as in "The design". They read through `PeopleService` and `IRootFolder`.
  - `PeopleService` gets a read for one face by id (user, state, cluster, file id) if no existing method fits;
    `faceAssignment()` returns only the assignment.
  - Reads take `user_id` from the session, never from the path.
- `info.xml`: the `<sabre>` block, **and `<types><dav/></types>`**: Nextcloud's `PluginManager` reads collections only
  from apps that declare the `dav` type. Bump to **0.12.1**, `occ upgrade`, restart the web server (`reload_web`).
  - The collection's constructor takes `$principalBackend` by parameter name; register it in `Application::register()`
    as `$context->registerServiceAlias('principalBackend', \OCA\DAV\Connector\Sabre\Principal::class)`, as Recognize
    does. Without it every DAV request of the instance, Files included, answers 500.
- `RecognizeWriteRouterPlugin`: the second root and the same-tree rule for `Destination`. Update its docblock.
- **`dev/battery.sh`** takes `TREE` (default `recognize`) for every DAV path. The tables it reads stay our own or
  Recognize's as now, since it checks the writes, which are the same in both trees. New checks:
  - a `MOVE` whose `Destination` is in the other tree is 403 and changes nothing;
  - **PROPFIND depth 1 on `faces/{A}`**: the names are exactly `{id}-{name}` for the faces of A whose files the user
    can see. Compare the id set with SQL: `facewright_user_faces` with `state=1`, `cluster_id=A`, `user_id=u`, joined to
    the user's visible files;
  - PROPFIND on `unassigned-faces` with depth 1 is 405;
  - PROPFIND on the other user's tree is 403, and unauthenticated is 401;
  - GET on one face file returns the file's bytes (compare sha256 with the file through the files tree).
- `run-all.sh`: `dav-battery-own` = `with_own env TREE=facewright_bridge battery.sh`, next to the existing
  `dav-battery`.

**Verify:**
- Both batteries pass with the same status codes for the same operations.
- **Planted faults:**
  - Drop the same-tree check: the cross-tree `MOVE` check fails.
  - Make `UserHome` skip the user check: the other-user PROPFIND check fails.
  - Name children by file id instead of face id: the merge-listing check fails.

**Done (measured):** `lib/Dav/Tree/` (`RootCollection`, `UserHome`, `FacesHome`, `PersonCollection`,
`UnassignedFacesHome`, `FaceFile`, and a `ReadOnlyCollection` base that refuses every write), `PeopleService::getFace()`,
`info.xml` with `<types><dav/></types>` and the `<sabre>` collection (version **0.12.1**), a
`registerServiceAlias('principalBackend', Principal::class)` in `Application` (without it every DAV request answered 500
`Could not resolve Sabre\DAVACL\PrincipalBackend\BackendInterface`), and the router's second root with the same-tree
rule. `dev/battery.sh` takes `TREE`: **Recognize's own tree 58 passed, the bridge's tree 59 passed** (the one extra is the
405 for the unassigned listing, which Recognize does not give), the same status codes for the same requests. The
listing check compares the 27 face ids of one person with SQL; Recognize's tree gives the same set, which is what
shows the expectation is right and not shaped to our code. The bytes of a face file equal the Files tree's (sha256).
- **Planted faults, each caught:** no same-tree rule: 11 checks failed, among them `MOVE a person into the other tree
  expected [403] got [201]`, and the person had really been renamed in the other tree. A root without the user check:
  `PROPFIND on bob's tree as admin expected [403] got [207]`. Children named by file id: `PROPFIND depth 1 on a
  person lists exactly its faces` got `244,244,...`.
- `dev/run-all.sh --ui`: 37 of 38 suites passed; the one failure was `rollback-drill`, whose Stage D router predates the
  cross-tree rule that the battery now checks. The battery takes `CROSS_TREE=no` for it; the drill alone then passed
  24 of 24. A full run is repeated at the end of M8.

### M8 Dev: point the fork at the bridge — done 2026-11-20

1. `projection_targets=recognize,own` and `occ facewright:project --target=own` clean. Then `occ config:system:set
   memories.recognize.provider --value=facewright_bridge` (the admin page's "Recommended Apps" does the same).
2. Capture, and compare with `stock.json`: identical. **Restore the snapshot first**: UI flows delete a person and make
   Memories write cover rows, so a capture taken after them differs for reasons that have nothing to do with the provider.
3. **`dev/memories-ui/flows.py` takes `PROVIDER`** (default `recognize`). Its DAV filter (`"/dav/recognize"`) and its
   SQL use `oc_{PROVIDER}_face_detections`/`_clusters`, except that `facewright_bridge` maps to the `own` tables. Each
   flow also asserts that **no** request went to `/dav/recognize` when the provider is ours. Run it with
   `PROVIDER=facewright_bridge`: rename, remove face, move face, merge, remove person. Every flow passes, and the
   database checks hold in our tables and in both targets (`verify_step`).
4. **`occ app:disable recognize`.**
   - Capture, compare: identical.
   - Run the flows again.
   - The API-key endpoint returns `{"apiKey":""}`.
   - `nextcloud.log` has no new error from `memories` or `facewright_bridge` since the disable.
   - Memories' admin page and People page load in the browser (`flows.py` covers the People page).
5. `occ app:enable recognize`, then **at once** delete Recognize's `MaintenanceJob` and any `ClusterFacesJob` (CLAUDE.md,
   "Coexisting with Recognize"; pipe `y`). Check that `faces.enabled` is still `'true'`.

**Verify:**
- Both compares are identical, both flow runs pass, and the job table holds no Recognize clustering job afterwards.
- **Planted fault:** delete one row from the `own` detections table while the provider is ours. The capture must
  differ: Memories reads ours. Then repair with `project --target=own`.

**Done (measured), provider `facewright_bridge`, `projection_targets=recognize,own`:** 
- Capture right after `snapshot.sh restore original`: `identical` to `stock.json` (297 people, 2713 photo entries), with
  Recognize enabled, **disabled**, and enabled again. The frontend config carries `recognize_provider:
  facewright_bridge`; the API-key endpoint returns `{"apiKey":""}`; Memories' settings page and app page answer 200.
- `dev/memories-ui/flows.py` with `PROVIDER=facewright_bridge` (database checks on the `own` tables, and every flow asserts
  that no request went to `/dav/recognize`): **21 passed**, with Recognize enabled and again with it disabled (merge:
  one PROPFIND of our tree and 11 MOVEs). The log held no warning or error from `memories` or `facewright_bridge`
  during the disabled run.
- **Planted faults:** one row deleted from the `own` detections made the capture differ (`person 100001 ... count 4 ->
  3`) and `project --target=own` repaired it, `identical` again. The flows run with `PROVIDER=recognize` while the
  fork was set to the bridge: 11 of 21 failed, with `no request went to the facewright_bridge tree` listing the stray
  MOVE, DELETE and PROPFIND requests.
- `app:enable recognize` re-added its `MaintenanceJob`; deleting it in the same chain left 0 Recognize jobs and
  `faces.enabled` `true`.
- `dev/run-all.sh --ui` now also runs `memories-ui-bridge` and `memories-ui-bridge-off` (`with_provider`,
  `with_recognize_off`); the full run is at the end of M9.

### M9 Dev: rollback drill — done 2026-11-20

1. Provider back to `recognize` (`occ config:system:delete memories.recognize.provider`; the admin page's "Recommended Apps" does the same). Capture and compare: identical.
   Flows with `PROVIDER=recognize` pass.
2. The store build back (M4's copy, without `.git/`). Capture and compare: identical.
3. Forward again: the fork, `.git/`, provider ours. Capture and compare: identical.
4. `dev/run-all.sh --ui`, with `PROVIDER=facewright_bridge` for the UI flows.

**Verify:** all four compares are identical and the run passes. Record the time each swap took.

**Done (measured), the rollback drill, each step followed by `snapshot.sh restore original`, a capture and a compare
with `stock.json`:** fork with provider ours `identical`; provider setting removed (0.3 s) `identical`, flows with
`PROVIDER=recognize` 21 passed; **store build back** (swap 0.8 s, not pinned, `signature.json` present, no
`recognizeProvider` in it) `identical`; **fork forward** (swap 2.2 s, setting back 0.3 s) `identical`, flows with
`PROVIDER=facewright_bridge` 21 passed. `dev/run-all.sh --ui`: **`40 of 40 suites passed`** (the 38 before, plus
`memories-ui-bridge` and `memories-ui-bridge-off`).
- **Order matters on the way back:** the store build ignores the provider setting and reads Recognize's tables. So
  the setting is removed first (while the fork is still there), then the folder swapped; with the setting left in
  place the store build would show Recognize's copy, which is only right while Recognize is enabled and a target.

### M10 Docs — done 2026-11-20

- **`CLAUDE.md`:**
  - Architecture: the fork, the provider setting, Recognize optional once the provider is ours.
  - Components: `lib/Dav/Tree/`, projection targets, `projection_targets`.
  - The invariant: **every target is written by `ProjectionService` only, and the `own` tables by nothing else at all.**
  - Deployment: build with `scripts/facewright-build.sh` in the fork, swap the folder, `.git/` pin, a rollback must be
    the same version.
  - The `occ` list: `project --target`.
- **`docs/own-tables.md`:** Stage G's status, and Stage H's first step (the `recognize` target and root, `--hydrate`).
- **`README.md`:** installing needs the fork of Memories; a short section linking the fork.
- **`dev/README.md`:** `project-own.sh`, `TREE`, `PROVIDER`, `with_own`.

**Verify:** `grep -rn "pulsejet/memories#1812\|undecided" CLAUDE.md docs/own-tables.md` finds only history, not plans.

### M11 Live (each action with the user's go-ahead and a fresh dump)

The exact commands for the live instance belong in the operator's private notes. Write them there, not here.
Ask the user before each numbered item.

1. **Dump** (the private notes say how). `occ app:update --showonly`: if Recognize is listed too, leave it alone.
2. **The store update** of Memories to the fork's tag, nothing else. Check People in Memories.
3. **Swap in the fork** (M4's sequence, the store build kept on the host for rollback). Then `occ app:update --showonly`,
   `occ setupchecks`, and the People page. Leave it for a day and read the log.
4. **The bridge** at the M7 version: `occ upgrade`, web container restart.
   - Then `projection_targets=recognize,own`, `occ facewright:project --target=own` (it fills the copy; time it), and
     `--dry-run` clean.
   - `EXCEPT` cross-check with M6's queries: both empty.
5. **Set the provider.** Check the People page, a rename, a move, a merge, a remove. Read the log.
6. **Later, separate go-aheads:**
   - drop `recognize` from `projection_targets`;
   - disable Recognize;
   - Stage H.

#### M11 as run (live, 2026-11-20)

- **1 Dump:** the database, the installed Memories folder and the bridge folder, plus row counts (200,991 detections and
  4,683 clusters in Recognize's tables). `occ app:update --showonly` did not list Recognize.
- **2 Store update:** Memories 11.1.1 to 11.1.4 (live had not been on 11.0.2 as the plan assumed). People page fine.
- **3 Swap:** the store build was saved first, then the fork tarball unpacked as `www-data`, an empty `.git/` added, Apache
  restarted. The offer for Memories disappeared from `app:update --showonly`; the setting was still unset, so the
  fork behaved as stock. People page fine.
- **4 Bridge 0.12.1:** `occ upgrade` took 25 s. **It also applies every pending App Store update** (calendar, contacts, deck,
  mail, notes, richdocuments, spreed were updated; the pinned Memories and Recognize, with no offer, were not). The plan
  did not foresee that; the alternative, `appstoreenabled=false` for the duration, was not used (the user accepted the
  updates). Take another dump right before the upgrade, and expect it. Then `projection_targets=recognize,own`:
  `project --target=own --dry-run` found A 156,231 and D 4,681 (every visible face and person), the fill took **3.8 s**,
  and a second dry run was 0 for both targets. The `EXCEPT` cross-check (faces and people, both directions) was 0/0/0/0
  on 156,231 faces and 4,681 people.
- **5 Provider set:** `memories.recognize.provider=facewright_bridge`. Rename, move, remove face and merge by the user in
  Memories looked right; afterwards both targets reconciled at 0 and the `EXCEPT` check was 0/0/0/0, with no error in the
  log.

Rollback at any point: the provider back to `recognize` (Recognize must still be enabled and a target, and
`occ facewright:project --target=recognize` repairs its copy), then the store build of the same version.

### Decision after M11 (2026-11-21: Option 2)

**Decided 2026-11-21: Option 2.** The user does not need a data rollback: the way back is the code at the tag
`pre-stage-f` (this repo) and `facewright-v11.1.4` (the fork) on a database rebuilt from scratch, with detection run
again; losing names and other curation on that path is accepted. Runbook and work order: `docs/cleanup.md`, section 0.
The text below is the decision as it was laid out.

**State on live now:** Memories is the pinned fork (11.1.4), `memories.recognize.provider=facewright_bridge`,
`projection_targets=own`, Recognize 12.0.3 **disabled** (installed, no `oc_jobs` rows of it), bridge 0.12.1. Recognize's
tables are **frozen** at the moment `recognize` was dropped (21 Nov, about 15:40 local, a fresh dump of that moment is
`~/backups/facewright-pre-recognize-off/`); nothing writes them. Photos' People view is gone (it needs Recognize). Both
copies agreed at 0 until then; the `own` copy has been checked after new detections and edits.

The open question is how much of the Recognize-side machinery to keep as **a way out** if the fork cannot be kept up
(Memories moving on faster than we rebase, a security fix we cannot port, etc.). Two options:

**Option 1: park Stages F and H, keep the way out cheap.** Delete nothing. Keep the `recognize` target, `--hydrate`,
reconcile's B/squatter/legacy categories, the router's `recognize` root, Recognize's tables and its app files (disabled,
not `app:remove`d), and the saved store build. Then:
- *Warm standby (optional but recommended with this option):* put `recognize` back into `projection_targets`
  (`recognize,own`) and run `occ facewright:project --target=recognize` once to catch up the stretch it was off. The
  copy stays current for a few seconds of writes (the `own` fill of 156,231 faces took 3.8 s). A rollback is then: remove
  the provider setting, swap the store build of the same version back, `occ app:enable recognize`, delete its
  `MaintenanceJob` (and any `ClusterFacesJob`) in the same chain. No repair step.
- *Without warm standby (current state):* the same, plus `occ facewright:project --target=recognize` after setting
  `projection_targets=recognize,own`, to repair a stale copy. Its run time at production size is **not measured**
  (a dry run on live is read-only and would give the size of the drift).
- **Untested:** M9's rollback drill had Recognize enabled and a target. Rolling back from the present state (Recognize
  disabled, copy stale) has not been rehearsed. If this option is taken: write a dev drill for it (disable Recognize,
  drop the target, make changes through the fork, roll back, `memories-api.py capture|compare`), add it to
  `dev/run-all.sh`, and put the runbook here.
- Optional and rollback-safe later: F.1 (blank the vectors in Recognize's table, `ALTER TABLE ... FORCE`; about 1.1 GB
  and the buffer pool); `--hydrate` and `project` restore what it takes out. Not needed now.
- Costs: the Recognize-side code and the `recognize_*` rows stay (about 1.1 GB on disk); `facewright_detection_faces`,
  `ownRowsExpr` and the other Stage D leftovers stay; Photos' People view stays gone.

**Option 2: Stages H and F as written (a one-way door).** Sequence, each live step with its own go-ahead and a fresh
dump, dev first:
1. Code (bridge 0.13.0): remove the `recognize` target, `project --hydrate`, reconcile's B/squatter/legacy categories
   (H.1); the router's `recognize` root; F.2's dead code (`ownRowsExpr`, `encodeVector`, `VectorCompactionService`,
   `MigrateFaces`, `ReopenUnassigned`, `--precheck/--migrate/--seed-ids`; sentinels into `FaceState`); the legacy-member
   reads of Recognize's tables (F.3, after the user has seen the titled and untitled split of the 40 legacy rows).
2. Deploy 0.13.0 on live (`occ upgrade` also applies pending store updates).
3. Soak about a week on `own` alone.
4. 0.14.0 migration dropping `facewright_detection_faces` (after `grep -rn detection_faces lib/` is empty).
5. Dump, then drop `rfd`/`rfc` or leave them frozen; remove the Recognize app or leave it disabled; docs (the
   coexistence section of `CLAUDE.md`, `.noimage`).
- After step 1 there is **no way back to stock Memories** and none to the Stage D code; the way out is the dump.
- Gains: about 1.1 GB and buffer pool, much less code, no Recognize.

**Recommendation (Claude, 2026-11-21):** Option 1 with warm standby, then revisit Option 2 after the fork has survived a
rebase onto a real Memories release (M12) and a few weeks of use. The recommendation is the cheaper one to take back; it
is not yet decided. Open points for the user: whether to re-add the `recognize` target now; whether to have the dev drill
written; whether F.1 is ever wanted; whether Recognize is ever uninstalled.

### M12 Keeping the fork current

On each Memories release:
1. `git fetch upstream --tags`, `git rebase --onto vX.Y.Z <old tag> facewright`. The branch carries three commits (the
   change, the build script, the admin UI); all three move, and a conflict in `SystemConfig.php`, `AdminController.php` or
   `Apps.vue` is the admin UI's, which can be dropped on its own if it stops applying.
2. M1's greps and checks.
3. Read the release's changes to `lib/ClustersBackend/`, `lib/Settings/SystemConfig.php`,
   `lib/Controller/RecognizeController.php`, `src/services/dav/` and `src/components/modal/Face*`. A new place that
   names a Recognize table or path must take the setting.
4. Refresh the Node image's digest in `scripts/facewright-build.sh` (`NODE_IMAGE`; it is pinned, so it does not move on
   its own): `docker pull node:24`, then `docker inspect --format '{{index .RepoDigests 0}}' node:24`, and write
   `node:24@sha256:<digest>` into the variable (fixup into the build script's commit). Check that `package.json`
   still asks for Node 24.
5. M2's build.
6. On dev: the store update to that release, then M4 (swap in the fork, capture, compare) and M8 step 3 (the UI flows).
7. Live with the user's go-ahead.

Security fixes reach us only this way. Watch the upstream repository's releases.

## Deviations from `docs/own-tables.md` Stage G as first written

- The tree serves reads only; writes stay in the router plugin, which gains a second root (G.3 had "the router's
  parsing moves into the collection's nodes"). Less code moves, and the battery already covers the router.
- The build overlays the official release tarball instead of rebuilding `bin-ext/` (simpler, no binary of ours).
- The Android-app question is closed: the user does not use it.

# Memories fork: Recognize stand-in (Stage G)

The implementation plan for Stage G of `docs/own-tables.md`, written for a session that has not seen this repository
before. Memories' Recognize backend gets one system setting that names the app whose Recognize-format tables and
WebDAV tree it uses. The bridge provides both: a copy of our people data in tables of its own, and a WebDAV tree that
answers Memories' requests. Recognize can then be disabled, and in the end uninstalled (Stage H).

## Where things stand

**M0 to M3 are done (2026-11-20); the bridge is unchanged.** The decisions are in `docs/own-tables.md`, Stage G.0.

- **The fork:** https://github.com/Haini/memories (SSH remote `git@github.com:Haini/memories.git`). The branch
  `facewright` is v11.1.4 plus the commit `bbb4f38c` ("Recognize provider setting: read Recognize-format tables and DAV
  tree of another app", exactly `docs/memories-fork.patch`, 9 files, +70/-20) and the commit `2adf50b6`
  (`scripts/facewright-build.sh`, M2). **`bbb4f38c` is pushed; `2adf50b6` is not, it waits for the user's go-ahead.**
- **The clone is `/home/constantin/memories-fork`** (`$FORK`), with the remote `upstream` and its tags. Its `build/`
  holds the release tarball, the unpacked release and the fork build.
- **M0 (measured):** `dev/run-all.sh --ui` printed `29 of 29 suites passed`. Versions on dev: bridge 0.11.0, memories
  11.0.2, recognize 12.0.3. `occ app:update --showonly` offers `memories` 11.1.4 only. The snapshot `original-m0` is
  byte-identical to `original` (apart from the dump's own date line).
- **M2 (measured):** the build takes about 40 s and produces `build/memories-v11.1.4-facewright.tar.gz` (58 MB). Against
  the unpacked release only the four patched `lib/` files and `js/` (rebuilt) differ, `signature.json` is missing,
  `bin-ext/` is identical, the version is 11.1.4. The rebuilt `js/` contains `recognize_provider` in 3 files and the
  release's in none. The build is **not reproducible byte for byte** (two runs gave different sha256), so record the
  hash of the one you deploy.
- **M3 (measured):** the `.git/` pin works. `--showonly` printed `memories new version available: 11.1.4`, then with
  `custom_apps/memories/.git` present `All apps are up-to-date or no updates could be found`, then after `rmdir` the
  `memories` line again. `occ app:update memories` printed `memories updated`; dev now runs **Memories 11.1.4** (bridge
  0.11.0, recognize 12.0.3) and migration `900008Date20261104000000` is recorded. `dev/run-all.sh --ui` on it:
  `29 of 29 suites passed`, after one fix to `dev/memories-ui/flows.py` (11.1.4 draws the person page's tiles later;
  the flow now waits for the first tile instead of a fixed 4.5 s). The oracle `dev/.work/fork/stock.json`:
  `STABLE: two captures identical`, then `captured admin: 297 people, 2713 photo entries`.
- **Checks on the patch's content:** `vue-tsc` and `prettier --check` on the identical prototype; `php -l` and the M1
  greps on the commit. The code has never run in Nextcloud.

**Next: M4.**

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
  now) and carries the change as **one commit**, rebased onto each new tag (M12). The app id stays `memories` and the
  version stays exactly upstream's (`docs/own-tables.md` G.0).
- The change (`docs/memories-fork.patch`):
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
   - Check: `git diff --abbrev=8 v11.1.4 facewright` is byte-identical to `docs/memories-fork.patch`. Without
     `--abbrev=8` only the `index` lines differ, because Git abbreviates hashes to a length that depends on the
     clone.
3. `git switch -c facewright v11.1.4`, `git apply /path/to/facewright/docs/memories-fork.patch`.
4. Checks: `npx vue-tsc --noEmit -p .`, `npx prettier --check` on the five changed `src/` files, and `php -l` on the
   four changed PHP files.
   - There is no Node on the host: run them in `docker run --rm -u $(id -u):$(id -g) -e HOME=/tmp -v "$FORK":/src -w
     /src node:24 ...` after `npm ci --ignore-scripts`.
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

### M2 The build script, in the fork — done 2026-11-20

`scripts/facewright-build.sh` in the fork; commit it as a second commit, which is also rebased in M12. It takes no
arguments and builds the tag the branch sits on (`git describe --tags --abbrev=0`). It also keeps `build/release/`
(the release, untouched, for the comparison below), refuses to start when `lib/ appinfo/ templates/ l10n/ img/ src/`
or the package files differ from `HEAD`, and deletes the previous package first, so a run that stops part-way cannot
leave an old one next to its error:

1. **The tarball.** Download `https://github.com/pulsejet/memories/releases/download/$TAG/memories.tar.gz` into
   `build/` (gitignored), unless it is already there. Print its sha256.
2. **Unpack** it into `build/memories/`.
3. **Build `js/`** in `node:24`, run as the user: `npm ci`, `bash scripts/patch-external.sh`, `npm run build`. This is
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
   capture dev/.work/fork/stock.json`. It captures, as Memories' API returns them, the people and each person's photos
   (`days`) for admin. Bob is not captured: he has no Memories timeline folder, so Memories refuses him. Ids are ours in every target, so the captures compare directly. **Take
   every capture in this plan right after `dev/snapshot.sh restore original`**, so that they all start from the same
   data. With `own` configured (M6 on), restore also refills the `own` tables.

**Verify:** steps 1 to 3 printed as described (paste the three outputs into "Where things stand"), `occ app:list` shows
`memories: 11.1.4`, and the run passes.

### M4 Dev: the fork with the default provider changes nothing

1. **Keep the store build.** `docker cp recognaize-nextcloud-1:/var/www/html/custom_apps/memories
   dev/.work/fork/memories-store-11.1.4`. It is kept on the host, not inside the container's apps directory: a second
   folder with the id `memories` under an apps path conflicts.
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

### M5 Bridge: the `own` tables

- Migration `Version000009000Date2026MMDD000000` creating the two tables exactly as in "The design". No data. Bump the
  bridge's version to **0.12.0**.
- Dev: `dev/snapshot.sh restore original`, `occ upgrade`, `dev/snapshot.sh save original`. Keep the old file as
  `original-pre-m5`.

**Verify:**
- `SHOW CREATE TABLE` on both matches the design: columns, NOT NULL, the four indexes, no autoincrement.
- `occ facewright:own-tables --verify` is clean, and the run passes. Nothing writes the tables yet.

### M6 Bridge: projection targets

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
  - `verify_step` also runs the **independent oracle** when `own` is configured. Two `EXCEPT` queries must both be
    empty: `(SELECT id, user_id, file_id, x, y, width, height, cluster_id FROM oc_recognize_face_detections WHERE id IN
    (SELECT id FROM oc_facewright_user_faces)) EXCEPT (SELECT ... FROM oc_facewright_bridge_face_detections)`, and the
    same the other way round. Do the same for people, with `title`.
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

### M7 Bridge: the WebDAV tree

- `lib/Dav/Tree/`: `RootCollection`, `UserHome`, `FacesHome`, `PersonCollection`, `UnassignedFacesHome`, `FaceFile`,
  as in "The design". They read through `PeopleService` and `IRootFolder`.
  - `PeopleService` gets a read for one face by id (user, state, cluster, file id) if no existing method fits;
    `faceAssignment()` returns only the assignment.
  - Reads take `user_id` from the session, never from the path.
- `info.xml`: the `<sabre>` block. Bump to **0.12.1**, `occ upgrade`, restart the web container (a new DAV collection
  behaves like a new route).
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

### M8 Dev: point the fork at the bridge

1. `projection_targets=recognize,own` and `occ facewright:project --target=own` clean. Then `occ config:system:set
   memories.recognize.provider --value=facewright_bridge`.
2. Capture, and compare with `stock.json`: identical.
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

### M9 Dev: rollback drill

1. Provider back to `recognize` (`occ config:system:delete memories.recognize.provider`). Capture and compare: identical.
   Flows with `PROVIDER=recognize` pass.
2. The store build back (M4's copy, without `.git/`). Capture and compare: identical.
3. Forward again: the fork, `.git/`, provider ours. Capture and compare: identical.
4. `dev/run-all.sh --ui`, with `PROVIDER=facewright_bridge` for the UI flows.

**Verify:** all four compares are identical and the run passes. Record the time each swap took.

### M10 Docs

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

Rollback at any point: the provider back to `recognize` (Recognize must still be enabled and a target, and
`occ facewright:project --target=recognize` repairs its copy), then the store build of the same version.

### M12 Keeping the fork current

On each Memories release:
1. `git fetch upstream --tags`, `git rebase --onto vX.Y.Z <old tag> facewright`.
2. M1's greps and checks.
3. Read the release's changes to `lib/ClustersBackend/`, `lib/Settings/SystemConfig.php`,
   `lib/Controller/RecognizeController.php`, `src/services/dav/` and `src/components/modal/Face*`. A new place that
   names a Recognize table or path must take the setting.
4. M2's build.
5. On dev: the store update to that release, then M4 (swap in the fork, capture, compare) and M8 step 3 (the UI flows).
6. Live with the user's go-ahead.

Security fixes reach us only this way. Watch the upstream repository's releases.

## Deviations from `docs/own-tables.md` Stage G as first written

- The tree serves reads only; writes stay in the router plugin, which gains a second root (G.3 had "the router's
  parsing moves into the collection's nodes"). Less code moves, and the battery already covers the router.
- The build overlays the official release tarball instead of rebuilding `bin-ext/` (simpler, no binary of ours).
- The Android-app question is closed: the user does not use it.

# Memories fork: choosing the face provider in the admin UI

Written for a model session that has not seen this repository or the fork before. Part 1 is what and why, and the
decisions; Part 2 is the work, step by step. Where they differ, Part 2 wins. The user asked for this on 2026-11-27.

## How to work this plan

- **Read first:** `CLAUDE.md` in full (this repo), then `docs/memories-fork.md` sections "The change", M2 (the build),
  M4/M9 (`dev/fork-swap.sh`) and M12 (the rebase). The fork exists to read our copy of the face data instead of
  Recognize's; this feature only adds a way to set the existing setting from the admin page.
- **Repositories.** This repo: `/home/constantin/recognAIze`. The fork: `/home/constantin/memories-fork`, branch
  `facewright`, remotes `origin` (the user's fork on GitHub) and `upstream` (pulsejet/memories). There is no PHP or
  Node on the host: run `php -l`, `npm`, `vue-tsc` and `prettier` in containers, as `docs/memories-fork.md` shows
  (around its setup step 4) and as `scripts/facewright-build.sh` does with `$NODE_IMAGE`.
- **Dev only.** Test on the compose stack of this repo (`dev/README.md`). **Never touch the live instance**, and never
  run `make build` / `make run` (on this host they hit the live ExApp). Deploying to live is the user's step.
- **Nothing is pushed**, in either repository. **No tag is created, moved or deleted.** The tag `facewright-v11.1.4`
  marks the build live runs.
- Check outputs, not exit codes. Where a check can fail on the old code, show that it does.
- You cannot ask the user. Where this plan says "stop", or the code contradicts the plan, stop that step, leave the
  trees committed or clean (stash with a descriptive message), and report.
- Record what you ran and measured under each step's "Done" in this file, in the final commit of this repo.

---

# Part 1: What and why

## Today

The fork has a system setting `memories.recognize.provider` (default `recognize`): the app whose Recognize-format
tables (`{provider}_face_detections`, `{provider}_face_clusters`) Memories reads and whose WebDAV tree
(`/remote.php/dav/{provider}/`) it writes to. Live sets it to `facewright_bridge`. The only way to set it is `occ
config:system:set memories.recognize.provider --value=...`; the rollback runbook deletes it with `occ
config:system:delete`.

What already exists and can be reused:
- `lib/Settings/SystemConfig.php`: the key is in `DEFAULTS`, so `AdminController::getSystemConfig()` already returns it
  and `setSystemConfig()` already accepts it (type-checked as a string). `SystemConfig::set()` deletes an app key that
  is set to its default, so choosing `recognize` removes the key, exactly what the runbook does by hand.
  `recognizeProvider()` resolves it, falls back to `recognize` on a value that fails `^[a-z0-9_]+$`, and logs that.
- `src/components/admin/AdminTypes.ts` already has the key in `ISystemConfig`.
- The admin page (`src/components/admin/AdminMain.vue`) provides `useAdminContext()`: `systemConfig`, `status` (from
  `AdminController::getSystemStatus()`, refreshed 500 ms after every `update()`), and `update(key, value)`, which PUTs
  and reverts on failure.
- The section "Recommended Apps" (`src/components/admin/sections/Apps.vue`) shows a note card about Recognize, from the
  *user* config (`recognize_installed`, `recognize_enabled`), which is loaded once per page and not refreshed.

Problems with that card today: with the provider set to `facewright_bridge`, it says "Recognize is installed and
enabled for face recognition", which is false (Recognize is disabled on live). And after a change it would stay stale
until a reload.

## Goal

An admin can see which app provides faces to Memories, choose another one from the apps that can, and see at once
whether the choice works, on Memories' own admin page. No `occ` needed, and nothing in the frontend names our app.

## Decisions

These were made by the planning session; the user asked for the feature and the plan. Do not reopen them in the
implementation; if one turns out impossible, stop and report.

1. **A dropdown of detected providers, not a free-text field.** A provider is an **enabled** app that has both
   `{app}_face_detections` and `{app}_face_clusters` tables. Recognize qualifies, `facewright_bridge` qualifies, and
   nothing in the fork names `facewright_bridge`: it stays usable upstream (pulsejet/memories#1812). A free-text field
   would let a typo silently break the People page.
2. **The current value is always an option**, even if it no longer qualifies (app disabled, tables gone), shown with
   what is wrong. Otherwise the admin could not see what is set.
3. **Detection and state come from `getSystemStatus()`**, not from the user config, so they refresh after a change
   like the rest of the admin page. New status field `face_providers` (shape in step 2).
4. **`SystemConfig::set()` rejects a provider value that fails `^[a-z0-9_]+$`** (the same pattern as
   `recognizeProvider()`), so the API cannot store what the reader would ignore. It does **not** reject an app that is
   not a detected provider: an admin may set it before installing the app, as with `occ`.
5. **Where:** in "Recommended Apps", replacing the Recognize note card with a small block: the dropdown, and one note
   card for the chosen provider. Recognize's own conditions (version ≥ 3.8.0, `faces.enabled`) are mentioned only when
   the chosen provider is `recognize`. The Face Recognition card and the rest of the section stay.
6. **A separate commit on the fork**, on top of the build script: `facewright` becomes change, build script, admin UI.
   It can be dropped or proposed upstream on its own. M12 then cherry-picks three commits (step 5 updates the doc).
7. **Out of scope:** free text; listing disabled apps; refreshing open browser tabs of other users (a user's open
   Memories tab keeps the provider it loaded with until it reloads; the cached config is replaced by the server copy
   on the next page load); deploying to live; any change to the bridge.

---

# Part 2: The work

## Step 1. Backend: reject an invalid provider value

`lib/Settings/SystemConfig.php`, `set()`. After the type check, if `$key === 'memories.recognize.provider'` and the
value is a string that fails `^[a-z0-9_]+$`, throw `\InvalidArgumentException`. Put the pattern in one private const
shared with `recognizeProvider()` so the two cannot drift.

- Find out how `Util::guardEx()` maps `\InvalidArgumentException` (read it). If it is not a 4xx, do not change
  `guardEx`; report the status you see.
- Check on dev (fork deployed, logged in as admin, with `curl` and an app password or the admin's basic auth as
  `dev/lib.sh` does): `PUT /apps/memories/api/system-config/memories.recognize.provider` (check the exact route in
  `appinfo/routes.php`) with `{"value":"Bad-Value"}` answers an error, and `occ config:system:get
  memories.recognize.provider` is unchanged. On the old code the same request stores `Bad-Value` (show it).

**Done:** (fill in)

## Step 2. Backend: detect providers in the system status

`lib/Controller/AdminController.php`, `getSystemStatus()`. Add:

```
$status['face_providers'] = [
  ['id' => 'recognize',         'name' => 'Recognize',  'enabled' => true,  'tables' => true],
  ['id' => 'facewright_bridge', 'name' => '<from info.xml>', 'enabled' => true, 'tables' => true],
  ...
];
```

- Candidates: every **enabled** app (`IAppManager`; check which method lists enabled apps on the Nextcloud version of
  dev, and do not use a deprecated one if a current one exists), plus the current value of `recognizeProvider()`
  whatever its state.
- An app qualifies if `IDBConnection::tableExists('{app}_face_detections')` and `tableExists('{app}_face_clusters')`.
  Check the detections table first and skip the second check when it is missing. Confirm `tableExists()` takes the
  name without the prefix on this version (read it in the server source in the dev container).
- `name`: the app's name from `IAppManager::getAppInfo()`, falling back to the id. Use the method the controller can
  get by constructor injection; the controller already has `IDBConnection` (`$this->connection`).
- List only qualifying apps, plus the current value (with its real `enabled` / `tables`). Sort by name.
- For `recognize`, add `'recognize_faces_enabled' => bool` (its `faces.enabled` app config, read as
  `SystemConfig::recognizeIsEnabled()` does) so the UI can say why Recognize does not show faces. Read it through
  `IAppConfig` as the existing code does; do not call Recognize's classes.
- Wrap the detection in `try/catch` like the GIS block below it: a failure must not break the status page; on failure
  set `face_providers` to `[]` and log a warning with the app id `memories`.
- **Cost:** measure `GET .../api/system-status` (check the route) on dev before and after, five times each, and record
  the medians. If the detection adds more than 200 ms, stop and report instead of optimising.
- Add the field to `ISystemStatus` in `src/components/admin/AdminTypes.ts`:
  `face_providers: { id: string; name: string; enabled: boolean; tables: boolean; recognize_faces_enabled?: boolean }[];`
- Check on dev: with the provider unset, the list has Recognize (if enabled on dev) and `facewright_bridge` (its `own`
  tables exist on dev once the bridge is installed; if they don't, report). Disable Recognize, and it leaves the list
  (unless it is the current value). Set the provider to `nosuchapp` with `occ`, and it is listed with `enabled: false,
  tables: false`. Put everything back.

**Done:** (fill in)

## Step 3. Frontend: the dropdown and the note card

`src/components/admin/sections/Apps.vue`.

- Replace the Recognize `NcNoteCard` with:
  - An `NcSelect` (look at how other admin sections use it, e.g. `grep -rn NcSelect src/components/admin`; follow
    their import path and props), label `t('memories', 'Face recognition provider')`, options from
    `status.face_providers` (label: name, plus ` (id)` when name and id differ), the selected value from
    `systemConfig['memories.recognize.provider']`, and on change `update('memories.recognize.provider', id)`. Not
    clearable. Disabled while `status` is null.
  - One `NcNoteCard` for the selected provider, from the status entry (not from `userConfig`):
    - enabled and tables: success, "{name} provides faces to Memories."; for `recognize` with
      `recognize_faces_enabled === false`, warning, "Recognize is installed, but its face recognition is not enabled."
    - not enabled: error, "{name} is not enabled. The People view is unavailable."
    - no tables: error, "{name} has no face tables. The People view is unavailable."
    - no entry at all (status failed): no card.
  - A short help line under the select: "The app whose face data Memories shows, and which receives edits made in
    Memories. Users need to reload Memories after a change." Keep it to that.
- Strings through `t('memories', ...)` with placeholders, as the file does now. No new dependency.
- Remove the `userConfig.recognize_*` reads from this file only if nothing else in it needs them. Do not change
  `user-config.ts`, `OtherController`, or anything outside the admin components.
- `npx vue-tsc --noEmit -p .` and `npx prettier --check` on the changed `src/` files must pass (in the container).
- Check in headless Chromium on dev (`/settings/admin/memories`, admin login with the real password, as
  `dev/README.md` says for UI checks):
  1. The select shows the current provider and the card says it works.
  2. Switching to the other provider: `occ config:system:get memories.recognize.provider` shows the new value; the
     card follows within a second (status refresh) without a reload.
  3. Choosing `recognize`: the key is **gone** from `config.php` (`occ config:system:get` exits 1), not set to
     `recognize`.
  4. In a new tab, Memories' People view reads the chosen provider: the face list's WebDAV/API requests go to
     `/dav/{provider}/` (the `memories-ui/flows.py` harness already watches this; reuse its approach, not its file).
  5. With `nosuchapp` set by `occ`, the select shows it and the card is the error.
  Put the provider back to what it was before the check.

**Done:** (fill in)

## Step 4. Commit, build, regression

1. In the fork: one commit, `Admin: choose the face provider from detected apps`, on top of the build script commit.
   Trailer: `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`. Check `git log --oneline v11.1.4..facewright`
   shows three commits: change, build script, this.
2. `scripts/facewright-build.sh` (refuses with uncommitted or untracked files; commit first). Record both sha256 lines.
3. `dev/fork-swap.sh fork <the new tarball>`, then `dev/run-all.sh --ui` in this repo (commit this repo's changes
   first: `rollback-drill.sh` refuses to run otherwise). Every suite must pass. Record the count.
4. Add a dev check for the admin flow so it stays tested: a small Playwright script next to `dev/memories-ui/flows.py`
   (e.g. `dev/memories-ui/admin_provider.py`) doing step 3's checks 1 to 3 and putting the value back, wired into
   `dev/run-all.sh` under `--ui` and listed in `dev/README.md`'s table. Run it alone and inside `run-all.sh --ui`.

**Done:** (fill in)

## Step 5. Docs (this repo)

- `docs/memories-fork.md`:
  - "The change": a short paragraph on the admin UI commit (what it adds, that it names no provider).
  - M12: three commits to cherry-pick, not two.
  - Wherever it says to set or delete the setting with `occ` (setup, the rollback), add that the admin page
    ("Recommended Apps") does the same; keep the `occ` commands, they are what the runbooks use.
- `docs/cleanup.md` section 0's runbook, step 1: same one-line note.
- `README.md`, the fork section: one sentence that the provider can be chosen on Memories' admin page.
- `CLAUDE.md`, "Memories (the fork)" under Deployment: one bullet: the provider can be chosen in Memories' admin page,
  which lists only enabled apps with both tables, and choosing `recognize` deletes the key.
- `docs/README.md`: this document's row, status "done on dev".
- One commit in this repo with the doc changes and this file's "Done" sections. Do not push.

**Done:** (fill in)

## Afterwards (for the user, not this plan)

- Push the fork and this repo.
- Deploy the new fork build to live (same version, swapped folder, `.git/` pin; `docs/memories-fork.md` M11), with a
  fresh dump and a go-ahead. Then the live provider is visible on the admin page; nothing else changes.
- Optionally propose the admin UI to upstream together with the provider setting (#1812).

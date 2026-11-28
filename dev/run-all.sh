#!/bin/bash
# Run every check against the dev stack and print one line per suite. Restores the snapshot "original" before
# each suite that changes data (and at the end), so every suite starts from the same data. If there is no
# snapshot yet, make one first:   dev/snapshot.sh save original
#   dev/run-all.sh          everything except the Memories UI flows
#   dev/run-all.sh --ui     also the Memories UI flows (needs dev/setup-venv.sh)
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
ui=no; [ "${1:-}" = "--ui" ] && ui=yes
# The suites share one database and restore snapshots into it: two runs at once corrupt each other's results (this
# happened: eleven suites failed that passed alone). One run at a time; do not start a suite by hand while this runs.
exec 9>"$WORK_DIR/run-all.lock"
flock -n 9 || { echo "another dev run is active (lock: $WORK_DIR/run-all.lock); wait for it"; exit 2; }
ls "$WORK_DIR/snapshots/original"* >/dev/null 2>&1 || { echo "no snapshot 'original': run dev/snapshot.sh save original"; exit 2; }
# The baseline every suite starts from (dev/README.md): fork mode, Recognize disabled, one projection target.
[ "$(occ config:system:get memories.recognize.provider 2>/dev/null)" = facewright_bridge ] && [ "$(occ config:app:get facewright_bridge projection_targets 2>/dev/null)" = own ] \
  && occ app:list 2>/dev/null | sed -n '/^Disabled/,$p' | grep -q ' recognize:' \
  || { echo "not at the baseline: provider facewright_bridge, projection_targets=own, Recognize disabled (dev/README.md)"; exit 2; }
declare -a names results
run() { local name=$1; shift
  "$DEV_DIR/snapshot.sh" restore original > "$WORK_DIR/last-restore.log" 2>&1 || { names+=("$name"); results+=(99); printf '%-28s %-9s (the snapshot restore failed: %s)\n' "$name" "FAIL (99)" "$(tail -1 "$WORK_DIR/last-restore.log")"; return; }
  local t0=$SECONDS
  "$@" > "$WORK_DIR/last-$name.log" 2>&1; local rc=$?
  names+=("$name"); results+=("$rc"); printf '%-28s %-9s %4d s   (log: %s)\n' "$name" "$([ $rc -eq 0 ] && echo PASS || echo "FAIL ($rc)")" "$((SECONDS-t0))" "$WORK_DIR/last-$name.log"; }
# The suites that write clustering results, again under clustering_engine=exapp (docs/exapp-clustering.md, S3).
with_exapp() { occ config:app:set facewright_bridge clustering_engine --value=exapp >/dev/null; "$@"; local rc=$?
  occ config:app:delete facewright_bridge clustering_engine >/dev/null; return $rc; }
# memories-ui/admin_provider.py lists both providers and starts with the provider unset, so it needs Recognize enabled.
# Put everything back afterwards, Recognize's jobs included (CLAUDE.md, "Coexisting with Recognize"). Step 5 of
# docs/memories-fork.md (Recognize uninstalled) changes this suite.
with_stock_memories() { local prov; prov=$(occ config:system:get memories.recognize.provider 2>/dev/null)
  occ config:system:delete memories.recognize.provider >/dev/null; occ app:enable recognize >/dev/null; reload_web
  "$@"; local rc=$?
  occ app:disable recognize >/dev/null
  for id in $(sql "SELECT id FROM oc_jobs WHERE class LIKE '%Recognize%MaintenanceJob' OR class LIKE '%ClusterFacesJob'"); do echo y | docker exec -i -u www-data "$NC_CONTAINER" php occ background-job:delete "$id" >/dev/null 2>&1; done
  [ -n "$prov" ] && occ config:system:set memories.recognize.provider --value="$prov" >/dev/null; reload_web
  return $rc; }
echo "(a snapshot restore precedes every suite)"
run reads-stable           "$DEV_DIR/reads.sh" stable
run reads-direct-stable    "$DEV_DIR/reads-direct.sh" stable
run dav-battery            "$DEV_DIR/battery.sh"
run writers-people         "$DEV_DIR/writers-people.sh"
run people-stats           "$DEV_DIR/people-stats.sh"
run writers-cluster        "$DEV_DIR/writers-cluster.sh"
run persist-guard          "$DEV_DIR/persist-guard.sh"
run own-traps              "$DEV_DIR/own-traps.sh"
run writers-files          "$DEV_DIR/writers-files.sh"
run chain-dangling         "$DEV_DIR/chain-loop-test.sh" dangling
run chain-assignable       "$DEV_DIR/chain-loop-test.sh" assignable
run project-drift          "$DEV_DIR/project-drift.sh"
run project-failure        "$DEV_DIR/project-failure.sh"
run project-concurrency    "$DEV_DIR/project-concurrency.sh"
# PEOPLE=50 everywhere: the suite keeps one detection cache and deletes the others, and cluster-compare needs this one.
run clustering-truth       env PEOPLE=50 "$DEV_DIR/clustering-truth.sh"      # downloads the dataset on first use
# Clustering in the ExApp (docs/exapp-clustering.md): the ExApp's cache and /cluster, the client, the comparison, the
# stored checksum, the engine and the cache hygiene; then the writers again with the exapp engine.
run exapp-cluster          "$DEV_DIR/exapp-cluster.sh"
run exapp-client           "$DEV_DIR/exapp-client.sh"
run cluster-inmemory       "$DEV_DIR/cluster-inmemory.sh" --prepare
run cluster-compare        "$DEV_DIR/cluster-compare.sh"
run embedding-crc          "$DEV_DIR/embedding-crc.sh"
run cluster-engine         "$DEV_DIR/cluster-engine.sh"
run cache-hygiene          "$DEV_DIR/cache-hygiene.sh"
run writers-cluster-exapp  with_exapp "$DEV_DIR/writers-cluster.sh"
run chain-dangling-exapp   with_exapp "$DEV_DIR/chain-loop-test.sh" dangling
run chain-assignable-exapp with_exapp "$DEV_DIR/chain-loop-test.sh" assignable
run clustering-truth-exapp with_exapp env PEOPLE=50 "$DEV_DIR/clustering-truth.sh"
# The projection (docs/memories-fork.md, M6) and the bridge's DAV tree (M7).
run project-own            "$DEV_DIR/project-own.sh"
# Memories' own UI against the fork: it reads the own copy tables and writes to the bridge's tree.
[ $ui = yes ] && run memories-ui "$WORK_DIR/venv/bin/python" "$DEV_DIR/memories-ui/flows.py"
# The provider dropdown on Memories' admin page (docs/memories-fork-admin-ui.md); it sets and restores the system setting itself.
[ $ui = yes ] && run memories-ui-admin-provider with_stock_memories "$WORK_DIR/venv/bin/python" "$DEV_DIR/memories-ui/admin_provider.py"
"$DEV_DIR/snapshot.sh" restore original | tail -1
fail=0; for r in "${results[@]}"; do [ "$r" -ne 0 ] && fail=$((fail+1)); done
echo "$((${#results[@]}-fail)) of ${#results[@]} suites passed"; [ $fail -eq 0 ]

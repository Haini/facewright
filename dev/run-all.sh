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
declare -a names results
run() { local name=$1; shift
  "$DEV_DIR/snapshot.sh" restore original >/dev/null
  "$@" > "$WORK_DIR/last-$name.log" 2>&1; local rc=$?
  names+=("$name"); results+=("$rc"); printf '%-28s %s   (log: %s)\n' "$name" "$([ $rc -eq 0 ] && echo PASS || echo "FAIL ($rc)")" "$WORK_DIR/last-$name.log"; }
echo "(a snapshot restore precedes every suite)"
run reads-stable           "$DEV_DIR/reads.sh" stable
run reads-direct-stable    "$DEV_DIR/reads-direct.sh" stable
run dav-battery            "$DEV_DIR/battery.sh"
run writers-people         "$DEV_DIR/writers-people.sh"
run writers-cluster        "$DEV_DIR/writers-cluster.sh"
run writers-files          "$DEV_DIR/writers-files.sh"
run chain-dangling         "$DEV_DIR/chain-loop-test.sh" dangling
run chain-assignable       "$DEV_DIR/chain-loop-test.sh" assignable
run project-drift          "$DEV_DIR/project-drift.sh"
run project-failure        "$DEV_DIR/project-failure.sh"
run project-concurrency    "$DEV_DIR/project-concurrency.sh"
run project-recognize-trap "$DEV_DIR/project-recognize-trap.sh"
run project-recognize-copies "$DEV_DIR/project-recognize-copies.sh"
run rollback-drill         "$DEV_DIR/rollback-drill.sh"
run clustering-truth       "$DEV_DIR/clustering-truth.sh"      # downloads the dataset on first use
[ $ui = yes ] && run memories-ui "$WORK_DIR/venv/bin/python" "$DEV_DIR/memories-ui/flows.py"
"$DEV_DIR/snapshot.sh" restore original | tail -1
fail=0; for r in "${results[@]}"; do [ "$r" -ne 0 ] && fail=$((fail+1)); done
echo "$((${#results[@]}-fail)) of ${#results[@]} suites passed"; [ $fail -eq 0 ]

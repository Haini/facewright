#!/bin/bash
# Step S3 of docs/exapp-clustering.md: ClusterScanJob under clustering_engine=exapp. Every chain starts from the snapshot
# "original" with admin reset (every face pending), and its jobs are run by hand, one at a time, as cron would.
#   (a) an exapp chain runs one job per pass, ends, and leaves the same people and the same unclusterable faces as a
#       php chain
#   (b) the ExApp down when the chain starts: the chain falls back, logs one warning, finishes in PHP with the same result
#   (c) PHP's peak memory: one exapp pass against one php chunk, on admin's whole reset pool
#   (d) planted in the code: the exapp path writing the ExApp's negative labels without making people. persistAssignments()
#       must refuse before writing anything, and the chain must fall back
#   (e) planted in the code: a failure after the pass made its people. They must be deleted again
# Changes data and the app config clustering_engine: restores the snapshot and deletes the config key at the end.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
PASS=0; FAIL=0
: "${FW_CONTAINER:=recognaize-facewright-1}"
JOB='OCA\FacewrightBridge\BackgroundJob\ClusterScanJob'
F=$REPO_DIR/facewright_bridge/lib/Service/ClusteringService.php
LOG=/var/www/html/data/nextcloud.log
W=$WORK_DIR/cluster-engine; rm -rf "$W"; mkdir -p "$W"
cp "$F" "$W/ClusteringService.php.good"
cleanup() { cp "$W/ClusteringService.php.good" "$F"; docker start "$FW_CONTAINER" >/dev/null 2>&1; }
trap cleanup EXIT

helper() { docker cp "$DEV_DIR/cluster_engine.php" "$NC_CONTAINER:/tmp/cluster_engine.php" >/dev/null; docker exec -u www-data "$NC_CONTAINER" php /tmp/cluster_engine.php "$@"; }
engine() { occ config:app:set facewright_bridge clustering_engine --value="$1" >/dev/null; }
fixture() {
  "$DEV_DIR/snapshot.sh" restore original >/dev/null
  sql "DELETE FROM oc_jobs WHERE class LIKE '%ClusterScanJob'"
  helper reset "$ADMIN_USER" >/dev/null
}
members() { DB_CONTAINER=$DB_CONTAINER DB_USER=$DB_USER DB_PASS=$DB_PASS DB_NAME=$DB_NAME python3 "$DEV_DIR/members.py" "$W/$1.json" "$ADMIN_USER,$BOB_USER" >/dev/null; }
same() { python3 -c 'import json,sys; sys.exit(0 if json.load(open(sys.argv[1])) == json.load(open(sys.argv[2])) else 1)' "$W/$1.json" "$W/$2.json" && echo same || echo differ; }
log_size() { docker exec "$NC_CONTAINER" stat -c %s "$LOG"; }
log_since() { docker exec "$NC_CONTAINER" tail -c +$(( $1 + 1 )) "$LOG"; }
next_job() { occ background-job:list --class="$JOB" 2>&1 | grep -E '^\| [0-9]' | head -1; }
admin_job() { occ background-job:list --class="$JOB" 2>&1 | grep -E '^\| [0-9]' | grep "\"userId\":\"$ADMIN_USER\"" | head -1; }  # bob has a chain too
run_one() {  # run_one ARGS_FILE : runs the next queued chain job, appends its argument line; false if none was queued
  local line; line=$(next_job); [ -z "$line" ] && return 1
  echo "$line" >> "$1"
  occ background-job:execute "$(awk '{print $2}' <<<"$line")" --force-execute >/dev/null 2>&1
}
run_chain() {  # run_chain NAME : runs every queued chain job (at most 80); NAME.args holds one line per run
  local n=0; while [ $n -lt 80 ] && run_one "$W/$1.args"; do n=$((n + 1)); done
}
runs() { grep -c "\"userId\":\"$ADMIN_USER\"" "$W/$1.args"; }
runs_on() { grep "\"userId\":\"$ADMIN_USER\"" "$W/$1.args" | grep -c "\"engine\":\"$2\""; }
heartbeat() { for _ in $(seq 60); do docker exec "$NC_CONTAINER" curl -s -o /dev/null -m 2 -w '%{http_code}' http://facewright:9030/heartbeat 2>/dev/null | grep -q 200 && return 0; sleep 1; done; return 1; }
uncl() { one "SELECT COUNT(*) FROM oc_facewright_user_faces WHERE user_id='$ADMIN_USER' AND state=3"; }

echo "== (a) a php chain and an exapp chain from the same data"
fixture; engine php; occ facewright:cluster-scan >/dev/null; run_chain php; members php
echo "    php: $(runs php) runs for $ADMIN_USER; $(uncl) unclusterable"
need "the php chain marked faces unclusterable" "$(uncl)" 1
fixture; engine exapp; occ facewright:cluster-scan >/dev/null; run_chain exapp; members exapp
check "exapp: every run of the chain on the ExApp" "$(runs exapp)" "$(runs_on exapp exapp)"
check "exapp: one run per pass (one that writes, one that writes nothing and marks)" 2 "$(runs exapp)"
check "exapp: the chain ended" "" "$(next_job)"
check "same people and same unclusterable faces as php" same "$(same php exapp)"
verify_step "the exapp chain"

echo "== (b) the ExApp down when the chain starts"
fixture; engine exapp; occ facewright:cluster-scan >/dev/null
docker stop "$FW_CONTAINER" >/dev/null
off=$(log_size)
run_one "$W/fallback.args"
check "the failed run wrote nothing" 0 "$(one "SELECT COUNT(*) FROM oc_facewright_user_faces WHERE user_id='$ADMIN_USER' AND state=1")"
check "the chain goes on in PHP, from the start" yes "$(admin_job | grep -q '"afterId":0,"passProgress":false,"engine":"php"' && echo yes || echo no)"
docker start "$FW_CONTAINER" >/dev/null; heartbeat || echo "    (the ExApp did not answer its heartbeat within 60 s)"
run_chain fallback; members fallback
check "  and stays in PHP with the ExApp back" "$(( $(runs fallback) - 1 ))" "$(runs_on fallback php)"
check "one warning logged" 1 "$(log_since "$off" | grep -c 'continuing this chain in PHP')"
check "same result as the php chain" same "$(same php fallback)"
verify_step "the fallback chain"

echo "== (c) PHP peak memory, admin's whole pool pending"
fixture; read -r _ _ pb pp pool pa pw < <(helper mem "$ADMIN_USER" php)
fixture; read -r _ _ eb ep epool ea ew < <(helper mem "$ADMIN_USER" exapp)
echo "    php chunk:  pool $pool, $pa attempted, $pw written; peak $pp MB (from $pb MB)"
echo "    exapp pass: pool $epool, $ea attempted, $ew written; peak $ep MB (from $eb MB)"
check "an exapp pass holds less than a php chunk" yes "$(python3 -c "print('yes' if $ep - $eb < $pp - $pb else 'no')")"

echo "== (d) planted in the code: negative labels written without making people"
python3 - "$F" <<'PY'
import sys
p = sys.argv[1]; s = open(p).read()
old = "['cluster_id' => $label > 0 ? $label : $personOf[$label]]"
assert s.count(old) == 1
open(p, "w").write(s.replace(old, "['cluster_id' => $label] /* PLANTED */"))
PY
fixture; engine exapp; occ facewright:cluster-scan >/dev/null
off=$(log_size)
run_one "$W/planted.args"
check "nothing written" 0 "$(one "SELECT COUNT(*) FROM oc_facewright_user_faces WHERE user_id='$ADMIN_USER' AND state<>0 AND state<>4")"
check "no person left behind" 0 "$(one "SELECT COUNT(*) FROM oc_facewright_clusters WHERE user_id='$ADMIN_USER'")"
check "refused by persistAssignments(), logged as the fallback" 1 "$(log_since "$off" | grep 'continuing this chain in PHP' | grep -c 'Refusing to persist cluster id -')"
check "the chain goes on in PHP" yes "$(admin_job | grep -q '"engine":"php"' && echo yes || echo no)"
cp "$W/ClusteringService.php.good" "$F"
check "ClusteringService restored" 0 "$(grep -c PLANTED "$F")"
run_chain planted; members planted
check "and ends with the php chain's result" same "$(same php planted)"

echo "== (e) planted in the code: a failure after the people were made"
python3 - "$F" <<'PY'
import sys
p = sys.argv[1]; s = open(p).read()
old = "\t\t\t$assignments = [];\n"
assert s.count(old) == 1
open(p, "w").write(s.replace(old, "\t\t\tthrow new ExAppClusteringException('PLANTED after ' . count($created) . ' people');\n" + old))
PY
fixture; engine exapp; occ facewright:cluster-scan >/dev/null
off=$(log_size)
run_one "$W/planted2.args"
need "people were made before the failure" "$(log_since "$off" | grep -o 'PLANTED after [0-9]*' | grep -o '[0-9]*$' | head -1)" 1
check "and deleted again" 0 "$(one "SELECT COUNT(*) FROM oc_facewright_clusters WHERE user_id='$ADMIN_USER'")"
check "nothing written" 0 "$(one "SELECT COUNT(*) FROM oc_facewright_user_faces WHERE user_id='$ADMIN_USER' AND state<>0 AND state<>4")"
cp "$W/ClusteringService.php.good" "$F"
check "ClusteringService restored" 0 "$(grep -c PLANTED "$F")"
sql "DELETE FROM oc_jobs WHERE class LIKE '%ClusterScanJob'"

occ config:app:delete facewright_bridge clustering_engine >/dev/null
"$DEV_DIR/snapshot.sh" restore original | tail -1
docker exec "$NC_CONTAINER" rm -f /tmp/cluster_engine.php
summary cluster-engine

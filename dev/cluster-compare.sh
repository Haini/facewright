#!/bin/bash
# Step C4 of docs/exapp-clustering.md: occ facewright:cluster-compare on dev.
#   1. admin's faces reopened: no unexplained difference, and Nextcloud's tables unchanged (processing paused)
#   2. the ground-truth user (dev/clustering-truth.sh's detection cache for PEOPLE=50): everything pending, in chunks of
#      100; then clustered for real and every third assigned face reopened, so that those must join existing people
#      (a person's first face pulls in all 15 of its faces at once, so a pending-only run never joins)
#   3. planted faults in the ExApp (its file is swapped in the running container and restored), each on data where it
#      changes PHP's own result: min_faces ignored on admin's reopened pool (it has skips), and the threshold 0.15 too
#      tight on the ground-truth user (dev data sits far from the threshold: nothing changes between 0.4 and 0.75 for
#      either user, so a small offset is invisible here, and the boundary and tie classes cannot occur on dev).
#      Each must give unexplained differences and exit 1.
#   4. nothing to attempt (admin after a restore has no pending face): exit 2
# Changes data (prepares rows): restores the snapshot "original" before and after.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
: "${FW_CONTAINER:=recognaize-facewright-1}"; : "${GT_USER:=gt}"
PASS=0; FAIL=0
compare() { docker exec -u www-data "$NC_CONTAINER" php occ facewright:cluster-compare "$@"; }
summary_line() { grep -E '^Differences:' <<<"$1"; }
fingerprint() {
  local t out=""
  for t in oc_facewright_user_faces oc_facewright_clusters oc_facewright_faces oc_recognize_face_detections oc_recognize_face_clusters oc_jobs; do
    out+="$t:$(one "SELECT COUNT(*) FROM $t"):$(sql "CHECKSUM TABLE $t" | cut -f2) "
  done
  echo "$out"
}
fw_restart() { docker restart "$FW_CONTAINER" >/dev/null
  for i in $(seq 1 90); do [ "$(docker exec "$NC_CONTAINER" curl -s -o /dev/null -w '%{http_code}' http://facewright:9030/heartbeat)" = 200 ] && return; sleep 2; done; }
plant() {  # plant LABEL PYTHON_OLD PYTHON_NEW : swap the ExApp's clustering.py for a faulted copy
  docker exec "$FW_CONTAINER" cp /ex_app/lib/clustering.py /tmp/clustering.py.good
  docker exec -i "$FW_CONTAINER" python3 - "$2" "$3" <<'PY'
import sys
p = "/ex_app/lib/clustering.py"; s = open(p).read(); old, new = sys.argv[1], sys.argv[2]
assert s.count(old) == 1, "planted fault: anchor not found once"
open(p, "w").write(s.replace(old, new))
PY
  fw_restart
}
unplant() { docker exec "$FW_CONTAINER" cp /tmp/clustering.py.good /ex_app/lib/clustering.py; fw_restart; }
trap 'occ config:app:delete facewright_bridge processing_paused >/dev/null 2>&1' EXIT

"$DEV_DIR/snapshot.sh" restore original >/dev/null
occ config:app:set facewright_bridge processing_paused --value=true >/dev/null

echo "== 1. admin, reopened"
sql "UPDATE oc_facewright_user_faces SET state = 3, cluster_id = NULL WHERE user_id = '$ADMIN_USER' AND state = 1"
need "unclusterable faces of admin" "$(one "SELECT COUNT(*) FROM oc_facewright_user_faces WHERE user_id='$ADMIN_USER' AND state=3")" 100
before=$(fingerprint)
out=$(compare "$ADMIN_USER" --reopen); rc=$?
echo "$out" | grep -E '^(Pool|Cache|Totals|PHP peak|Differences)' | sed 's/^/    /'
check "exit" 0 "$rc"
check "differences" "Differences: 0 boundary, 0 tie, 0 unexplained." "$(summary_line "$out")"
check "people formed (PHP = ExApp, and some)" yes "$(grep -qE 'PHP [1-9][0-9]* new .* \| ExApp [1-9][0-9]* new' <<<"$out" && echo yes || echo no)"
check "Nextcloud's tables unchanged" "$before" "$(fingerprint)"

echo "== 2. ground-truth user, everything pending, chunks of 100"
REPO_DIR="$(cd "$DEV_DIR/.." && pwd)"
key=$({ echo 50; cat "$REPO_DIR/ex_app/lib/main.py" "$REPO_DIR/facewright_bridge/lib/Service/DetectionService.php" "$REPO_DIR/facewright_bridge/lib/Service/FaceVectorCodec.php"; } | sha256sum | cut -c1-16)
cache="$WORK_DIR/gt-cache/$key.sql"
if [ -s "$cache" ] && [ -n "$(one "SELECT 1 FROM oc_users WHERE uid='$GT_USER'")" ]; then
  docker exec -i "$DB_CONTAINER" mariadb -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" 2>/dev/null < "$cache"
  need "pending faces of $GT_USER" "$(one "SELECT COUNT(*) FROM oc_facewright_user_faces WHERE user_id='$GT_USER' AND state=0")" 500
  out=$(compare "$GT_USER" --chunk-size=100); rc=$?
  echo "$out" | grep -E '^(Pool|Cache|Totals|PHP peak|Differences)' | sed 's/^/    /'
  check "exit" 0 "$rc"
  check "differences" "Differences: 0 boundary, 0 tie, 0 unexplained." "$(summary_line "$out")"
  occ facewright:cluster "$GT_USER" --min-faces=3 | grep -E 'cluster\(s\)' | sed 's/^/    real run: /'
  sql "UPDATE oc_facewright_user_faces SET state = 3, cluster_id = NULL WHERE user_id = '$GT_USER' AND state = 1 AND id % 3 = 0"
  need "reopened faces of $GT_USER" "$(one "SELECT COUNT(*) FROM oc_facewright_user_faces WHERE user_id='$GT_USER' AND state=3")" 100
  out=$(compare "$GT_USER" --reopen --chunk-size=100); rc=$?
  echo "$out" | grep -E '^(Pool|Totals|Differences)' | sed 's/^/    /'
  check "reopened: exit" 0 "$rc"
  check "reopened: differences" "Differences: 0 boundary, 0 tie, 0 unexplained." "$(summary_line "$out")"
  check "reopened: faces joined existing people (PHP = ExApp, and some)" yes "$(grep -qE 'PHP [0-9]+ new [1-9][0-9]* join .* \| ExApp [0-9]+ new [1-9][0-9]* join' <<<"$out" && echo yes || echo no)"
  check "reopened: PHP and ExApp totals equal" yes "$(awk -F'[|]' '/^Totals/ { split($1, a, "PHP "); split($2, b, "ExApp "); sub(/, [0-9.]+ s.*/, "", a[2]); sub(/, [0-9.]+ s.*/, "", b[2]); print (a[2] == b[2] ? "yes" : "no") }' <<<"$out")"
  GT_OK=yes
else
  GT_OK=no
  echo "  SKIPPED: no detection cache for PEOPLE=50 ($cache) or no user $GT_USER; run PEOPLE=50 dev/clustering-truth.sh once"
  FAIL=$((FAIL + 1))
fi
target="$ADMIN_USER --reopen"

echo "== 3. planted faults in the ExApp"
plant "min_faces ignored" "if len(candidates) + 1 < min_faces:" "if len(candidates) + 1 < 1:  # PLANTED"
out=$(compare $target); rc=$?
check "min_faces ignored: exit" 1 "$rc"
check "min_faces ignored: unexplained differences" yes "$(grep -qE 'Differences: .* [1-9][0-9]* unexplained' <<<"$out" && echo yes || echo no)"
unplant
if [ $GT_OK = yes ]; then
  sql "UPDATE oc_facewright_user_faces SET state = 0, cluster_id = NULL WHERE user_id = '$GT_USER'"   # all pending again
  need "pending faces of $GT_USER" "$(one "SELECT COUNT(*) FROM oc_facewright_user_faces WHERE user_id='$GT_USER' AND state=0")" 500
  plant "threshold 0.15 too tight" "candidates = np.flatnonzero(distances <= max_distance)" "candidates = np.flatnonzero(distances <= max_distance - 0.15)  # PLANTED"
  out=$(compare "$GT_USER"); rc=$?
  check "threshold too tight: exit" 1 "$rc"
  check "threshold too tight: unexplained differences" yes "$(grep -qE 'Differences: .* [1-9][0-9]* unexplained' <<<"$out" && echo yes || echo no)"
  echo "$out" | grep -E '^  unexplained' | head -3 | sed 's/^/    /'
  unplant
else
  echo "  SKIPPED: threshold fault (needs the ground-truth user)"; FAIL=$((FAIL + 1))
fi
check "ExApp restored (no planted line)" 0 "$(docker exec "$FW_CONTAINER" grep -c PLANTED /ex_app/lib/clustering.py)"

echo "== 4. nothing to attempt"
"$DEV_DIR/snapshot.sh" restore original >/dev/null
occ config:app:set facewright_bridge processing_paused --value=true >/dev/null
need "no pending face of admin (0 = ok)" "$((1 - $(one "SELECT COUNT(*) FROM oc_facewright_user_faces WHERE user_id='$ADMIN_USER' AND state=0")))" 1
compare "$ADMIN_USER" >/dev/null; check "no pending faces: exit" 2 "$?"

"$DEV_DIR/snapshot.sh" restore original | tail -1
summary cluster-compare

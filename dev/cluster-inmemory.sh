#!/bin/bash
# Step C2 of docs/exapp-clustering.md: ClusteringService::loadPoolForComparison() and assignInMemory() write nothing,
# --reopen adds exactly the unclusterable rows, and persistAssignments() refuses a temporary label. Run with processing
# paused or on a quiet stack: the "nothing written" checks fingerprint whole tables, oc_jobs included.
#   dev/cluster-inmemory.sh [--prepare] [USER]     (default: admin; needs a user with faces)
# --prepare first marks the user's assigned faces unclusterable, so that --reopen has a real pool to cluster. It changes
# data: restore a snapshot afterwards (dev/snapshot.sh restore original).
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
prepare=no; [ "${1:-}" = "--prepare" ] && { prepare=yes; shift; }
user=${1:-$ADMIN_USER}
if [ $prepare = yes ]; then
  sql "UPDATE oc_facewright_user_faces SET state = 3, cluster_id = NULL WHERE user_id = '$user' AND state = 1"
  echo "  prepared: $(one "SELECT COUNT(*) FROM oc_facewright_user_faces WHERE user_id='$user' AND state=3") unclusterable faces of $user"
fi
occ config:app:set facewright_bridge processing_paused --value=true >/dev/null
trap 'occ config:app:delete facewright_bridge processing_paused >/dev/null' EXIT
need "faces of $user" "$(one "SELECT COUNT(*) FROM oc_facewright_user_faces WHERE user_id='$user'")" 1
docker cp "$DEV_DIR/cluster_inmemory.php" "$NC_CONTAINER:/tmp/cluster_inmemory.php" >/dev/null
PASS=0; FAIL=0
while read -r kind rest; do
  case $kind in
    info) echo "  $rest" ;;
    check) python3 - "$rest" <<'PY' && PASS=$((PASS + 1)) || FAIL=$((FAIL + 1))
import json, sys
dec = json.JSONDecoder(); s = sys.argv[1]; vals = []; i = 0
while i < len(s):
    if s[i] == " ": i += 1; continue
    v, i = dec.raw_decode(s, i); vals.append(v)
label, expected, actual = vals
ok = expected == actual
print(f"  {'ok  ' if ok else 'FAIL'} {label}" + ("" if ok else f": expected {str(expected)[:200]}, got {str(actual)[:200]}"))
sys.exit(0 if ok else 1)
PY
    ;;
    *) echo "  ?? $kind $rest"; FAIL=$((FAIL + 1)) ;;
  esac
done < <(docker exec -u www-data "$NC_CONTAINER" php /tmp/cluster_inmemory.php "$user" 2>&1)
docker exec "$NC_CONTAINER" rm -f /tmp/cluster_inmemory.php
echo; echo "cluster-inmemory: $PASS passed, $FAIL failed"
[ "$FAIL" = 0 ] && [ "$PASS" -gt 0 ]

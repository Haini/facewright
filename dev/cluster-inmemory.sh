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
PASS=0; FAIL=0
php_checks cluster_inmemory.php "$user"
summary cluster-inmemory && [ "$PASS" -gt 0 ]

#!/bin/bash
# Regression for the clustering chain that looped on a live instance: faces pending in OUR table whose row in
# the copy Memories reads carried the id of a person that no longer exists. Clustering matched them every pass, the
# write was rejected, and progress counted matches instead of rows written, so the chain restarted forever.
#
#   dev/chain-loop-test.sh dangling     the live case: the chain must END; the faces are simply assigned (the pass reads
#                                       and guards on ours) and the projection corrects the copy's bad value. Code from
#                                       before "progress means rows written" never ends at all.
#   dev/chain-loop-test.sh assignable   the normal case: pending faces that can be assigned are (they have no row in the
#                                       copy meanwhile: it is sparse), and the chain ends.
#
# The queued jobs are run by hand, one at a time, as cron would. Changes data: restore a snapshot afterwards.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
mode=${1:?usage: chain-loop-test.sh dangling|assignable}; max=${MAX_RUNS:-12}; u=$ADMIN_USER
JOB='OCA\FacewrightBridge\BackgroundJob\ClusterScanJob'

sql "DELETE FROM oc_jobs WHERE class LIKE '%ClusterScanJob'"
P=$(one "SELECT cluster_id FROM oc_facewright_user_faces WHERE user_id='$u' AND state=1 GROUP BY cluster_id ORDER BY COUNT(*) DESC, cluster_id LIMIT 1")
IDS=$(csv "SELECT id FROM oc_facewright_user_faces WHERE user_id='$u' AND cluster_id=$P ORDER BY id LIMIT 5")
need "faces of the largest person" "$(wc -w <<<"$(words "$IDS")")" 5
case $mode in
  dangling)   sql "UPDATE oc_facewright_bridge_face_detections SET cluster_id=999999 WHERE id IN ($IDS)" ;;
  assignable) sql "DELETE FROM oc_facewright_bridge_face_detections WHERE id IN ($IDS)" ;;
  *) echo "mode must be dangling or assignable"; exit 2 ;;
esac
sql "UPDATE oc_facewright_user_faces SET state=0, cluster_id=NULL WHERE id IN ($IDS)"
echo "== fixture ($mode): 5 faces of person $P ($IDS)"
need "faces pending in our table" "$(one "SELECT COUNT(*) FROM oc_facewright_user_faces WHERE state=0 AND id IN ($IDS)")" 5
if [ $mode = dangling ]; then
  need "copy rows pointing at the dead person" "$(one "SELECT COUNT(*) FROM oc_facewright_bridge_face_detections WHERE cluster_id=999999")" 5
  check "   that person does not exist" 0 "$(one "SELECT COUNT(*) FROM oc_facewright_bridge_face_clusters WHERE id=999999")"
fi

echo "== run the chain by hand (at most $max runs)"
occ facewright:cluster-scan | sed 's/ Runs in the background.*//'
runs=0; ended=no
while [ $runs -lt $max ]; do
  line=$(occ background-job:list --class="$JOB" 2>&1 | grep -E '^\| [0-9]' | head -1)
  [ -z "$line" ] && { ended=yes; break; }
  id=$(awk '{print $2}' <<<"$line"); runs=$((runs+1))
  printf '  run %2d: %s\n' $runs "$(grep -o '"userId":"[^"]*","maxDistance":[0-9.]*,"minFaces":[0-9]*,"chunkSize":[0-9]*,"afterId":[0-9]*,"passProgress":[a-z]*' <<<"$line" | sed 's/"maxDistance.*"afterId"/ afterId/')"
  occ background-job:execute "$id" --force-execute >/dev/null 2>&1
done
[ $ended = no ] && [ -z "$(occ background-job:list --class="$JOB" 2>&1 | grep -E '^\| [0-9]')" ] && ended=yes
check "the chain ended within $max runs" yes "$ended"
echo "== outcome"
st() { one "SELECT GROUP_CONCAT(CONCAT(state,'/',IFNULL(cluster_id,'-')) ORDER BY id) FROM oc_facewright_user_faces WHERE id IN ($IDS)"; }
rf() { one "SELECT GROUP_CONCAT(IFNULL(cluster_id,'NULL') ORDER BY id) FROM oc_facewright_bridge_face_detections WHERE id IN ($IDS)"; }
n5() { local s=$1; for _ in 1 2 3 4; do s="$s,$1"; done; echo "$s"; }
if [ $mode = dangling ]; then
  check "faces are assigned to their person in our table" "$(n5 1/$P)" "$(st)"
  check "   and the projection replaced the dead person id in the copy" "$(n5 $P)" "$(rf)"
else
  check "faces are assigned again, to their person" "$(n5 1/$P)" "$(st)"
  check "   in the copy too" "$(n5 $P)" "$(rf)"
fi
verify_step "the chain"
summary "clustering chain ($mode)"

#!/bin/bash
# A clustering pass loads the pool, matches for minutes, then writes. Whatever a human did to those rows meanwhile must
# win: persistAssignments() may write only rows still pending or unclusterable, of this user, into people that still
# exist, and a person the pass created that ended up empty must go. Set the rows up as a human would have left them and
# call it directly. Changes data: restore a snapshot afterwards.   dev/persist-guard.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
u=$ADMIN_USER; o=$BOB_USER
P=($(words "$(csv "SELECT cluster_id FROM oc_facewright_user_faces WHERE user_id='$u' AND state=1 GROUP BY cluster_id HAVING COUNT(*)>=8 ORDER BY cluster_id LIMIT 2")"))
need "people with 8+ faces" "${#P[@]}" 2
target=${P[0]}; human=${P[1]}
F=($(words "$(csv "SELECT id FROM oc_facewright_user_faces WHERE user_id='$u' AND cluster_id=$human ORDER BY id LIMIT 6")"))
need "faces to arrange" "${#F[@]}" 6
foreign=$(one "SELECT id FROM oc_facewright_user_faces WHERE user_id='$o' ORDER BY id LIMIT 1")
need "a face of another user" "$([ -n "$foreign" ] && echo 1 || echo 0)" 1
fstate=$(one "SELECT CONCAT(state,'/',IFNULL(cluster_id,'-')) FROM oc_facewright_user_faces WHERE id=$foreign")
dead=$(( $(one "SELECT MAX(id) FROM oc_facewright_clusters") + 100000 ))
echo "== a human has been at these rows"
sql "UPDATE oc_facewright_user_faces SET state=0, cluster_id=NULL WHERE id=${F[0]};
     UPDATE oc_facewright_user_faces SET state=3, cluster_id=NULL WHERE id=${F[1]};
     UPDATE oc_facewright_user_faces SET state=2, cluster_id=NULL WHERE id=${F[2]};
     UPDATE oc_facewright_user_faces SET state=4, cluster_id=NULL WHERE id=${F[3]};
     UPDATE oc_facewright_user_faces SET state=0, cluster_id=NULL WHERE id=${F[5]};"
docker cp "$DEV_DIR/persist_guard.php" "$NC_CONTAINER:/tmp/persist_guard.php" >/dev/null
out=$(docker exec -u www-data -e GUARD_USER="$u" -e GUARD_OTHER="$o" -e GUARD_IDS="$(IFS=,; echo "${F[*]},$foreign")" \
  -e GUARD_TARGET="$target" -e GUARD_HUMAN_PERSON="$human" -e GUARD_DEAD_PERSON="$dead" "$NC_CONTAINER" php /tmp/persist_guard.php 2>&1 | tail -1)
docker exec "$NC_CONTAINER" rm -f /tmp/persist_guard.php
echo "  $out"
written=$(python3 -c "import json,sys; print(','.join(map(str,json.loads(sys.argv[1])['written'])))" "$out")
created=$(python3 -c "import json,sys; print(json.loads(sys.argv[1])['created'])" "$out")
s() { one "SELECT CONCAT(state,'/',IFNULL(cluster_id,'-')) FROM oc_facewright_user_faces WHERE id=$1"; }
echo "== the pass wrote only what was still open"
check "returned exactly the two open faces"        "${F[0]},${F[1]}" "$written"
check "pending face assigned"                      "1/$target" "$(s "${F[0]}")"
check "unclusterable face assigned"                "1/$target" "$(s "${F[1]}")"
echo "== what the human did stands"
check "unassigned stays unassigned"                "2/-" "$(s "${F[2]}")"
check "ignored stays ignored"                      "4/-" "$(s "${F[3]}")"
check "moved to another person stays there"        "1/$human" "$(s "${F[4]}")"
check "face aimed at a deleted person stays pending" "0/-" "$(s "${F[5]}")"
check "another user's face is untouched"           "$fstate" "$(s "$foreign")"
echo "== people"
check "the person the pass made and left empty is gone" 0 "$(one "SELECT COUNT(*) FROM oc_facewright_clusters WHERE id=$created")"
check "and not in Recognize's table either"        0 "$(one "SELECT COUNT(*) FROM oc_recognize_face_clusters WHERE id=$created")"
echo "== the copy follows"
check "the assigned faces are in Recognize's table with the person" 2 "$(one "SELECT COUNT(*) FROM oc_recognize_face_detections WHERE id IN (${F[0]},${F[1]}) AND cluster_id=$target")"
summary "persistAssignments guard"

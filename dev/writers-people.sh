#!/bin/bash
# Every People-level write, through the real API, with the invariant checker after each step: create, rename,
# move faces into a person and into a new one, detach, ignore, unignore, move or detach one face, merge, delete a
# person, delete the empty ones. Changes data (the last step removes the empty people the fixture has): restore
# a snapshot afterwards.   dev/writers-people.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
u=$ADMIN_USER
state_of() { one "SELECT CONCAT(state,'/',IFNULL(cluster_id,'-')) FROM oc_facewright_user_faces WHERE id=$1"; }
status() { python3 -c 'import sys,json; d=json.load(sys.stdin)["ocs"]; print(d["meta"]["statuscode"])'; }

echo "== data"
P=$(one "SELECT cluster_id FROM oc_facewright_user_faces WHERE user_id='$u' AND state=1 GROUP BY cluster_id HAVING COUNT(*)>=3 ORDER BY cluster_id LIMIT 1")
F=($(words "$(csv "SELECT id FROM oc_facewright_user_faces WHERE user_id='$u' AND cluster_id=$P ORDER BY id LIMIT 3")"))
need "faces of one person" "${#F[@]}" 3
echo "  person $P, faces ${F[*]}"
verify_step "nothing yet"

echo "== create and rename"
A=$(admin_ocs POST /people '{"title":"SC-A"}' | python3 -c 'import sys,json; print(json.load(sys.stdin)["ocs"]["data"]["id"])')
check "create SC-A (it is in our table)" SC-A "$(one "SELECT title FROM oc_facewright_clusters WHERE id=$A")"
verify_step "create"
check "rename SC-A -> SC-A2" 200 "$(admin_ocs PUT "/people/$A" '{"title":"SC-A2"}' | status)"
check "   our copy has the new name" SC-A2 "$(one "SELECT title FROM oc_facewright_clusters WHERE id=$A")"
verify_step "rename"

echo "== faces"
check "move 2 faces into SC-A2" 200 "$(admin_ocs POST /faces/move "{\"ids\":[${F[0]},${F[1]}],\"clusterId\":$A}" | status)"
check "   face 1 is assigned to SC-A2" "1/$A" "$(state_of ${F[0]})"
verify_step "move faces into a person"
check "move 1 face to a new person SC-B" 200 "$(admin_ocs POST /faces/move "{\"ids\":[${F[2]}],\"newTitle\":\"SC-B\"}" | status)"
B=$(one "SELECT id FROM oc_recognize_face_clusters WHERE user_id='$u' AND title='SC-B'"); need "SC-B exists" "${B:+1}" 1
check "   face 3 is in SC-B" "1/$B" "$(state_of ${F[2]})"
verify_step "move faces to a new person"
check "detach face 1" 200 "$(admin_ocs POST /faces/detach "{\"ids\":[${F[0]}]}" | status)"
check "   unassigned (state 2)" "2/-" "$(state_of ${F[0]})"
verify_step "detach"
check "ignore face 2" 200 "$(admin_ocs POST /faces/ignore "{\"ids\":[${F[1]}]}" | status)"
check "   ignored (state 4)" "4/-" "$(state_of ${F[1]})"
verify_step "ignore"
check "unignore face 2" 200 "$(admin_ocs POST /faces/unignore "{\"ids\":[${F[1]}]}" | status)"
check "   pending again (state 0)" "0/-" "$(state_of ${F[1]})"
verify_step "unignore"
check "move one face (PUT) into SC-B" 200 "$(admin_ocs PUT "/faces/${F[0]}/cluster" "{\"clusterId\":$B}" | status)"
check "   in SC-B" "1/$B" "$(state_of ${F[0]})"
verify_step "move one face"
check "detach one face (DELETE)" 200 "$(admin_ocs DELETE "/faces/${F[0]}/cluster" | status)"
check "   unassigned" "2/-" "$(state_of ${F[0]})"
verify_step "detach one face"

echo "== merge and delete"
before=$(one "SELECT COUNT(*) FROM oc_facewright_user_faces WHERE cluster_id=$A")
check "merge SC-B into SC-A2" 200 "$(admin_ocs POST /people/merge "{\"keep\":$A,\"merge\":[$B]}" | status)"
check "   SC-B is gone from our table" 0 "$(one "SELECT COUNT(*) FROM oc_facewright_clusters WHERE id=$B")"
check "   its face moved into SC-A2" "1/$A" "$(state_of ${F[2]})"
verify_step "merge"
check "delete SC-A2" 200 "$(admin_ocs POST /people/delete "{\"ids\":[$A]}" | status)"
check "   its face is unassigned" "2/-" "$(state_of ${F[2]})"
check "   the person is gone" 0 "$(one "SELECT COUNT(*) FROM oc_facewright_clusters WHERE id=$A")"
verify_step "delete person"

echo "== empty people"
admin_ocs POST /people '{"title":"SC-Empty"}' >/dev/null
dry=$(occ facewright:people "$u" --delete-empty --dry-run | grep -o '[0-9]\+' | head -1); need "empty people to delete (SC-Empty at least)" "${dry:-0}" 1
verify_step "an empty person exists"
check "delete-empty removes them" 0 "$(occ facewright:people "$u" --delete-empty >/dev/null; one "SELECT COUNT(*) FROM oc_recognize_face_clusters c WHERE c.user_id='$u' AND NOT EXISTS (SELECT 1 FROM oc_recognize_face_detections d WHERE d.cluster_id=c.id)")"
verify_step "delete empty people"

echo "== put the faces back"
check "move the three faces back to person $P" 200 "$(admin_ocs POST /faces/move "{\"ids\":[${F[0]},${F[1]},${F[2]}],\"clusterId\":$P}" | status)"
verify_step "restore"
summary "People writers"

#!/bin/bash
# Commands from before Stage E must not be able to harm tables that are now the source of truth, and a copy to a user
# who already has the rows must be harmless. Changes data: restore a snapshot afterwards.   dev/own-traps.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
u=$ADMIN_USER
echo "== --migrate must not copy Recognize's table over ours"
pid=$(one "SELECT cluster_id FROM oc_facewright_user_faces WHERE user_id='$u' AND state=1 GROUP BY cluster_id ORDER BY COUNT(*) DESC LIMIT 1")
fid=$(one "SELECT id FROM oc_facewright_user_faces WHERE cluster_id=$pid ORDER BY id LIMIT 1")
need "a face to protect" "$([ -n "$fid" ] && echo 1 || echo 0)" 1
before=$(one "SELECT CONCAT(state,'/',cluster_id) FROM oc_facewright_user_faces WHERE id=$fid")
sql "UPDATE oc_recognize_face_detections SET cluster_id=NULL WHERE id=$fid"     # what Recognize's own reset does to every row
occ config:app:set facewright_bridge processing_paused --value=true >/dev/null
occ facewright:own-tables --migrate --chunk=100000 >/dev/null 2>&1; rc=$?
check "--migrate refuses while our table holds data" 1 "$rc"
check "   and our row is what it was" "$before" "$(one "SELECT CONCAT(state,'/',IFNULL(cluster_id,'-')) FROM oc_facewright_user_faces WHERE id=$fid")"
occ config:app:delete facewright_bridge processing_paused >/dev/null
occ facewright:project >/dev/null
echo "== --migrate still fills empty tables (the fresh install case, and what snapshot.sh relies on)"
check "after a restore the tables are full" 1 "$(one "SELECT COUNT(*)>0 FROM oc_facewright_user_faces")"
echo "== migrate-faces must not adopt Recognize's copies"
sql "INSERT INTO oc_recognize_face_detections (id,user_id,file_id,x,y,height,width,face_vector,threshold) SELECT 99000011,user_id,file_id,x,y,height,width,CONCAT('[',REPEAT('0.123456,',600),'0]'),0 FROM oc_recognize_face_detections WHERE user_id='$u' ORDER BY id LIMIT 1"
need "a stale copy of Recognize's with a long vector" "$(one "SELECT COUNT(*) FROM oc_recognize_face_detections WHERE id=99000011")" 1
links=$(one "SELECT COUNT(*) FROM oc_facewright_detection_faces")
occ facewright:migrate-faces >/dev/null 2>&1; rc=$?
check "migrate-faces refuses since Stage E" 1 "$rc"
check "   and adds no link" "$links" "$(one "SELECT COUNT(*) FROM oc_facewright_detection_faces")"
sql "DELETE FROM oc_recognize_face_detections WHERE id=99000011"
echo "== a copy to someone who already has the rows"
docker cp "$DEV_DIR/own_traps.php" "$NC_CONTAINER:/tmp/own_traps.php" >/dev/null
out=$(docker exec -u www-data -e U1="$u" -e U2="$BOB_USER" "$NC_CONTAINER" php /tmp/own_traps.php 2>&1 | tail -1); docker exec "$NC_CONTAINER" rm -f /tmp/own_traps.php
echo "  $out"
f() { python3 -c "import json,sys; print(json.loads(sys.argv[1]).get(sys.argv[2]))" "$out" "$1" 2>/dev/null; }
check "the first copy wrote rows" True "$(python3 -c "import json,sys; print(json.loads(sys.argv[1])['first']>0)" "$out" 2>/dev/null)"
check "the second copy writes none and does not throw" 0 "$(f second)"
check "the marker is back after it (the crash this heals)" 1 "$(f marked)"
check "no duplicate rows" 0 "$(f dupes)"
echo "== FaceStore refuses a person on a state that has none"
check "setState(UNASSIGNED, person) throws" 1 "$(f refused)"
verify_step "after all of it"
summary "own-tables traps"

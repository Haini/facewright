#!/bin/bash
# The DAV router, the way Memories and Photos use it: create, rename, delete a person; remove and move a face;
# what must be refused; what must not be touched. Every request is followed by a look at the database.
# Leaves the data as it found it (the test person is deleted and the moved face returned).
#   dev/battery.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
trap api_key_on EXIT
u=$ADMIN_USER
urlenc() { python3 -c 'import sys,urllib.parse; print(urllib.parse.quote(sys.argv[1]))' "$1"; }
dest() { echo "$DAV_BASE/recognize/$u/faces/$(urlenc "$1")"; }

echo "== data"
titled() { csv "SELECT c.id FROM oc_recognize_face_clusters c JOIN oc_facewright_user_faces x ON x.cluster_id=c.id AND x.state=1 JOIN oc_facewright_faces f ON f.id=x.face_id JOIN oc_filecache fc ON fc.fileid=f.file_id WHERE c.user_id='$u' AND c.title<>'' GROUP BY c.id HAVING COUNT(*)>=3 ORDER BY c.id LIMIT 2"; }
T=($(words "$(titled)")); need "titled people with 3+ faces whose photo exists" "${#T[@]}" 2
A=${T[0]}; B=${T[1]}; TITLE_A=$(one "SELECT title FROM oc_recognize_face_clusters WHERE id=$A"); TITLE_B=$(one "SELECT title FROM oc_recognize_face_clusters WHERE id=$B")
IFS=$'\t' read -r FID FNAME <<<"$(sql "SELECT x.id, REGEXP_REPLACE(fc.path,'^.*/','') FROM oc_facewright_user_faces x JOIN oc_facewright_faces f ON f.id=x.face_id JOIN oc_filecache fc ON fc.fileid=f.file_id WHERE x.cluster_id=$A AND x.state=1 ORDER BY x.id LIMIT 1")"
echo "  person A=$A [$TITLE_A], person B=$B [$TITLE_B], face $FID ($FNAME)"
FACE_URL="$(urlenc "$FID-$FNAME")"; A_URL=$(urlenc "$TITLE_A")
cluster_of() { one "SELECT IFNULL(cluster_id,'NULL') FROM oc_recognize_face_detections WHERE id=$1"; }
count_people() { one "SELECT COUNT(*) FROM oc_recognize_face_clusters WHERE user_id='$u' AND title='$1'"; }
check "face is in A to begin with" "$A" "$(cluster_of $FID)"
api_key_off

echo "== create"
check "MKCOL create RouterTest" 201 "$(admin_dav MKCOL "recognize/$u/faces/RouterTest")"
RT=$(one "SELECT id FROM oc_recognize_face_clusters WHERE user_id='$u' AND title='RouterTest'"); need "the new person exists" "${RT:+1}" 1
check "MKCOL duplicate name" 403 "$(admin_dav MKCOL "recognize/$u/faces/RouterTest")"
check "   still one person of that name" 1 "$(count_people RouterTest)"
check "MKCOL numeric name" 403 "$(admin_dav MKCOL "recognize/$u/faces/12345")"
check "MKCOL in a deeper path" 403 "$(admin_dav MKCOL "recognize/$u/faces/a/b")"
echo "== rename"
check "MOVE rename RouterTest -> RouterRenamed" 201 "$(admin_dav MOVE "recognize/$u/faces/RouterTest" -H "Destination: $(dest RouterRenamed)")"
check "   title in database" RouterRenamed "$(one "SELECT title FROM oc_recognize_face_clusters WHERE id=$RT")"
check "MOVE rename onto existing [$TITLE_B]" 403 "$(admin_dav MOVE "recognize/$u/faces/RouterRenamed" -H "Destination: $(dest "$TITLE_B")")"
check "   unchanged" RouterRenamed "$(one "SELECT title FROM oc_recognize_face_clusters WHERE id=$RT")"
check "MOVE rename unknown person" 404 "$(admin_dav MOVE "recognize/$u/faces/NoSuchPerson" -H "Destination: $(dest Whatever)")"
check "MOVE rename by numeric id" 201 "$(admin_dav MOVE "recognize/$u/faces/$RT" -H "Destination: $(dest RouterByID)")"
check "MOVE rename back" 201 "$(admin_dav MOVE "recognize/$u/faces/RouterByID" -H "Destination: $(dest RouterRenamed)")"
echo "== faces"
check "MOVE face A -> RouterRenamed" 201 "$(admin_dav MOVE "recognize/$u/faces/$A_URL/$FACE_URL" -H "Destination: $(dest RouterRenamed)/$FACE_URL")"
check "   cluster_id in database" "$RT" "$(cluster_of $FID)"
check "MOVE face, wrong source person" 404 "$(admin_dav MOVE "recognize/$u/faces/$A_URL/$FACE_URL" -H "Destination: $(dest RouterRenamed)/$FACE_URL")"
check "MOVE face to unknown person" 404 "$(admin_dav MOVE "recognize/$u/faces/RouterRenamed/$FACE_URL" -H "Destination: $(dest Nobody)/$FACE_URL")"
check "DELETE face (remove from person)" 204 "$(admin_dav DELETE "recognize/$u/faces/RouterRenamed/$FACE_URL")"
check "   now unassigned (-1)" -1 "$(cluster_of $FID)"
check "DELETE the face again (not in the person)" 404 "$(admin_dav DELETE "recognize/$u/faces/RouterRenamed/$FACE_URL")"
check "MOVE face from the unassigned list" 201 "$(admin_dav MOVE "recognize/$u/unassigned-faces/$FACE_URL" -H "Destination: $(dest RouterRenamed)/$FACE_URL")"
check "   in RouterRenamed again" "$RT" "$(cluster_of $FID)"
check "DELETE an unassigned face" 403 "$(admin_dav DELETE "recognize/$u/unassigned-faces/$FACE_URL")"
echo "== delete person"
check "DELETE person RouterRenamed" 204 "$(admin_dav DELETE "recognize/$u/faces/RouterRenamed")"
check "   person gone" 0 "$(count_people RouterRenamed)"
check "   its face is unassigned (-1)" -1 "$(cluster_of $FID)"
check "DELETE unknown person" 404 "$(admin_dav DELETE "recognize/$u/faces/RouterRenamed")"
echo "== refused, and who may do what"
check "other user's tree (MKCOL in $BOB_USER's)" 403 "$(admin_dav MKCOL "recognize/$BOB_USER/faces/Intruder")"
check "   nothing created" 0 "$(one "SELECT COUNT(*) FROM oc_recognize_face_clusters WHERE title='Intruder'")"
check "MOVE into $BOB_USER's tree" 403 "$(admin_dav MOVE "recognize/$u/faces/$A_URL" -H "Destination: $DAV_BASE/recognize/$BOB_USER/faces/Stolen")"
check "MOVE with a destination outside the tree" 403 "$(admin_dav MOVE "recognize/$u/faces/$A_URL" -H "Destination: $DAV_BASE/files/$u/x")"
check "MOVE without a Destination" 403 "$(admin_dav MOVE "recognize/$u/faces/$A_URL")"
check "COPY a person" 403 "$(admin_dav COPY "recognize/$u/faces/$A_URL" -H "Destination: $(dest Copy)")"
check "PUT into the tree" 403 "$(admin_dav PUT "recognize/$u/faces/x" --data x)"
check "$BOB_USER writes in $u's tree (DELETE a person)" 403 "$(bob_dav DELETE "recognize/$u/faces/$A_URL")"
check "   person A still there" 1 "$(one "SELECT COUNT(*) FROM oc_recognize_face_clusters WHERE id=$A")"
check "$BOB_USER creates a person in his own tree" 201 "$(bob_dav MKCOL "recognize/$BOB_USER/faces/BobBatteryPerson")"
check "$BOB_USER deletes it" 204 "$(bob_dav DELETE "recognize/$BOB_USER/faces/BobBatteryPerson")"
check "unauthenticated MKCOL" 401 "$(docker exec "$NC_CONTAINER" curl -s -o /dev/null -w '%{http_code}' -X MKCOL "$DAV_BASE/recognize/$u/faces/Anon")"
check "Files DAV unaffected (PUT)" 201 "$(admin_dav PUT "files/$u/battery-test.txt" --data hi)"
check "Files DAV unaffected (DELETE)" 204 "$(admin_dav DELETE "files/$u/battery-test.txt")"
echo "== leave the data as found"
check "move the face back to A" 201 "$(admin_dav MOVE "recognize/$u/unassigned-faces/$FACE_URL" -H "Destination: $(dest "$TITLE_A")/$FACE_URL")"
check "   face is in A again" "$A" "$(cluster_of $FID)"
verify_step "the battery"
summary "DAV battery"

#!/bin/bash
# The DAV router, the way Memories and Photos use it: create, rename, delete a person; remove and move a face;
# what must be refused; what must not be touched. Every request is followed by a look at the database.
# Leaves the data as it found it (the test person is deleted and the moved face returned).
#   dev/battery.sh                          the tree Recognize provides, `recognize/`
#   TREE=facewright_bridge dev/battery.sh   the bridge's own tree (docs/memories-fork.md, M7): the same requests, the same
#                                           answers; the writes are the same router, the reads are the bridge's nodes
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
trap api_key_on EXIT
u=$ADMIN_USER
TREE=${TREE:-recognize}
case "$TREE" in recognize) OTHER=facewright_bridge ;; facewright_bridge) OTHER=recognize ;; *) echo "TREE must be recognize or facewright_bridge"; exit 2 ;; esac
echo "tree under test: $TREE"
urlenc() { python3 -c 'import sys,urllib.parse; print(urllib.parse.quote(sys.argv[1]))' "$1"; }
dest() { echo "$DAV_BASE/$TREE/$u/faces/$(urlenc "$1")"; }

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
check "MKCOL create RouterTest" 201 "$(admin_dav MKCOL "$TREE/$u/faces/RouterTest")"
RT=$(one "SELECT id FROM oc_recognize_face_clusters WHERE user_id='$u' AND title='RouterTest'"); need "the new person exists" "${RT:+1}" 1
check "MKCOL duplicate name" 403 "$(admin_dav MKCOL "$TREE/$u/faces/RouterTest")"
check "   still one person of that name" 1 "$(count_people RouterTest)"
check "MKCOL numeric name" 403 "$(admin_dav MKCOL "$TREE/$u/faces/12345")"
check "MKCOL in a deeper path" 403 "$(admin_dav MKCOL "$TREE/$u/faces/a/b")"
echo "== rename"
check "MOVE rename RouterTest -> RouterRenamed" 201 "$(admin_dav MOVE "$TREE/$u/faces/RouterTest" -H "Destination: $(dest RouterRenamed)")"
check "   title in database" RouterRenamed "$(one "SELECT title FROM oc_recognize_face_clusters WHERE id=$RT")"
check "MOVE rename onto existing [$TITLE_B]" 403 "$(admin_dav MOVE "$TREE/$u/faces/RouterRenamed" -H "Destination: $(dest "$TITLE_B")")"
check "   unchanged" RouterRenamed "$(one "SELECT title FROM oc_recognize_face_clusters WHERE id=$RT")"
check "MOVE rename unknown person" 404 "$(admin_dav MOVE "$TREE/$u/faces/NoSuchPerson" -H "Destination: $(dest Whatever)")"
check "MOVE rename by numeric id" 201 "$(admin_dav MOVE "$TREE/$u/faces/$RT" -H "Destination: $(dest RouterByID)")"
check "MOVE rename back" 201 "$(admin_dav MOVE "$TREE/$u/faces/RouterByID" -H "Destination: $(dest RouterRenamed)")"
echo "== faces"
check "MOVE face A -> RouterRenamed" 201 "$(admin_dav MOVE "$TREE/$u/faces/$A_URL/$FACE_URL" -H "Destination: $(dest RouterRenamed)/$FACE_URL")"
check "   cluster_id in database" "$RT" "$(cluster_of $FID)"
check "MOVE face, wrong source person" 404 "$(admin_dav MOVE "$TREE/$u/faces/$A_URL/$FACE_URL" -H "Destination: $(dest RouterRenamed)/$FACE_URL")"
check "MOVE face to unknown person" 404 "$(admin_dav MOVE "$TREE/$u/faces/RouterRenamed/$FACE_URL" -H "Destination: $(dest Nobody)/$FACE_URL")"
check "DELETE face (remove from person)" 204 "$(admin_dav DELETE "$TREE/$u/faces/RouterRenamed/$FACE_URL")"
check "   now unassigned (-1)" -1 "$(cluster_of $FID)"
check "DELETE the face again (not in the person)" 404 "$(admin_dav DELETE "$TREE/$u/faces/RouterRenamed/$FACE_URL")"
check "MOVE face from the unassigned list" 201 "$(admin_dav MOVE "$TREE/$u/unassigned-faces/$FACE_URL" -H "Destination: $(dest RouterRenamed)/$FACE_URL")"
check "   in RouterRenamed again" "$RT" "$(cluster_of $FID)"
check "DELETE an unassigned face" 403 "$(admin_dav DELETE "$TREE/$u/unassigned-faces/$FACE_URL")"
echo "== delete person"
check "DELETE person RouterRenamed" 204 "$(admin_dav DELETE "$TREE/$u/faces/RouterRenamed")"
check "   person gone" 0 "$(count_people RouterRenamed)"
check "   its face is unassigned (-1)" -1 "$(cluster_of $FID)"
check "DELETE unknown person" 404 "$(admin_dav DELETE "$TREE/$u/faces/RouterRenamed")"
echo "== refused, and who may do what"
check "other user's tree (MKCOL in $BOB_USER's)" 403 "$(admin_dav MKCOL "$TREE/$BOB_USER/faces/Intruder")"
check "   nothing created" 0 "$(one "SELECT COUNT(*) FROM oc_recognize_face_clusters WHERE title='Intruder'")"
check "MOVE into $BOB_USER's tree" 403 "$(admin_dav MOVE "$TREE/$u/faces/$A_URL" -H "Destination: $DAV_BASE/$TREE/$BOB_USER/faces/Stolen")"
check "MOVE with a destination outside the tree" 403 "$(admin_dav MOVE "$TREE/$u/faces/$A_URL" -H "Destination: $DAV_BASE/files/$u/x")"
check "MOVE without a Destination" 403 "$(admin_dav MOVE "$TREE/$u/faces/$A_URL")"
check "COPY a person" 403 "$(admin_dav COPY "$TREE/$u/faces/$A_URL" -H "Destination: $(dest Copy)")"
check "PUT into the tree" 403 "$(admin_dav PUT "$TREE/$u/faces/x" --data x)"
check "$BOB_USER writes in $u's tree (DELETE a person)" 403 "$(bob_dav DELETE "$TREE/$u/faces/$A_URL")"
check "   person A still there" 1 "$(one "SELECT COUNT(*) FROM oc_recognize_face_clusters WHERE id=$A")"
check "$BOB_USER creates a person in his own tree" 201 "$(bob_dav MKCOL "$TREE/$BOB_USER/faces/BobBatteryPerson")"
check "$BOB_USER deletes it" 204 "$(bob_dav DELETE "$TREE/$BOB_USER/faces/BobBatteryPerson")"
check "unauthenticated MKCOL" 401 "$(docker exec "$NC_CONTAINER" curl -s -o /dev/null -w '%{http_code}' -X MKCOL "$DAV_BASE/$TREE/$u/faces/Anon")"
check "Files DAV unaffected (PUT)" 201 "$(admin_dav PUT "files/$u/battery-test.txt" --data hi)"
check "Files DAV unaffected (DELETE)" 204 "$(admin_dav DELETE "files/$u/battery-test.txt")"
# CROSS_TREE=no skips this section: dev/rollback-drill.sh runs the battery against Stage D's router, which predates the rule.
if [ "${CROSS_TREE:-yes}" = yes ]; then
echo "== refused: nothing moves between the two trees"
check "MOVE a person into the other tree" 403 "$(admin_dav MOVE "$TREE/$u/faces/$A_URL" -H "Destination: $DAV_BASE/$OTHER/$u/faces/Crossed")"
check "   the person keeps its name" 1 "$(count_people "$TITLE_A")"
check "   and nothing of that name was made" 0 "$(count_people Crossed)"
check "MOVE a face into the other tree" 403 "$(admin_dav MOVE "$TREE/$u/unassigned-faces/$FACE_URL" -H "Destination: $DAV_BASE/$OTHER/$u/faces/$A_URL/$FACE_URL")"
fi

echo "== leave the data as found"
check "move the face back to A" 201 "$(admin_dav MOVE "$TREE/$u/unassigned-faces/$FACE_URL" -H "Destination: $(dest "$TITLE_A")/$FACE_URL")"
check "   face is in A again" "$A" "$(cluster_of $FID)"

echo "== reads (what Memories asks for when it merges people)"
dav_names() {  # dav_names USER PASS URL: the children PROPFIND depth 1 lists, decoded, one per line (the folder itself left out)
  docker exec "$NC_CONTAINER" curl -s -u "$1:$2" -X PROPFIND -H "Depth: 1" "$DAV_BASE/$3" | python3 -c '
import sys, urllib.parse, xml.etree.ElementTree as ET
hrefs = [urllib.parse.unquote(h.text).rstrip("/") for h in ET.fromstring(sys.stdin.read()).iter("{DAV:}href")]
for h in hrefs[1:]: print(h.rsplit("/", 1)[-1])'
}
dav_sha() { docker exec "$NC_CONTAINER" sh -c "curl -s -u '$1:$2' '$DAV_BASE/$3' | sha256sum | cut -d' ' -f1"; }
# The faces of A whose photo the user can read: assigned to A, and a row in the file cache. (All of dev's photos are in
# the admin's storage or shared into it.)
WANT=$(csv "SELECT x.id FROM oc_facewright_user_faces x JOIN oc_facewright_faces f ON f.id=x.face_id JOIN oc_filecache fc ON fc.fileid=f.file_id WHERE x.user_id='$u' AND x.state=1 AND x.cluster_id=$A ORDER BY x.id")
need "faces of A with a photo" "$(wc -w <<<"$(words "$WANT")")" 3
NAMES=$(dav_names "$u" "$ADMIN_PASS" "$TREE/$u/faces/$A_URL/")
check "PROPFIND depth 1 on a person lists exactly its faces (by id)" "$WANT" "$(sed 's/-.*//' <<<"$NAMES" | sort -n | paste -sd, -)"
check "   every name is {id}-{file name}" 0 "$(grep -vcE '^[0-9]+-.' <<<"$NAMES")"
check "   including the face we moved there and back" 1 "$(grep -cxF -- "$FID-$FNAME" <<<"$NAMES")"
check "PROPFIND depth 1 on the faces folder lists the person" 1 "$(dav_names "$u" "$ADMIN_PASS" "$TREE/$u/faces/" | grep -cxF -- "$TITLE_A")"
check "PROPFIND on a person that does not exist" 404 "$(admin_dav PROPFIND "$TREE/$u/faces/NoSuchPerson/" -H "Depth: 1")"
if [ "$TREE" = facewright_bridge ]; then
  check "PROPFIND depth 1 on unassigned-faces is not built" 405 "$(admin_dav PROPFIND "$TREE/$u/unassigned-faces/" -H "Depth: 1")"
fi
check "PROPFIND depth 0 on unassigned-faces" 207 "$(admin_dav PROPFIND "$TREE/$u/unassigned-faces/" -H "Depth: 0")"
check "PROPFIND on $BOB_USER's tree as $u" 403 "$(admin_dav PROPFIND "$TREE/$BOB_USER/" -H "Depth: 1")"
check "PROPFIND unauthenticated" 401 "$(docker exec "$NC_CONTAINER" curl -s -o /dev/null -w '%{http_code}' -X PROPFIND -H "Depth: 1" "$DAV_BASE/$TREE/$u/faces/")"
FPATH=$(one "SELECT fc.path FROM oc_facewright_user_faces x JOIN oc_facewright_faces f ON f.id=x.face_id JOIN oc_filecache fc ON fc.fileid=f.file_id WHERE x.id=$FID")
need "the moved face's file path" "${FPATH:+1}" 1
check "GET a face file: the bytes of the photo (sha256 against the Files tree)" "$(dav_sha "$u" "$ADMIN_PASS" "files/$u/$(urlenc "${FPATH#files/}")")" "$(dav_sha "$u" "$ADMIN_PASS" "$TREE/$u/faces/$A_URL/$FACE_URL")"
check "GET a face of another person's folder" 404 "$(admin_dav GET "$TREE/$u/faces/$(urlenc "$TITLE_B")/$FACE_URL")"
verify_step "the battery"
summary "DAV battery"

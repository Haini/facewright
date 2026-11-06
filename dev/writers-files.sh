#!/bin/bash
# The writers that react to files: detection of a new photo with a real face, copying its rows to another user
# (called directly, and through a real share), pruning one user's rows, and deleting the file. Each step compares
# Recognize's table with ours for that file and runs the invariant checker. Since Stage E a new face is PENDING,
# which is hidden from Memories, so it is NOT in Recognize's table until a person is given to it; the suite assigns
# the face to a person to follow the visible path (appears there, then leaves on prune and on file delete). Needs the test photo
# (dev/make-portrait.py) and a healthy ExApp. Cleans up after itself; changes data only by the rows it creates
# and removes.   dev/writers-files.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
u=$ADMIN_USER; ensure_portrait
ts=$(date +%s); name="fwdev_$ts.jpg"; folder="fwdev_share_$ts"
rows_rfd()   { one "SELECT COUNT(*) FROM oc_recognize_face_detections WHERE file_id=$FID${1:+ AND user_id='$1'}"; }
rows_store() { one "SELECT COUNT(*) FROM oc_facewright_user_faces x JOIN oc_facewright_faces f ON f.id=x.face_id WHERE f.file_id=$FID${1:+ AND x.user_id='$1'}"; }
rows_links() { one "SELECT COUNT(*) FROM oc_facewright_detection_faces l JOIN oc_facewright_faces f ON f.id=l.face_id WHERE f.file_id=$FID"; }
php() { docker exec -u www-data "$NC_CONTAINER" php -r 'define("OC_CONSOLE",1); require_once "/var/www/html/lib/base.php"; $s=\OCP\Server::get(\OCA\FacewrightBridge\Service\AccessSyncService::class); '"$1" "${@:2}"; }
cleanup() { admin_dav DELETE "files/$u/$name" >/dev/null 2>&1; admin_dav DELETE "files/$u/$folder" >/dev/null 2>&1; }
trap cleanup EXIT

echo "== detection of a new photo"
check "upload the photo" 201 "$(admin_dav PUT "files/$u/$name" -T /tmp/portrait.jpg)"
FID=$(one "SELECT fileid FROM oc_filecache WHERE path='files/$name' ORDER BY fileid DESC LIMIT 1"); need "the file is known to Nextcloud" "${FID:+1}" 1
found=$(occ facewright:detect "$u" "$name" | grep -o 'inserted [0-9]*' | grep -o '[0-9]*'); need "rows inserted by detection (the detector must find the face)" "${found:-0}" 1
check "a new face is ours, pending, and linked" "1/1/0" "$(rows_store $u)/$(rows_links)/$(one "SELECT state FROM oc_facewright_user_faces x JOIN oc_facewright_faces f ON f.id=x.face_id WHERE f.file_id=$FID AND x.user_id='$u'")"
check "   and hidden: not in Recognize's table" 0 "$(rows_rfd $u)"
verify_step "detect"
ROW=$(one "SELECT x.id FROM oc_facewright_user_faces x JOIN oc_facewright_faces f ON f.id=x.face_id WHERE f.file_id=$FID AND x.user_id='$u'")
PERSON=$(one "SELECT cluster_id FROM oc_facewright_user_faces WHERE user_id='$u' AND state=1 GROUP BY cluster_id ORDER BY cluster_id LIMIT 1"); need "a person to give the face to" "${PERSON:+1}" 1
check "give the face a person" 200 "$(admin_ocs POST /faces/move "{\"ids\":[$ROW],\"clusterId\":$PERSON}" | python3 -c 'import sys,json; print(json.load(sys.stdin)["ocs"]["meta"]["statuscode"])')"
check "   it now appears in Recognize's table, in that person" "1/$PERSON" "$(rows_rfd $u)/$(one "SELECT cluster_id FROM oc_recognize_face_detections WHERE id=$ROW")"
verify_step "assign"

echo "== copy to another user, called directly"
copied=$(php 'echo $s->copyToUsers((int)$argv[1], $argv[2], [$argv[3]]);' "$FID" "$u" "$BOB_USER")
check "rows written for $BOB_USER" "$(rows_store $u)" "$copied"
check "   it is pending for $BOB_USER, so hidden: ours 1, Recognize's 0" "1/0" "$(rows_store $BOB_USER)/$(rows_rfd $BOB_USER)"
verify_step "copyToUsers"
echo "== prune one user"
php 'echo $s->pruneExceptUsers((int)$argv[1], [$argv[2]]);' "$FID" "$u" >/dev/null
check "$BOB_USER has no rows left (both tables)" "0/0" "$(rows_rfd $BOB_USER)/$(rows_store $BOB_USER)"
check "   $u keeps his, in both tables" "1/1" "$(rows_store $u)/$(rows_rfd $u)"
need "rows kept for $u" "$(rows_store $u)" 1
verify_step "pruneExceptUsers"

echo "== a real share"
check "create a folder" 201 "$(admin_dav MKCOL "files/$u/$folder")"
check "move the photo into it" 201 "$(admin_dav MOVE "files/$u/$name" -H "Destination: $DAV_BASE/files/$u/$folder/$name")"
SH=$(docker exec "$NC_CONTAINER" curl -s -u "$ADMIN_USER:$ADMIN_PASS" -H 'OCS-APIRequest: true' -X POST "http://localhost/ocs/v2.php/apps/files_sharing/api/v1/shares?format=json" -d "path=/$folder" -d shareType=0 -d "shareWith=$BOB_USER" -d permissions=31 | python3 -c 'import sys,json; print(json.load(sys.stdin)["ocs"]["data"]["id"])')
need "the share was created" "${SH:+1}" 1
check "$BOB_USER registers his mounts" 207 "$(bob_dav PROPFIND "files/$BOB_USER/" -H 'Depth: 1')"
occ facewright:backfill-access --user="$BOB_USER" | grep -E 'Copied'
need "rows $BOB_USER received through the share" "$(rows_store $BOB_USER)" 1
check "   pending for $BOB_USER: ours 1, Recognize's 0" "1/0" "$(rows_store $BOB_USER)/$(rows_rfd $BOB_USER)"
verify_step "share (backfill-access)"
docker exec "$NC_CONTAINER" curl -s -o /dev/null -u "$ADMIN_USER:$ADMIN_PASS" -H 'OCS-APIRequest: true' -X DELETE "http://localhost/ocs/v2.php/apps/files_sharing/api/v1/shares/$SH?format=json"

echo "== deleting the file"
check "DELETE the photo" 204 "$(admin_dav DELETE "files/$u/$folder/$name")"
check "no rows left anywhere (Recognize's table, ours, links)" "0/0/0" "$(rows_rfd)/$(rows_store)/$(rows_links)"
verify_step "file delete"
summary "file writers"

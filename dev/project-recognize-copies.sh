#!/bin/bash
# Recognize's own access-sync handler copies a file's rows to every user with access who has none, using its own id
# counter and the source row's vector. Since pending faces are no longer in its table, more users look like they
# have none, so it writes rows of its own that have no row of ours. Reconcile (category C) must remove exactly those
# and nothing else. The handler is driven through Recognize's own mapper and job; only the trigger (a mount-added
# event) is replaced by the mapper call it ends in.
# The two id spaces also MEET: pushing an explicit id moves Recognize's counter past it, so its next row lands exactly
# where our next id will. A visible face overwrites a row it finds there; on a hidden one the row is a squatter and
# reconcile removes it. Both are planted at our next id and checked.
# Changes data: restore a snapshot afterwards.   dev/project-recognize-copies.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
u=$ADMIN_USER; ensure_portrait
ts=$(date +%s); d="fwshare_$ts"
status() { python3 -c 'import sys,json; print(json.load(sys.stdin)["ocs"]["meta"]["statuscode"])'; }
trap 'admin_dav DELETE "files/$u/$d" >/dev/null 2>&1' EXIT
rfd_max() { one "SELECT COALESCE(MAX(id),0) FROM oc_recognize_face_detections"; }

echo "== a visible face in a folder, shared"
check "create the folder" 201 "$(admin_dav MKCOL "files/$u/$d")"
admin_dav PUT "files/$u/$d/p1.jpg" -T /tmp/portrait.jpg >/dev/null; occ facewright:detect "$u" "$d/p1.jpg" >/dev/null
FILE=$(one "SELECT fileid FROM oc_filecache WHERE path='files/$d/p1.jpg'"); ROOT=$(one "SELECT fileid FROM oc_filecache WHERE path='files/$d'"); STOR=$(one "SELECT storage FROM oc_filecache WHERE path='files/$d'")
ROW=$(one "SELECT x.id FROM oc_facewright_user_faces x JOIN oc_facewright_faces f ON f.id=x.face_id WHERE f.file_id=$FILE")
PERSON=$(one "SELECT cluster_id FROM oc_facewright_user_faces WHERE user_id='$u' AND state=1 GROUP BY cluster_id ORDER BY cluster_id LIMIT 1")
check "give the face a person" 200 "$(admin_ocs POST /faces/move "{\"ids\":[$ROW],\"clusterId\":$PERSON}" | status)"
SH=$(docker exec "$NC_CONTAINER" curl -s -u "$u:$ADMIN_PASS" -H 'OCS-APIRequest: true' -X POST "http://localhost/ocs/v2.php/apps/files_sharing/api/v1/shares?format=json" -d "path=/$d" -d shareType=0 -d "shareWith=$BOB_USER" -d permissions=31 | python3 -c 'import sys,json; print(json.load(sys.stdin)["ocs"]["data"]["id"])')
need "the share exists" "${SH:+1}" 1
bob_dav PROPFIND "files/$BOB_USER/" -H 'Depth: 1' >/dev/null
LEG=$(one "SELECT d.id FROM oc_recognize_face_detections d LEFT JOIN oc_facewright_user_faces x ON x.id=d.id WHERE x.id IS NULL AND CHAR_LENGTH(d.face_vector) BETWEEN 3 AND 3500 ORDER BY d.id LIMIT 1")
need "a legacy row to leave alone" "${LEG:+1}" 1; H0=$(one "SELECT MD5(CONCAT_WS('|',id,user_id,file_id,x,y,height,width,face_vector,IFNULL(cluster_id,'N'),threshold)) FROM oc_recognize_face_detections WHERE id=$LEG")
occ facewright:project --dry-run >/dev/null 2>&1; check "everything agrees before Recognize's handler runs" 0 "$?"

echo "== Recognize's access-sync handler runs"
docker exec -u www-data "$NC_CONTAINER" php -r 'define("OC_CONSOLE",1); require_once "/var/www/html/lib/base.php"; \OCP\Server::get(\OCA\Recognize\Db\FsActionMapper::class)->insertAccessUpdate((int)$argv[1], (int)$argv[2]);' "$STOR" "$ROOT"
JOB=$(one "SELECT id FROM oc_jobs WHERE class LIKE '%ProcessFsActionsJob' AND argument LIKE '%FsAccessUpdate%' ORDER BY id DESC LIMIT 1"); need "Recognize's access-update job" "${JOB:+1}" 1
occ background-job:execute "$JOB" --force-execute >/dev/null 2>&1
COPY=$(one "SELECT id FROM oc_recognize_face_detections WHERE file_id=$FILE AND user_id='$BOB_USER'"); need "the copy Recognize wrote for $BOB_USER" "${COPY:+1}" 1
check "   it is a row of Recognize's, not ours" 0 "$(one "SELECT COUNT(*) FROM oc_facewright_user_faces WHERE id=$COPY")"
check "   with no person (NULL), the kind that arms Recognize's own clustering" "NULL" "$(one "SELECT IFNULL(cluster_id,'NULL') FROM oc_recognize_face_detections WHERE id=$COPY")"
occ facewright:project --dry-run >/dev/null 2>&1; check "the dry run sees it (exit 1)" 1 "$?"
check "   as one stale copy (C)" 1 "$(occ facewright:project --dry-run 2>/dev/null | grep '^C ' | grep -oE '[0-9]+$')"
check "   and nothing else" "0/0/0" "$(occ facewright:project --dry-run 2>/dev/null | grep -E '^A |^D ' | grep -oE '[0-9]+$' | paste -sd/ - | sed 's#^0/0/0$#0/0/0#')"

echo "== reconcile removes that row and only that row"
occ facewright:project >/dev/null
check "the copy is gone" 0 "$(one "SELECT COUNT(*) FROM oc_recognize_face_detections WHERE id=$COPY")"
check "the owner's row is intact, in its person" "$PERSON" "$(one "SELECT cluster_id FROM oc_recognize_face_detections WHERE id=$ROW")"
check "the legacy row is byte-identical" "$H0" "$(one "SELECT MD5(CONCAT_WS('|',id,user_id,file_id,x,y,height,width,face_vector,IFNULL(cluster_id,'N'),threshold)) FROM oc_recognize_face_detections WHERE id=$LEG")"
check "the dry run is clean" 0 "$(occ facewright:project --dry-run >/dev/null 2>&1; echo $?)"
verify_step "Recognize's copy removed"
docker exec "$NC_CONTAINER" curl -s -o /dev/null -u "$u:$ADMIN_PASS" -X DELETE "http://localhost/ocs/v2.php/apps/files_sharing/api/v1/shares/$SH?format=json" -H 'OCS-APIRequest: true'

next_uf() { one "SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='oc_facewright_user_faces'"; }
squat() { sql "INSERT INTO oc_recognize_face_detections (id,user_id,file_id,x,y,height,width,face_vector,cluster_id,threshold) VALUES ($1,'$BOB_USER',$FILE,0.2,0.2,0.2,0.2,'[]',NULL,0)"; }
newface() { admin_dav PUT "files/$u/$d/$1.jpg" -T /tmp/portrait.jpg >/dev/null; occ facewright:detect "$u" "$d/$1.jpg" >/dev/null; one "SELECT x.id FROM oc_facewright_user_faces x JOIN oc_facewright_faces f ON f.id=x.face_id JOIN oc_filecache c ON c.fileid=f.file_id WHERE c.path='files/$d/$1.jpg'"; }

echo "== a squatter on the id of a HIDDEN face"
B0=$(occ facewright:project --dry-run 2>/dev/null | grep '^B ' | grep -oE '[0-9]+$')
ID=$(next_uf); squat "$ID"
check "the squatter is in Recognize's table, on the id our next face will get" 1 "$(one "SELECT COUNT(*) FROM oc_recognize_face_detections WHERE id=$ID AND user_id='$BOB_USER'")"
NEW=$(newface h1); check "our new face got exactly that id" "$ID" "$NEW"
check "   it is pending (hidden)" 0 "$(one "SELECT state FROM oc_facewright_user_faces WHERE id=$NEW")"
check "   the dry run counts the squatter as stale (C)" 1 "$(occ facewright:project --dry-run 2>/dev/null | grep '^C ' | grep -oE '[0-9]+$')"
check "   and not as one of our hidden remnants (B stays at its earlier value)" "$B0" "$(occ facewright:project --dry-run 2>/dev/null | grep '^B ' | grep -oE '[0-9]+$')"
occ facewright:project >/dev/null
check "   repair removes it" 0 "$(one "SELECT COUNT(*) FROM oc_recognize_face_detections WHERE id=$ID")"
check "   and our face is untouched" 1 "$(one "SELECT COUNT(*) FROM oc_facewright_user_faces WHERE id=$ID AND state=0")"
check "   the dry run is clean" 0 "$(occ facewright:project --dry-run >/dev/null 2>&1; echo $?)"

echo "== a squatter on the id of a VISIBLE face"
ID=$(next_uf); squat "$ID"; NEW=$(newface v1); check "our new face got that id too" "$ID" "$NEW"
check "give it a person: the push overwrites the squatter" 200 "$(admin_ocs POST /faces/move "{\"ids\":[$NEW],\"clusterId\":$PERSON}" | status)"
check "   the row now belongs to us: owner, person" "$u/$PERSON" "$(one "SELECT CONCAT(user_id,'/',cluster_id) FROM oc_recognize_face_detections WHERE id=$ID")"
check "   the dry run is clean (nothing left over)" 0 "$(occ facewright:project --dry-run >/dev/null 2>&1; echo $?)"
verify_step "squatters"
summary "Recognize's copies"

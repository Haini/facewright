#!/bin/bash
# Recognize's own jobs and commands can delete what we project, with no user or dimension filter. Ours is the
# source of truth, so `occ facewright:project` must bring all of it back. Two real cases, driven through Recognize's
# own code on the dev stack:
#   1  a `.noimage` marker in a folder: Recognize queues a deletion for every file under it and its job deletes the rows
#   2  `occ recognize:reset-face-clusters`: wipes every person and sets every face's cluster to NULL (DEV ONLY: on
#      a real instance this command undoes all curation; the projection is what makes it recoverable)
# After each, the damage is asserted first (a test that damaged nothing proves nothing), then repaired, and after
# case 2 Memories must show again exactly what it showed at the start. Changes data: restore a snapshot afterwards.
#   dev/project-recognize-trap.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
u=$ADMIN_USER; ensure_portrait
ts=$(date +%s); d="fwtrap_$ts"
status() { python3 -c 'import sys,json; print(json.load(sys.stdin)["ocs"]["meta"]["statuscode"])'; }
trap 'admin_dav DELETE "files/$u/$d" >/dev/null 2>&1' EXIT
mkdir -p "$WORK_DIR/trap"

echo "== 1. a .noimage marker and Recognize's deletion job"
check "create a folder" 201 "$(admin_dav MKCOL "files/$u/$d")"
for i in 1 2 3; do admin_dav PUT "files/$u/$d/p$i.jpg" -T /tmp/portrait.jpg >/dev/null; occ facewright:detect "$u" "$d/p$i.jpg" >/dev/null; done
ROWS=$(csv "SELECT x.id FROM oc_facewright_user_faces x JOIN oc_facewright_faces f ON f.id=x.face_id JOIN oc_filecache c ON c.fileid=f.file_id WHERE c.path LIKE 'files/$d/%' ORDER BY x.id")
need "faces detected in the folder" "$(wc -w <<<"$(words "$ROWS")")" 3
PERSON=$(one "SELECT cluster_id FROM oc_facewright_user_faces WHERE user_id='$u' AND state=1 GROUP BY cluster_id ORDER BY cluster_id LIMIT 1")
check "give them a person (so they are projected)" 200 "$(admin_ocs POST /faces/move "{\"ids\":[$ROWS],\"clusterId\":$PERSON}" | status)"
check "they are in Recognize's table" 3 "$(one "SELECT COUNT(*) FROM oc_recognize_face_detections WHERE id IN ($ROWS)")"
before=$(one "SELECT COUNT(*) FROM oc_recognize_fs_deletions")
admin_dav PUT "files/$u/$d/.noimage" -T /dev/null >/dev/null
check "the marker made Recognize queue deletions for the folder's files" 1 "$(( $(one "SELECT COUNT(*) FROM oc_recognize_fs_deletions") > before ? 1 : 0 ))"
JOB=$(one "SELECT id FROM oc_jobs WHERE class LIKE '%ProcessFsActionsJob' AND argument LIKE '%FsDeletion%' ORDER BY id LIMIT 1"); need "Recognize's deletion job" "${JOB:+1}" 1
occ background-job:execute "$JOB" --force-execute >/dev/null 2>&1
check "DAMAGE: Recognize deleted the rows from its table" 0 "$(one "SELECT COUNT(*) FROM oc_recognize_face_detections WHERE id IN ($ROWS)")"
check "   ours still has all three" 3 "$(one "SELECT COUNT(*) FROM oc_facewright_user_faces WHERE id IN ($ROWS)")"
occ facewright:project --dry-run >/dev/null 2>&1; check "the dry run sees it (exit 1)" 1 "$?"
check "   exactly those three (A)" 3 "$(occ facewright:project --dry-run 2>/dev/null | grep '^A ' | grep -oE '[0-9]+$')"
occ facewright:project >/dev/null
check "REPAIRED: the rows are back with the same ids and person" "$PERSON,$PERSON,$PERSON" "$(csv "SELECT cluster_id FROM oc_recognize_face_detections WHERE id IN ($ROWS) ORDER BY id")"
check "   the dry run is clean" 0 "$(occ facewright:project --dry-run >/dev/null 2>&1; echo $?)"
verify_step "the .noimage case"
admin_dav DELETE "files/$u/$d" >/dev/null 2>&1

echo "== 2. Recognize's reset-face-clusters (dev only)"
"$DEV_DIR/memories-api.py" capture "$WORK_DIR/trap/before.json" | tail -1
people=$(one "SELECT COUNT(*) FROM oc_recognize_face_clusters"); visible=$(one "SELECT COUNT(*) FROM oc_recognize_face_detections WHERE cluster_id>0")
need "people to lose" "$people" 5; need "assigned faces to lose" "$visible" 5
occ recognize:reset-face-clusters -n >/dev/null 2>&1 <<<"y"
check "DAMAGE: Recognize's people are gone" 0 "$(one "SELECT COUNT(*) FROM oc_recognize_face_clusters")"
check "   and no face is assigned in its table" 0 "$(one "SELECT COUNT(*) FROM oc_recognize_face_detections WHERE cluster_id>0")"
check "   ours still has all the people" "$people" "$(one "SELECT COUNT(*) FROM oc_facewright_clusters")"
check "   Memories lists nobody" 0 "$(docker exec "$NC_CONTAINER" curl -s -u "$u:$ADMIN_PASS" -H 'OCS-APIRequest: true' http://localhost/apps/memories/api/clusters/recognize | python3 -c 'import sys,json; print(len(json.load(sys.stdin)))')"
occ facewright:project --dry-run >/dev/null 2>&1; check "the dry run sees it (exit 1)" 1 "$?"
occ facewright:project | sed 's/^/    /' | head -8
check "REPAIRED: the people are back" "$people" "$(one "SELECT COUNT(*) FROM oc_recognize_face_clusters")"
check "   and so are the assigned faces" "$visible" "$(one "SELECT COUNT(*) FROM oc_recognize_face_detections WHERE cluster_id>0")"
check "   the dry run is clean" 0 "$(occ facewright:project --dry-run >/dev/null 2>&1; echo $?)"
"$DEV_DIR/memories-api.py" capture "$WORK_DIR/trap/after.json" >/dev/null
check "Memories shows what it showed before" 0 "$("$DEV_DIR/memories-api.py" compare "$WORK_DIR/trap/before.json" "$WORK_DIR/trap/after.json" >/dev/null; echo $?)"
verify_step "the reset case"
summary "Recognize's destructive actions"

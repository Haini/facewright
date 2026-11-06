#!/bin/bash
# Stage E reconcile: plant every kind of drift in Recognize's tables, require `occ facewright:project --dry-run`
# to report exactly those (and exit 1), repair, and require a clean run, an untouched legacy row and Memories
# showing what it showed before. Each fault is counted before it is planted, so a step that planted nothing fails.
# Changes data (restores it): run from a restored snapshot.   dev/project-drift.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
u=$ADMIN_USER
rep() { occ facewright:project --dry-run "$@"; }
num() { rep | grep -E "^$1 " | head -1 | grep -oE '[0-9]+$'; }   # num "A" -> the number on that line
cat_a() { rep | grep '^A ' | grep -oE '[0-9]+$'; }
cat_b() { rep | grep '^B ' | grep -oE '[0-9]+$'; }
cat_c() { rep | grep '^C ' | grep -oE '[0-9]+$'; }
cat_legacy() { rep | grep 'legacy' | grep -oE '[0-9]+$'; }
cat_dm() { rep | grep 'missing or different there' | grep -oE '[0-9]+$'; }
cat_ds() { rep | grep 'nothing references' | grep -oE '[0-9]+$'; }
rowhash() { one "SELECT MD5(CONCAT_WS('|',id,user_id,file_id,x,y,height,width,face_vector,IFNULL(cluster_id,'N'),threshold)) FROM oc_recognize_face_detections WHERE id=$1"; }

echo "== baseline"
mkdir -p "$WORK_DIR/pd"; "$DEV_DIR/memories-api.py" capture "$WORK_DIR/pd/before.json" | tail -1
rep >/dev/null; check "clean before we start (exit 0)" 0 "$?"
check "A is 0" 0 "$(cat_a)"; check "C is 0" 0 "$(cat_c)"; check "D is 0/0" "0/0" "$(cat_dm)/$(cat_ds)"
B0=$(cat_b); L0=$(cat_legacy); need "legacy rows to leave alone" "$L0" 1
LEG=$(one "SELECT d.id FROM oc_recognize_face_detections d LEFT JOIN oc_facewright_user_faces x ON x.id=d.id WHERE x.id IS NULL AND CHAR_LENGTH(d.face_vector) BETWEEN 3 AND 3500 ORDER BY d.id LIMIT 1")
need "a legacy row" "${LEG:+1}" 1; H0=$(rowhash $LEG)

echo "== plant drift"
DEL=$(csv "SELECT x.id FROM oc_facewright_user_faces x WHERE x.user_id='$u' AND x.state=1 ORDER BY x.id DESC LIMIT 10"); need "faces to delete from the copy" "$(wc -w <<<"$(words "$DEL")")" 10
CHG=$(one "SELECT x.id FROM oc_facewright_user_faces x WHERE x.user_id='$u' AND x.state=1 AND x.id NOT IN ($DEL) ORDER BY x.id LIMIT 1")
P1=$(one "SELECT id FROM oc_recognize_face_clusters WHERE user_id='$u' AND title<>'' ORDER BY id LIMIT 1")
P2=$(one "SELECT c.id FROM oc_facewright_clusters c WHERE c.user_id='$u' AND c.id<>$P1 AND c.title<>'' ORDER BY c.id LIMIT 1")
need "a person to retitle and a person to delete" "$([ -n "$P1" ] && [ -n "$P2" ] && echo 1)" 1
sql "DELETE FROM oc_recognize_face_detections WHERE id IN ($DEL)"
sql "UPDATE oc_recognize_face_detections SET cluster_id=-1 WHERE id=$CHG"
sql "UPDATE oc_recognize_face_clusters SET title='drifted' WHERE id=$P1"
sql "DELETE FROM oc_recognize_face_clusters WHERE id=$P2"
sql "INSERT INTO oc_recognize_face_clusters (id,title,user_id) VALUES (8000500,'orphan','$u')"
FILE=$(one "SELECT file_id FROM oc_facewright_faces ORDER BY id LIMIT 1")
sql "INSERT INTO oc_recognize_face_detections (id,user_id,file_id,x,y,height,width,face_vector,cluster_id,threshold) VALUES (8000001,'$u',$FILE,0.1,0.1,0.1,0.1,CONCAT('[',REPEAT('0.123456,',600),'0]'),NULL,0), (8000002,'$u',$FILE,0.1,0.1,0.1,0.1,'[]',NULL,0)"
check "10 copies deleted, 1 changed" 11 "$(( $(echo $DEL | tr ',' '\n' | wc -l) + 1 ))"

echo "== the dry run reports exactly those"
rep > "$WORK_DIR/pd/dry.txt"; rc=$?; cat "$WORK_DIR/pd/dry.txt" | sed 's/^/    /'
check "exit 1" 1 "$rc"
check "A: 10 deleted + 1 changed" 11 "$(cat_a)"
check "B unchanged" "$B0" "$(cat_b)"
check "C: the long copy and the empty copy" 2 "$(cat_c)"
check "legacy rows unchanged" "$L0" "$(cat_legacy)"
check "D missing: 1 retitled + 1 deleted" 2 "$(cat_dm)"
check "D stale: the orphan" 1 "$(cat_ds)"
"$DEV_DIR/memories-api.py" capture "$WORK_DIR/pd/drifted.json" >/dev/null
check "Memories shows the drift (it differs from before)" 1 "$("$DEV_DIR/memories-api.py" compare "$WORK_DIR/pd/before.json" "$WORK_DIR/pd/drifted.json" >/dev/null; echo $?)"
check "a dry run wrote nothing" 11 "$(( $(cat_a) ))"

echo "== repair"
occ facewright:project | sed 's/^/    /'; check "repair exits 0" 0 "${PIPESTATUS[0]}"
rep >/dev/null; check "a clean dry run exits 0" 0 "$?"
check "A, C, D all 0" "0/0/0/0" "$(cat_a)/$(cat_c)/$(cat_dm)/$(cat_ds)"
check "B untouched" "$B0" "$(cat_b)"
check "the legacy row is byte-identical" "$H0" "$(rowhash $LEG)"
check "the stale copies are gone" 0 "$(one "SELECT COUNT(*) FROM oc_recognize_face_detections WHERE id IN (8000001,8000002)")"
check "the orphan is gone" 0 "$(one "SELECT COUNT(*) FROM oc_recognize_face_clusters WHERE id=8000500")"
check "Recognize's table agrees with ours again (retitled)" "$(one "SELECT title FROM oc_facewright_clusters WHERE id=$P1")" "$(one "SELECT title FROM oc_recognize_face_clusters WHERE id=$P1")"
"$DEV_DIR/memories-api.py" capture "$WORK_DIR/pd/after.json" >/dev/null
check "Memories shows what it showed before" 0 "$("$DEV_DIR/memories-api.py" compare "$WORK_DIR/pd/before.json" "$WORK_DIR/pd/after.json" >/dev/null; echo $?)"
check "the old mirror-equality verify still holds" 0 "$(occ facewright:own-tables --verify >/dev/null 2>&1; echo $?)"
summary "projection reconcile"

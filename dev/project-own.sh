#!/bin/bash
# The `own` projection target (docs/memories-fork.md, M6): a copy of our people data in tables only we write, which the
# fork of Memories reads. Requires `occ facewright:project --target=own` to see an empty copy as missing everything
# (and write nothing on a dry run), to fill it, to report exactly the drift planted in it, to repair that, and to leave
# Recognize's tables untouched. Then a broken `own` statement must not stop Recognize's copy or a People write, must
# be flagged and logged by target, and must be repaired afterwards.
# Breaks one column name in the `own` face upsert in the bind-mounted source (a copy is restored on exit, even on a
# crash; the real table is never altered). Changes data: restore a snapshot afterwards.
#   dev/project-own.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
u=$ADMIN_USER
SRC="$REPO_DIR/facewright_bridge/lib/Service/ProjectionService.php"
BAK="$WORK_DIR/ProjectionService.php.own.bak"
LOG=/var/www/html/data/nextcloud.log
cp "$SRC" "$BAK"
trap 'cp "$BAK" "$SRC"; occ config:app:delete facewright_bridge projection_targets >/dev/null 2>&1; reload_web >/dev/null' EXIT
DET=oc_facewright_bridge_face_detections; CLU=oc_facewright_bridge_face_clusters
status() { python3 -c 'import sys,json; print(json.load(sys.stdin)["ocs"]["meta"]["statuscode"])'; }
rep() { occ facewright:project --dry-run --target=own "$@"; }
cat_a() { rep | grep '^A ' | grep -oE '[0-9]+$'; }
cat_c() { rep | grep '^C ' | grep -oE '[0-9]+$'; }
cat_dm() { rep | grep 'missing or different there' | grep -oE '[0-9]+$'; }
cat_ds() { rep | grep 'nothing references' | grep -oE '[0-9]+$'; }
sums() { sql "CHECKSUM TABLE oc_recognize_face_detections, oc_recognize_face_clusters" | awk '{print $2}' | paste -sd/ -; }
n_det() { one "SELECT COUNT(*) FROM $DET"; }
n_clu() { one "SELECT COUNT(*) FROM $CLU"; }
sync_state() { docker exec -u www-data "$NC_CONTAINER" php -r 'define("OC_CONSOLE",1); require_once "/var/www/html/lib/base.php"; $s=\OCP\Server::get(\OCA\FacewrightBridge\Service\ProjectionService::class)->status(); echo $s["dirty"] ? "dirty" : "clean", "|", $s["error"];'; }
log_count() { docker exec "$NC_CONTAINER" sh -c "grep -c 'projection push to the own target failed' $LOG 2>/dev/null || true" | tail -1; }

echo "== data"
VIS=$(one "SELECT COUNT(*) FROM oc_facewright_user_faces WHERE state IN (1,2)"); PEOPLE=$(one "SELECT COUNT(*) FROM oc_facewright_clusters")
need "visible faces of ours" "$VIS" 1000; need "people of ours" "$PEOPLE" 100
# One ignored face to plant a row for (made through the API, before any checksum is taken: it writes Recognize's copy).
IGN=$(one "SELECT id FROM oc_facewright_user_faces WHERE user_id='$u' AND state=1 ORDER BY id DESC LIMIT 1")
check "ignore one face through the API" 200 "$(admin_ocs POST /faces/ignore "{\"ids\":[$IGN]}" | status)"
check "   it is hidden in ours" 4 "$(one "SELECT state FROM oc_facewright_user_faces WHERE id=$IGN")"
VIS=$(one "SELECT COUNT(*) FROM oc_facewright_user_faces WHERE state IN (1,2)")
check "the own tables start empty (a restore empties them)" "0/0" "$(n_det)/$(n_clu)"
occ config:app:set facewright_bridge projection_targets --value=recognize,own >/dev/null
check "the configured targets" "recognize,own" "$(occ config:app:get facewright_bridge projection_targets)"
SUMS=$(sums)

echo "== an empty copy: the dry run sees all of it and writes nothing"
rep > "$WORK_DIR/po-empty.txt"; rc=$?; sed 's/^/    /' "$WORK_DIR/po-empty.txt"
check "exit 1" 1 "$rc"
check "A is every visible face of ours" "$VIS" "$(cat_a)"
check "D missing is every person of ours" "$PEOPLE" "$(cat_dm)"
check "C and D stale are 0" "0/0" "$(cat_c)/$(cat_ds)"
check "a dry run wrote nothing" "0/0" "$(n_det)/$(n_clu)"

echo "== fill it"
occ facewright:project --target=own | sed 's/^/    /'; check "the repair exits 0" 0 "${PIPESTATUS[0]}"
rep >/dev/null; check "a clean dry run exits 0" 0 "$?"
check "one row per visible face" "$VIS" "$(n_det)"
check "one row per person" "$PEOPLE" "$(n_clu)"
check "no hidden face has a row" 0 "$(one "SELECT COUNT(*) FROM $DET d JOIN oc_facewright_user_faces u ON u.id=d.id WHERE u.state NOT IN (1,2)")"
check "unassigned faces are -1, assigned are their person" 0 "$(one "SELECT COUNT(*) FROM $DET d JOIN oc_facewright_user_faces u ON u.id=d.id WHERE d.cluster_id <> IF(u.state=1, u.cluster_id, -1)")"
own_oracle "the fill"

echo "== plant one fault per category in the own tables"
DEL=$(csv "SELECT id FROM $DET WHERE user_id='$u' ORDER BY id DESC LIMIT 5"); need "rows to delete" "$(wc -w <<<"$(words "$DEL")")" 5
CHG=$(one "SELECT id FROM $DET WHERE user_id='$u' AND id NOT IN ($DEL) ORDER BY id LIMIT 1"); OLDC=$(one "SELECT cluster_id FROM $DET WHERE id=$CHG")
P1=$(one "SELECT id FROM $CLU WHERE user_id='$u' AND title<>'' ORDER BY id LIMIT 1"); need "a person to retitle" "${P1:+1}" 1
FILE=$(one "SELECT file_id FROM oc_facewright_faces ORDER BY id LIMIT 1")
sql "DELETE FROM $DET WHERE id IN ($DEL)"
sql "UPDATE $DET SET cluster_id = cluster_id + 1000000 WHERE id=$CHG"
check "   a changed cluster_id" "$((OLDC + 1000000))" "$(one "SELECT cluster_id FROM $DET WHERE id=$CHG")"
sql "INSERT INTO $DET (id,user_id,file_id,x,y,width,height,cluster_id) VALUES (8000001,'$u',$FILE,0.1,0.1,0.1,0.1,-1)"
sql "INSERT INTO $DET (id,user_id,file_id,x,y,width,height,cluster_id) VALUES ($IGN,'$u',$FILE,0.1,0.1,0.1,0.1,-1)"
sql "UPDATE $CLU SET title='drifted' WHERE id=$P1"
sql "INSERT INTO $CLU (id,user_id,title) VALUES (8000500,'$u','orphan')"
check "   a row for an unknown id and a row for an ignored face" 2 "$(one "SELECT COUNT(*) FROM $DET WHERE id IN (8000001,$IGN)")"

echo "== the dry run reports exactly those"
rep > "$WORK_DIR/po-drift.txt"; rc=$?; sed 's/^/    /' "$WORK_DIR/po-drift.txt"
check "exit 1" 1 "$rc"
check "A: 5 deleted + 1 changed" 6 "$(cat_a)"
check "C: the unknown id and the ignored face's id" 2 "$(cat_c)"
check "D missing: the retitled person" 1 "$(cat_dm)"
check "D stale: the person of nobody's" 1 "$(cat_ds)"
check "the dry run wrote nothing (same counts)" 6 "$(cat_a)"
# 6 faces differ from Recognize's copy (5 deleted, 1 changed); own holds 3 that Recognize's does not (the changed one,
# the unknown id, the ignored face's id); 1 person differs (retitled) and own holds 2 that Recognize's does not.
check "the independent oracle sees it too (it can fail): faces 6/3, people 1/2" "6/3/1/2" "$(own_oracle_counts)"

echo "== repair"
occ facewright:project --target=own | sed 's/^/    /'; check "the repair exits 0" 0 "${PIPESTATUS[0]}"
rep >/dev/null; check "a clean dry run exits 0" 0 "$?"
check "A, C, D all 0" "0/0/0/0" "$(cat_a)/$(cat_c)/$(cat_dm)/$(cat_ds)"
check "the planted rows are gone" 0 "$(( $(one "SELECT COUNT(*) FROM $DET WHERE id IN (8000001,$IGN)") + $(one "SELECT COUNT(*) FROM $CLU WHERE id=8000500") ))"
check "the title is back" "$(one "SELECT title FROM oc_facewright_clusters WHERE id=$P1")" "$(one "SELECT title FROM $CLU WHERE id=$P1")"
own_oracle "the repair"
check "Recognize's tables were not touched by any --target=own run" "$SUMS" "$(sums)"

echo "== --hydrate is Recognize's only"
occ facewright:project --hydrate --target=own >/dev/null 2>&1; check "refused (exit 2)" 2 "$?"
check "   and wrote nothing" "$SUMS" "$(sums)"
occ facewright:project --target=nope >/dev/null 2>&1; check "an unknown target is refused (exit 2)" 2 "$?"

echo "== one target failing must not stop the other (a broken column in the own face upsert)"
python3 - "$SRC" <<'PY'
import sys; p=sys.argv[1]; s=open(p).read()
a=s.index("	private function upsertFacesOwn"); b=s.index("ON DUPLICATE KEY UPDATE", a)
seg=s[a:b]; assert "height, cluster_id) " in seg
s=s[:a]+seg.replace("height, cluster_id) ","height, cluster_idx) ",1)+s[b:]
open(p,"w").write(s)
PY
reload_web
A=$(one "SELECT cluster_id FROM oc_facewright_user_faces WHERE user_id='$u' AND state=1 GROUP BY cluster_id HAVING COUNT(*)>=3 ORDER BY cluster_id LIMIT 1")
B=$(one "SELECT id FROM oc_facewright_clusters WHERE user_id='$u' AND id<>$A AND title<>'' ORDER BY id LIMIT 1")
F=$(one "SELECT id FROM oc_facewright_user_faces WHERE user_id='$u' AND cluster_id=$A AND state=1 ORDER BY id LIMIT 1"); need "a face to move" "${F:+1}" 1; need "a second person" "${B:+1}" 1
occ config:app:delete facewright_bridge projection_dirty_seq >/dev/null; occ config:app:delete facewright_bridge projection_clean_seq >/dev/null
check "the copy starts clean" "clean|" "$(sync_state)"
L0=$(log_count)
check "a People write still succeeds" 200 "$(admin_ocs POST /faces/move "{\"ids\":[$F],\"clusterId\":$B}" | status)"
check "   ours has the move" "$B" "$(one "SELECT cluster_id FROM oc_facewright_user_faces WHERE id=$F")"
check "   Recognize's copy has it too (the other target was written)" "$B" "$(one "SELECT cluster_id FROM oc_recognize_face_detections WHERE id=$F")"
check "   the own copy does not (it failed)" "$A" "$(one "SELECT cluster_id FROM $DET WHERE id=$F")"
check "   the copy is flagged dirty" "dirty" "$(sync_state | cut -d'|' -f1)"
echo "     error: $(sync_state | cut -d'|' -f2 | cut -c1-110)"
check "   the flagged error names the target and the column" 1 "$(sync_state | grep -c 'own: .*cluster_idx')"
check "   one error was logged, naming the target" 1 "$(( $(log_count) - L0 ))"
check "the dry run: Recognize's tables are clean, own differs by that one face" "recognize-clean/1" "$(occ facewright:project --dry-run --target=recognize >/dev/null 2>&1 && echo recognize-clean)/$(cat_a)"

echo "== repair through the hourly job once the code is back"
cp "$BAK" "$SRC"; reload_web
JOB=$(one "SELECT id FROM oc_jobs WHERE class LIKE '%ProjectionMaintenanceJob'"); need "the job is registered" "${JOB:+1}" 1
occ background-job:execute "$JOB" --force-execute >/dev/null 2>&1
check "the own copy has the move" "$B" "$(one "SELECT cluster_id FROM $DET WHERE id=$F")"
check "the copy is clean again" "clean|" "$(sync_state | sed 's/|.*$/|/')"
check "the dry run over every target is clean" 0 "$(occ facewright:project --dry-run >/dev/null 2>&1; echo $?)"
verify_step "the repair"
summary "the own projection target"

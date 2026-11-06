#!/bin/bash
# Stage E failure isolation: if a push to Recognize's tables fails (a changed schema, a lock timeout), our own
# People writes must still succeed, the admin page must say so, and the hourly job must repair it afterwards.
# Breaks one column name in the projection's face upsert in the bind-mounted source (a copy is restored on exit,
# even on a crash; the real table is never altered), then repairs through the job. Changes data: restore a snapshot.
#   dev/project-failure.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
u=$ADMIN_USER
SRC="$REPO_DIR/facewright_bridge/lib/Service/ProjectionService.php"
BAK="$WORK_DIR/ProjectionService.php.bak"
cp "$SRC" "$BAK"; trap 'cp "$BAK" "$SRC"; reload_web >/dev/null' EXIT
status() { python3 -c 'import sys,json; print(json.load(sys.stdin)["ocs"]["meta"]["statuscode"])'; }
sync_state() { docker exec -u www-data "$NC_CONTAINER" php -r 'define("OC_CONSOLE",1); require_once "/var/www/html/lib/base.php"; $s=\OCP\Server::get(\OCA\FacewrightBridge\Service\ProjectionService::class)->status(); echo $s["dirty"] ? "dirty" : "clean", "|", $s["error"];'; }
rfd_cluster() { one "SELECT IFNULL(cluster_id,'none') FROM oc_recognize_face_detections WHERE id=$1"; }
ours_cluster() { one "SELECT IFNULL(cluster_id,'none') FROM oc_facewright_user_faces WHERE id=$1"; }

echo "== data and a healthy start"
A=$(one "SELECT cluster_id FROM oc_facewright_user_faces WHERE user_id='$u' AND state=1 GROUP BY cluster_id HAVING COUNT(*)>=3 ORDER BY cluster_id LIMIT 1")
B=$(one "SELECT id FROM oc_facewright_clusters WHERE user_id='$u' AND id<>$A AND title<>'' ORDER BY id LIMIT 1")
F=($(words "$(csv "SELECT id FROM oc_facewright_user_faces WHERE user_id='$u' AND cluster_id=$A ORDER BY id LIMIT 2")"))
need "faces to move" "${#F[@]}" 2; need "a second person" "${B:+1}" 1
occ config:app:delete facewright_bridge projection_dirty_seq >/dev/null; occ config:app:delete facewright_bridge projection_clean_seq >/dev/null
check "the copy starts clean" "clean|" "$(sync_state)"
check "the copy agrees for the face we will move" "$A" "$(rfd_cluster ${F[0]})"

echo "== break the push (one column name in the face upsert)"
python3 - "$SRC" <<'PY'
import sys; p=sys.argv[1]; s=open(p).read()
a=s.index("	private function upsertFaces"); b=s.index("ON DUPLICATE KEY UPDATE", a)
seg=s[a:b]; assert "face_vector, cluster_id, threshold)" in seg
s=s[:a]+seg.replace("face_vector, cluster_id, threshold)","face_vector, cluster_id, thresholdx)",1)+s[b:]
open(p,"w").write(s)
PY
reload_web   # the web process caches compiled PHP for up to 60 s
check "People write still succeeds (the push fails behind it)" 200 "$(admin_ocs POST /faces/move "{\"ids\":[${F[0]}],\"clusterId\":$B}" | status)"
check "   ours has the move" "$B" "$(ours_cluster ${F[0]})"
check "   Recognize's table does NOT (so Memories still shows the old person)" "$A" "$(rfd_cluster ${F[0]})"
check "   the copy is flagged dirty, with the error" "dirty" "$(sync_state | cut -d'|' -f1)"
echo "     error: $(sync_state | cut -d'|' -f2 | cut -c1-90)"
check "   the error names the broken column" 1 "$(sync_state | grep -c thresholdx)"
check "a second People write also succeeds" 200 "$(admin_ocs POST /faces/move "{\"ids\":[${F[1]}],\"clusterId\":$B}" | status)"
check "the admin page says it is not up to date" 1 "$(docker exec -u www-data "$NC_CONTAINER" php -r 'define("OC_CONSOLE",1); require_once "/var/www/html/lib/base.php"; echo json_encode(\OCP\Server::get(\OCA\FacewrightBridge\Settings\AdminSettings::class)->getForm()->getParams()["memoriesSync"]["dirty"]);' 2>/dev/null | grep -c true)"
check "the dry run sees the difference" 1 "$(occ facewright:project --dry-run >/dev/null 2>&1; echo $?)"
check "   it is exactly the 2 faces we moved (A)" 2 "$(occ facewright:project --dry-run 2>/dev/null | grep '^A ' | grep -oE '[0-9]+$')"

echo "== repair through the hourly job once the code is back"
cp "$BAK" "$SRC"; reload_web
JOB=$(one "SELECT id FROM oc_jobs WHERE class LIKE '%ProjectionMaintenanceJob'"); need "the job is registered" "${JOB:+1}" 1
occ background-job:execute "$JOB" --force-execute >/dev/null 2>&1
check "Recognize's table now has both moves" "$B/$B" "$(rfd_cluster ${F[0]})/$(rfd_cluster ${F[1]})"
check "the copy is clean again" "clean|" "$(sync_state | sed 's/|.*$/|/')"
check "the dry run is clean" 0 "$(occ facewright:project --dry-run >/dev/null 2>&1; echo $?)"
verify_step "the repair"
summary "projection failure isolation"

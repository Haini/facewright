#!/bin/bash
# Rollback tier R2 (docs/own-tables.md): after the cutover has been used, `occ facewright:project --hydrate` writes
# every state back into Recognize's tables, the bridge files go back to the Stage D commit, and the Stage D checks
# must pass: nothing is lost. Then the Stage E files return and the copy must be clean again.
# Swaps the TRACKED files of facewright_bridge in this checkout (restored on exit, even on a crash), so it refuses to
# start unless that directory is clean. Changes data: restore a snapshot afterwards.
#   dev/rollback-drill.sh        STAGE_D_REF (default 3c0cba4) is the last commit before Stage E
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
: "${STAGE_D_REF:=3c0cba4}"; u=$ADMIN_USER
BR="$REPO_DIR/facewright_bridge"
[ -z "$(git -C "$REPO_DIR" status --porcelain facewright_bridge)" ] || { echo "facewright_bridge has uncommitted changes: commit or stash them first (this script swaps its files)"; exit 2; }
git -C "$REPO_DIR" cat-file -e "$STAGE_D_REF^{commit}" 2>/dev/null || { echo "no such commit: $STAGE_D_REF"; exit 2; }
STAGE_E_REF=$(git -C "$REPO_DIR" rev-parse HEAD)
restore_e() { rsync -a --delete "$WORK_DIR/stage-e-tree/" "$BR/" 2>/dev/null; git -C "$REPO_DIR" checkout "$STAGE_E_REF" -- facewright_bridge 2>/dev/null; reload_web >/dev/null; }
trap restore_e EXIT
status() { python3 -c 'import sys,json; print(json.load(sys.stdin)["ocs"]["meta"]["statuscode"])'; }
n() { one "SELECT COUNT(*) FROM oc_facewright_user_faces WHERE user_id='$u' AND state=$1"; }
rfd_total() { one "SELECT COUNT(*) FROM oc_recognize_face_detections WHERE user_id='$u'"; }
rm -rf "$WORK_DIR/stage-e-tree"; mkdir -p "$WORK_DIR/stage-e-tree"; rsync -a "$BR/" "$WORK_DIR/stage-e-tree/"

echo "== Stage E in use: hidden states exist only in ours"
P=$(one "SELECT cluster_id FROM oc_facewright_user_faces WHERE user_id='$u' AND state=1 GROUP BY cluster_id HAVING COUNT(*)>=4 ORDER BY cluster_id LIMIT 1")
F=($(words "$(csv "SELECT id FROM oc_facewright_user_faces WHERE user_id='$u' AND cluster_id=$P ORDER BY id LIMIT 4")")); need "faces to work with" "${#F[@]}" 4
check "ignore one face" 200 "$(admin_ocs POST /faces/ignore "{\"ids\":[${F[0]}]}" | status)"
check "detach one face" 200 "$(admin_ocs POST /faces/detach "{\"ids\":[${F[1]}]}" | status)"
check "rename the person" 200 "$(admin_ocs PUT "/people/$P" '{"title":"Drill"}' | status)"
occ facewright:cluster "$u" --min-faces=3 >/dev/null
ign=$(n 4); pend=$(n 0); unas=$(n 2); asg=$(n 1); need "an ignored face" "$ign" 1; need "an unassigned face" "$unas" 1
echo "  ours: assigned $asg, unassigned $unas, unclusterable $(n 3), ignored $ign, pending $pend"
check "the ignored face is NOT in Recognize's table (that is Stage E)" 0 "$(one "SELECT COUNT(*) FROM oc_recognize_face_detections WHERE id=${F[0]}")"
occ facewright:project --dry-run >/dev/null 2>&1; check "the copy is clean under Stage E" 0 "$?"

echo "== R2 step 1: hydrate"
occ facewright:project --hydrate | sed 's/^/    /'
check "the ignored face is back in Recognize's table, as -3" "-3" "$(one "SELECT cluster_id FROM oc_recognize_face_detections WHERE id=${F[0]}")"
check "the detached face as -1" "-1" "$(one "SELECT cluster_id FROM oc_recognize_face_detections WHERE id=${F[1]}")"
check "every face of ours has a row there again" 0 "$(one "SELECT COUNT(*) FROM oc_facewright_user_faces x LEFT JOIN oc_recognize_face_detections d ON d.id=x.id WHERE d.id IS NULL")"
check "every person of ours too, with the new name" "Drill" "$(one "SELECT title FROM oc_recognize_face_clusters WHERE id=$P")"

echo "== R2 step 2: the Stage D files go back"
STAGE_D_TREE="$WORK_DIR/stage-d-tree"; rm -rf "$STAGE_D_TREE"; mkdir -p "$STAGE_D_TREE"
git -C "$REPO_DIR" archive "$STAGE_D_REF" facewright_bridge | tar -x -C "$STAGE_D_TREE"
rsync -a --delete "$STAGE_D_TREE/facewright_bridge/" "$BR/"
# The restored info.xml names the OLD version while the database records the NEW one, and Nextcloud answers a
# mismatch with "requires upgrade": occ loses its commands and the web returns 503. `occ upgrade` is not the answer
# (it applies every pending store update). The restored files keep the installed version instead.
INSTALLED=$(occ config:app:get facewright_bridge installed_version); need "the installed version is known" "${INSTALLED:+1}" 1
echo "  restored info.xml says: $(grep -o '<version>[^<]*' "$BR/appinfo/info.xml" | cut -c10-); installed: $INSTALLED"
sed -i "s#<version>[^<]*</version>#<version>$INSTALLED</version>#" "$BR/appinfo/info.xml"
reload_web
check "the bridge files are Stage D's (no ProjectionService)" 0 "$(ls "$BR/lib/Service/ProjectionService.php" 2>/dev/null | wc -l)"
out=$(occ facewright:own-tables --verify 2>&1); rc=$?
echo "$out" | sed 's/^/    /' | head -16
check "Stage D's --verify (row for row against Recognize's table) passes" 0 "$rc"
check "   it checks the mirror (people_in_source is a Stage D line)" 1 "$(echo "$out" | grep -c people_in_source)"

echo "== the Stage D checks on the rolled-back system"
reads=$("$DEV_DIR/reads.sh" stable 2>&1 | tail -1); echo "    $reads"
check "the read capture is stable under Stage D code" 1 "$(grep -c STABLE <<<"$reads")"
check "People API writes work under Stage D code" 200 "$(admin_ocs POST /faces/move "{\"ids\":[${F[0]},${F[1]}],\"clusterId\":$P}" | status)"
check "   and Stage D's --verify still holds after them" 0 "$(occ facewright:own-tables --verify >/dev/null 2>&1; echo $?)"
check "the DAV router works under Stage D code (a rename through Memories' path)" 1 "$("$DEV_DIR/battery.sh" 2>&1 | grep -c 'failed 0')"
check "the Stage E job row left in the job list does not break cron" 0 "$(occ background-job:list >/dev/null 2>&1; echo $?)"
# Nextcloud drops a job whose class is missing when cron walks the job list: whether that happened inside this short
# window is chance, so it is reported, not asserted. What must hold is the state after rolling forward (below).
DROPPED=$(( 1 - $(one "SELECT COUNT(*) FROM oc_jobs WHERE class LIKE '%ProjectionMaintenanceJob'") ))
echo "  (info) the Stage E job row was dropped by Nextcloud while Stage D ran: $([ "$DROPPED" = 1 ] && echo yes || echo no, not yet)"

echo "== and forward again"
restore_e; trap - EXIT
check "the Stage E files are back" 1 "$(ls "$BR/lib/Service/ProjectionService.php" 2>/dev/null | wc -l)"
occ app:disable facewright_bridge >/dev/null 2>&1; occ app:enable facewright_bridge >/dev/null 2>&1
check "   after an app:disable / app:enable cycle the hourly job is registered exactly once" 1 "$(one "SELECT COUNT(*) FROM oc_jobs WHERE class LIKE '%ProjectionMaintenanceJob'")"
check "   and the app is enabled at the same version" "0.10.0" "$(occ config:app:get facewright_bridge installed_version)"
for id in $(sql "SELECT id FROM oc_jobs WHERE class LIKE '%Recognize%MaintenanceJob' OR class LIKE '%ClusterFacesJob'"); do echo y | docker exec -i -u www-data "$NC_CONTAINER" php occ background-job:delete "$id" >/dev/null 2>&1; done
check "   and after deleting Recognize's MaintenanceJob (re-added by the cycle, same chain) none remain" 0 "$(one "SELECT COUNT(*) FROM oc_jobs WHERE class LIKE '%Recognize%MaintenanceJob' OR class LIKE '%ClusterFacesJob'")"
check "   the tree matches the commit" "" "$(git -C "$REPO_DIR" status --porcelain facewright_bridge)"
occ facewright:project --dry-run >/dev/null 2>&1; check "the copy is clean under Stage E again (exit 0)" 0 "$?"
verify_step "rolling forward"
summary "rollback drill (R2)"

#!/bin/bash
# What the dev stack must contain for the suites to test anything. Hard requirements stop the run (exit 2);
# warnings say which comparison would be vacuous without the data.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
warn_if_none() { if [ "${2:-0}" -ge 1 ] 2>/dev/null; then printf '  have  %-54s %s\n' "$1" "$2"; else printf '  WARN  %-54s none: %s\n' "$1" "$3"; fi; }

echo "== containers and users"
for c in "$NC_CONTAINER" "$DB_CONTAINER"; do
  check "container $c running" yes "$(docker inspect -f '{{.State.Running}}' "$c" 2>/dev/null | sed 's/true/yes/; s/false/no/')"
done
check "$ADMIN_USER can log in (WebDAV)" 207 "$(admin_dav PROPFIND "files/$ADMIN_USER/" -H 'Depth: 0')"
check "$BOB_USER can log in (WebDAV)" 207 "$(bob_dav PROPFIND "files/$BOB_USER/" -H 'Depth: 0')"
check "facewright_bridge enabled" yes "$(occ app:list 2>/dev/null | grep -q ' facewright_bridge:' && echo yes || echo no)"
if [ "$FAIL" -ne 0 ]; then summary "fixture"; exit 2; fi

echo "== hard requirements"
need "$ADMIN_USER: people with 3 or more faces" "$(one "SELECT COUNT(*) FROM (SELECT cluster_id FROM oc_facewright_user_faces WHERE user_id='$ADMIN_USER' AND state=1 GROUP BY cluster_id HAVING COUNT(*)>=3) x")" 5
need "$ADMIN_USER: titled people" "$(one "SELECT COUNT(*) FROM oc_recognize_face_clusters WHERE user_id='$ADMIN_USER' AND title<>''")" 2
need "$BOB_USER: faces" "$(one "SELECT COUNT(*) FROM oc_facewright_user_faces WHERE user_id='$BOB_USER'")" 1
if occ facewright:own-tables --verify >/dev/null; then echo "  have  own-tables --verify                                clean"; else echo "  MISSING PRECONDITION: --verify is not clean; restore a snapshot (dev/snapshot.sh restore)"; exit 2; fi

echo "== data that makes comparisons meaningful (warnings only)"
warn_if_none "$ADMIN_USER: unassigned faces" "$(one "SELECT COUNT(*) FROM oc_facewright_user_faces WHERE user_id='$ADMIN_USER' AND state=2")" "the unassigned views and the reopen dry-run compare nothing"
warn_if_none "$ADMIN_USER: ignored faces" "$(one "SELECT COUNT(*) FROM oc_facewright_user_faces WHERE user_id='$ADMIN_USER' AND state=4")" "the ignored views compare nothing"
warn_if_none "$ADMIN_USER: faces whose photo exists on disk" "$(one "SELECT COUNT(*) FROM oc_facewright_user_faces u JOIN oc_facewright_faces f ON f.id=u.face_id JOIN oc_filecache fc ON fc.fileid=f.file_id WHERE u.user_id='$ADMIN_USER'")" "face crops always answer 404, so the crop comparison proves nothing"
warn_if_none "files with rows for 2 or more users" "$(one "SELECT COUNT(*) FROM (SELECT f.file_id FROM oc_facewright_faces f JOIN oc_facewright_user_faces u ON u.face_id=f.id GROUP BY f.file_id HAVING COUNT(DISTINCT u.user_id)>1) x")" "run dev/make-shared-fixture.sh before the direct-call harness"
warn_if_none "pending faces (any user)" "$(one "SELECT COUNT(*) FROM oc_facewright_user_faces WHERE state=0")" "the unclustered count compares nothing"
echo; echo "fixture ok"

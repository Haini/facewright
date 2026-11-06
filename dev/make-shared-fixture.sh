#!/bin/bash
# Gives the second user rows on a few of the admin's files, through AccessSyncService::copyToUsers (the same path
# a share takes), so the direct-call harness meets files held by more than one user. Idempotent: it only copies
# files the second user does not have yet.   usage: dev/make-shared-fixture.sh [FILES (default 3)]
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
n=${1:-3}
files=$(csv "SELECT DISTINCT f.file_id FROM oc_facewright_faces f JOIN oc_facewright_user_faces x ON x.face_id=f.id WHERE x.user_id='$ADMIN_USER' AND f.file_id NOT IN (SELECT f2.file_id FROM oc_facewright_faces f2 JOIN oc_facewright_user_faces x2 ON x2.face_id=f2.id WHERE x2.user_id='$BOB_USER') ORDER BY f.file_id LIMIT $n")
[ -z "$files" ] && { echo "nothing to do: $BOB_USER already holds rows on every file $ADMIN_USER has"; exit 0; }
for f in $(words "$files"); do
  printf 'file %s: ' "$f"
  docker exec -u www-data "$NC_CONTAINER" php -r 'define("OC_CONSOLE",1); require_once "/var/www/html/lib/base.php"; echo \OCP\Server::get(\OCA\FacewrightBridge\Service\AccessSyncService::class)->copyToUsers((int)$argv[1], $argv[2], [$argv[3]]), " row(s) copied\n";' "$f" "$ADMIN_USER" "$BOB_USER"
done
verify_step "shared fixture"; summary "shared fixture"

#!/bin/bash
# Step C3 of docs/exapp-clustering.md: ExAppClusteringClient against the dev ExApp. Checksums, cold and warm cache sync,
# a stale entry replaced, the 409 retry after the cache is emptied, a face that cannot be synced, and a transport
# failure with the ExApp stopped (it is started again). Writes nothing to Nextcloud's database; leaves the ExApp's cache
# holding USER's pool.
#   dev/exapp-client.sh [USER]     (default: admin)
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
: "${FW_CONTAINER:=recognaize-facewright-1}"
user=${1:-$ADMIN_USER}
need "faces of $user" "$(one "SELECT COUNT(*) FROM oc_facewright_user_faces WHERE user_id='$user'")" 1
PASS=0; FAIL=0
echo "== the ExApp up"
php_checks exapp_client.php "$user"
echo "== the ExApp stopped"
docker stop "$FW_CONTAINER" >/dev/null
php_checks exapp_client.php "$user" down
docker start "$FW_CONTAINER" >/dev/null
for i in $(seq 1 90); do
  [ "$(docker exec "$NC_CONTAINER" curl -s -o /dev/null -w '%{http_code}' http://facewright:9030/heartbeat)" = 200 ] && break; sleep 2
done
check "ExApp answers again" 200 "$(docker exec "$NC_CONTAINER" curl -s -o /dev/null -w '%{http_code}' http://facewright:9030/heartbeat)"
summary exapp-client && [ "$PASS" -gt 0 ]

#!/bin/bash
# Step S4 of docs/exapp-clustering.md: deleted faces leave the ExApp's embedding cache.
#   - deleting a file makes the bridge tell the ExApp to forget its faces
#   - deleting a folder does so in ONE request for all its files (PruneService walks it file by file)
#   - with the ExApp stopped, a delete still succeeds and logs one warning
#   - occ facewright:reset empties the cache
#   - forgetFile() outside a tree walk (AccessSyncService's reconcile, when nobody has access left) forgets at once
#   - planted in the code: the batched forget removed, so the faces of a deleted file stay cached
# Uses copies of the real test portrait (dev/make-portrait.py), detected for admin. Changes data: restores the snapshot
# "original" at the end (the reset deletes everything of ours).
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
PASS=0; FAIL=0
: "${FW_CONTAINER:=recognaize-facewright-1}"
u=$ADMIN_USER; ts=$(date +%s); dir="fwcache_$ts"
F=$REPO_DIR/facewright_bridge/lib/Service/AccessSyncService.php
LOG=/var/www/html/data/nextcloud.log
W=$WORK_DIR/cache-hygiene; rm -rf "$W"; mkdir -p "$W"; cp "$F" "$W/AccessSyncService.php.good"
cleanup() { cp "$W/AccessSyncService.php.good" "$F"; reload_web >/dev/null; docker start "$FW_CONTAINER" >/dev/null 2>&1; }
trap cleanup EXIT

cached() { docker exec "$FW_CONTAINER" python3 -c "import sqlite3,sys; print(sqlite3.connect('/data/embeddings.sqlite').execute(f'SELECT COUNT(*) FROM embeddings WHERE id IN ({sys.argv[1] or 0})').fetchone()[0])" "$1"; }
cache_total() { cached "SELECT id FROM embeddings"; }
client() {  # client PHP : runs PHP with $c = the bridge's ExAppClusteringClient
  docker exec -u www-data "$NC_CONTAINER" php -r 'define("OC_CONSOLE",1); require_once "/var/www/html/lib/base.php"; $c=\OCP\Server::get(\OCA\FacewrightBridge\Service\ExAppClusteringClient::class); '"$1" "${@:2}"
}
log_size() { docker exec "$NC_CONTAINER" stat -c %s "$LOG"; }
log_since() { docker exec "$NC_CONTAINER" tail -c +$(( $1 + 1 )) "$LOG"; }
forgets_since() { docker logs --since "$1" "$FW_CONTAINER" 2>&1 | grep -c 'POST /cluster/cache/forget'; }
heartbeat() { for _ in $(seq 60); do docker exec "$NC_CONTAINER" curl -s -o /dev/null -m 2 -w '%{http_code}' http://facewright:9030/heartbeat 2>/dev/null | grep -q 200 && return 0; sleep 1; done; return 1; }
faces_of() { csv "SELECT f.id FROM oc_facewright_faces f JOIN oc_filecache c ON c.fileid = f.file_id WHERE c.path = 'files/$1' AND c.storage = (SELECT numeric_id FROM oc_storages WHERE id = 'home::$u')"; }
photo() {  # photo PATH : uploads the portrait to PATH and detects it; prints its face ids
  [ "$(admin_dav PUT "files/$u/$1" -T /tmp/portrait.jpg)" = 201 ] || { echo "upload of $1 failed" >&2; return; }
  occ facewright:detect "$u" "$1" >/dev/null 2>&1
  faces_of "$1"
}

"$DEV_DIR/snapshot.sh" restore original >/dev/null
ensure_portrait
client '$c->clear();'
echo "== fixture: the portrait as one file, two files in a folder, and one more, all detected and cached"
check "create a folder" 201 "$(admin_dav MKCOL "files/$u/$dir")"
A=$(photo "fwcache_a_$ts.jpg"); B=$(photo "$dir/b.jpg"); C=$(photo "$dir/c.jpg"); D=$(photo "fwcache_d_$ts.jpg"); P=$(photo "fwcache_p_$ts.jpg"); E=$(photo "fwcache_e_$ts.jpg")
ALL="$A,$B,$C,$D,$P,$E"
need "faces detected in all six" "$(wc -w <<<"$(words "$ALL")")" 6
client '$c->syncCache($argv[1], array_map("intval", explode(",", $argv[2])));' "$u" "$ALL"
check "all six cached" 6 "$(cached "$ALL")"

echo "== delete one file"
check "DELETE it" 204 "$(admin_dav DELETE "files/$u/fwcache_a_$ts.jpg")"
check "its face is gone from our table" 0 "$(one "SELECT COUNT(*) FROM oc_facewright_faces WHERE id IN ($A)")"
check "and from the ExApp's cache" 0 "$(cached "$A")"
check "the others stay cached" 5 "$(cached "$B,$C,$D,$P,$E")"

echo "== delete a folder of two"
t0=$(date -u +%Y-%m-%dT%H:%M:%S.%NZ)   # docker logs --since: whole seconds would include the delete above
check "DELETE the folder" 204 "$(admin_dav DELETE "files/$u/$dir")"
check "both faces gone from the cache" 0 "$(cached "$B,$C")"
check "in one forget request" 1 "$(forgets_since "$t0")"

echo "== the ExApp stopped"
docker stop "$FW_CONTAINER" >/dev/null
off=$(log_size)
check "DELETE still succeeds" 204 "$(admin_dav DELETE "files/$u/fwcache_d_$ts.jpg")"
check "its face is gone from our table" 0 "$(one "SELECT COUNT(*) FROM oc_facewright_faces WHERE id IN ($D)")"
check "one warning logged" 1 "$(log_since "$off" | grep -c 'could not tell the ExApp to forget')"
docker start "$FW_CONTAINER" >/dev/null; heartbeat || echo "    (the ExApp did not answer its heartbeat within 60 s)"
check "its entry stays cached (inert; the 30-day prune removes it)" 1 "$(cached "$D")"

echo "== forgetFile() outside a tree walk"
efile=$(one "SELECT file_id FROM oc_facewright_faces WHERE id = $E")
docker exec -u www-data "$NC_CONTAINER" php -r 'define("OC_CONSOLE",1); require_once "/var/www/html/lib/base.php"; \OCP\Server::get(\OCA\FacewrightBridge\Service\AccessSyncService::class)->forgetFile((int)$argv[1]);' "$efile"
check "its face is gone from our table" 0 "$(one "SELECT COUNT(*) FROM oc_facewright_faces WHERE id IN ($E)")"
check "and from the cache, at once" 0 "$(cached "$E")"
admin_dav DELETE "files/$u/fwcache_e_$ts.jpg" >/dev/null

echo "== planted in the code: the batched forget removed"
python3 - "$F" <<'PY'
import sys
p = sys.argv[1]; s = open(p).read()
old = "\t\t\t$this->forgetBuffer = null;\n\t\t\t$this->forgetFaces($faceIds);\n"
assert s.count(old) == 1
open(p, "w").write(s.replace(old, "\t\t\t$this->forgetBuffer = null;\n\t\t\t// PLANTED: no forget\n"))
PY
reload_web   # the delete runs in the web server, which would keep the old code for up to 60 s
check "DELETE" 204 "$(admin_dav DELETE "files/$u/fwcache_p_$ts.jpg")"
check "planted: the deleted face stays cached (the single-file check above would fail)" 1 "$(cached "$P")"
cp "$W/AccessSyncService.php.good" "$F"; reload_web
check "AccessSyncService restored" 0 "$(grep -c PLANTED "$F")"

echo "== occ facewright:reset empties the cache"
need "entries cached before" "$(cache_total)" 1
check "reset reports it" yes "$(occ facewright:reset --yes 2>&1 | grep -q "Emptied the ExApp's embedding cache" && echo yes || echo no)"
check "cache empty" 0 "$(cache_total)"

"$DEV_DIR/snapshot.sh" restore original | tail -1
summary cache-hygiene

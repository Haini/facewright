#!/bin/bash
# Step S1 of docs/exapp-clustering.md: facewright_faces.embedding_crc.
#   - the backfill fills every NULL, resumably, and the values equal CRC32(embedding)
#   - own-tables --verify checks it, and fails on a wrong stored checksum (planted in the data)
#   - a forced re-detection rewrites the embedding and its checksum together; with the checksum dropped from the upsert
#     (planted in the code) the same re-detection leaves a wrong checksum and --verify fails
#   - the client reads the stored value, and computes it where it is NULL
# Changes data: restores the snapshot "original" before and after.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
PASS=0; FAIL=0
F=$REPO_DIR/facewright_bridge/lib/Service/DetectionService.php
wrong() { one "SELECT COUNT(*) FROM oc_facewright_faces WHERE embedding_crc IS NOT NULL AND embedding_crc <> CRC32(embedding)"; }
missing() { one "SELECT COUNT(*) FROM oc_facewright_faces WHERE embedding_crc IS NULL"; }
verify_rc() { occ facewright:own-tables --verify >/dev/null 2>&1; echo $?; }
"$DEV_DIR/snapshot.sh" restore original >/dev/null

echo "== backfill"
need "faces without a checksum" "$(missing)" 100
total=$(one "SELECT COUNT(*) FROM oc_facewright_faces")
check "dry run counts them, changes nothing" "$total face(s) without a stored checksum./$total" "$(occ facewright:embedding-checksums --dry-run | head -1)/$(missing)"
occ facewright:embedding-checksums --chunk=1000 | tail -1 | sed 's/^/    /'
check "none missing" 0 "$(missing)"
check "none wrong" 0 "$(wrong)"
check "a second run has nothing to do" "0 face(s) without a stored checksum." "$(occ facewright:embedding-checksums | head -1)"
check "--verify passes" 0 "$(verify_rc)"
check "--verify reports the check" yes "$(occ facewright:own-tables --verify 2>&1 | grep -q embedding_crc_wrong && echo yes || echo no)"

echo "== resumable: a partial run is continued, not repeated"
sql "UPDATE oc_facewright_faces SET embedding_crc = NULL WHERE id % 2 = 0"
half=$(missing)
check "re-run fills exactly the NULL ones" "Filled $half checksum(s); 0 still missing." "$(occ facewright:embedding-checksums --chunk=700 | tail -1)"

echo "== planted in the data: a wrong stored checksum"
id=$(one "SELECT MIN(id) FROM oc_facewright_faces")
sql "UPDATE oc_facewright_faces SET embedding_crc = embedding_crc + 1 WHERE id = $id"
check "--verify fails" 1 "$(verify_rc)"
sql "UPDATE oc_facewright_faces SET embedding_crc = CRC32(embedding) WHERE id = $id"
check "--verify passes again" 0 "$(verify_rc)"

echo "== the client: stored value, or computed where NULL"
cat > "$WORK_DIR/crc_client.php" <<'PHP'
<?php
define('OC_CONSOLE', 1); require_once '/var/www/html/lib/base.php';
$db = \OCP\Server::get(\OCP\IDBConnection::class);
$ids = array_map('intval', $db->executeQuery('SELECT id FROM oc_facewright_faces ORDER BY id LIMIT 200')->fetchAll(\PDO::FETCH_COLUMN));
$got = \OCP\Server::get(\OCA\FacewrightBridge\Service\ExAppClusteringClient::class)->checksums($ids);
$want = [];
foreach ($db->executeQuery('SELECT id, CRC32(embedding) c FROM oc_facewright_faces ORDER BY id LIMIT 200')->fetchAll() as $r) $want[(int)$r['id']] = (int)$r['c'];
ksort($got);
echo $got === $want ? "same\n" : "differ\n";
PHP
docker cp "$WORK_DIR/crc_client.php" "$NC_CONTAINER:/tmp/crc_client.php" >/dev/null
check "client = CRC32(embedding), all stored" same "$(docker exec -u www-data "$NC_CONTAINER" php /tmp/crc_client.php)"
sql "UPDATE oc_facewright_faces SET embedding_crc = NULL WHERE id IN (SELECT id FROM (SELECT id FROM oc_facewright_faces ORDER BY id LIMIT 50) x)"
check "client = CRC32(embedding), 50 of 200 NULL" same "$(docker exec -u www-data "$NC_CONTAINER" php /tmp/crc_client.php)"
occ facewright:embedding-checksums >/dev/null

echo "== a forced re-detection rewrites embedding and checksum together"
# The real test portrait (dev/make-portrait.py), uploaded for admin and detected once: dev's own admin files are synthetic
# fixtures (hundreds of fake faces, an all-zero embedding) that a re-detection cannot reproduce. Then the face's stored
# embedding and checksum are made consistently wrong (another face's), so the re-detection must change both.
ensure_portrait
path="fw-crc-test-$$.jpg"
check "upload the portrait" 201 "$(admin_dav PUT "files/$ADMIN_USER/$path" -T /tmp/portrait.jpg)"
occ facewright:detect "$ADMIN_USER" "$path" >/dev/null 2>&1
file=$(one "SELECT fileid FROM oc_filecache WHERE path = 'files/$path' AND storage = (SELECT numeric_id FROM oc_storages WHERE id = 'home::$ADMIN_USER')")
fid=$(one "SELECT MIN(id) FROM oc_facewright_faces WHERE file_id = ${file:-0}")
need "the portrait's face detected" "${fid:-0}" 1
check "detected with its checksum" 0 "$(one "SELECT COUNT(*) FROM oc_facewright_faces WHERE id = $fid AND (embedding_crc IS NULL OR embedding_crc <> CRC32(embedding))")"
other=$(one "SELECT MAX(id) FROM oc_facewright_faces WHERE id <> $fid")
plant_stale() { sql "UPDATE oc_facewright_faces f JOIN oc_facewright_faces o ON o.id = $other SET f.embedding = o.embedding, f.embedding_crc = o.embedding_crc WHERE f.id = $fid"; }
plant_stale
check "planted: consistent but stale" "0/1" "$(wrong)/$(one "SELECT COUNT(*) FROM oc_facewright_faces f JOIN oc_facewright_faces o ON o.id = $other WHERE f.id = $fid AND f.embedding = o.embedding")"
occ facewright:detect "$ADMIN_USER" "$path" >/dev/null 2>&1
check "re-detection replaced the embedding" 0 "$(one "SELECT COUNT(*) FROM oc_facewright_faces f JOIN oc_facewright_faces o ON o.id = $other WHERE f.id = $fid AND f.embedding = o.embedding")"
check "and its checksum with it" 0 "$(wrong)"

echo "== planted in the code: the upsert without the checksum"
cp "$F" "$WORK_DIR/DetectionService.php.good"
python3 - "$F" <<'PY'
import sys
p = sys.argv[1]; s = open(p).read()
old = "\t\t\t'embedding_crc' => crc32($blob),\n"
assert s.count(old) == 1
open(p, "w").write(s.replace(old, "\t\t\t// PLANTED: no checksum\n"))
PY
plant_stale
occ facewright:detect "$ADMIN_USER" "$path" >/dev/null 2>&1
check "faulted upsert leaves a wrong checksum" 1 "$(wrong)"
check "--verify fails" 1 "$(verify_rc)"
cp "$WORK_DIR/DetectionService.php.good" "$F"
check "DetectionService restored" 0 "$(grep -c PLANTED "$F")"

check "delete the portrait" 204 "$(admin_dav DELETE "files/$ADMIN_USER/$path")"
"$DEV_DIR/snapshot.sh" restore original | tail -1
docker exec "$NC_CONTAINER" rm -f /tmp/crc_client.php
summary embedding-crc

#!/bin/bash
# Step C1 of docs/exapp-clustering.md: the ExApp's embedding cache and /cluster, through AppAPI as the bridge calls them.
#   - MariaDB's CRC32(embedding), PHP's crc32() and Python's zlib.crc32 agree on real rows (the design depends on it)
#   - put stores real embeddings; check then reports nothing missing
#   - a put with one bad checksum stores none of the request
#   - /cluster on a synthetic pool of N (default 50,000): answers, and /heartbeat keeps answering meanwhile
#   - /cluster with a face never put, or a wrong checksum in the pool: 409 naming it
# Also prints the matching self-test (dev/exapp_match_selftest.py). Leaves the cache empty. Nextcloud's data unchanged.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
: "${FW_CONTAINER:=recognaize-facewright-1}"; : "${N:=50000}"
PASS=0; FAIL=0
ok()  { echo "  ok   $1"; PASS=$((PASS + 1)); }
bad() { echo "  FAIL $1"; FAIL=$((FAIL + 1)); }
expect() { [ "$2" = "$3" ] && ok "$1 ($3)" || bad "$1: expected $2, got $3"; }
W="$WORK_DIR/exapp-cluster"; rm -rf "$W"; mkdir -p "$W"
call() {  # call ROUTE FILE -> "HTTP status ms" line, then the body
  docker exec -u www-data "$NC_CONTAINER" php /tmp/exapp-cluster/exapp_call.php "$1" "/tmp/exapp-cluster/$2" admin 300
}
push_files() { docker exec "$NC_CONTAINER" rm -rf /tmp/exapp-cluster; docker cp "$W" "$NC_CONTAINER:/tmp/exapp-cluster" >/dev/null; docker exec "$NC_CONTAINER" chown -R www-data /tmp/exapp-cluster; }
cache_count() { docker exec "$FW_CONTAINER" python3 -c "import sqlite3; print(sqlite3.connect('/data/embeddings.sqlite').execute('SELECT COUNT(*) FROM embeddings').fetchone()[0])"; }
status() { head -1 | awk '{print $2}'; }
cp "$DEV_DIR/exapp_call.php" "$W/"; echo '{}' > "$W/empty.json"

echo "== matching: match_chunk against a port of the PHP loop"
if docker exec -i "$FW_CONTAINER" python3 - < "$DEV_DIR/exapp_match_selftest.py" | sed 's/^/    /'; then ok "self-test"; else bad "self-test"; fi

echo "== checksums on real rows"
sql "SELECT id, CRC32(embedding), HEX(embedding) FROM oc_facewright_faces ORDER BY id LIMIT 20" > "$W/real.tsv"
need "real faces on dev" "$(wc -l < "$W/real.tsv")" 20
py=$(python3 -c '
import sys, zlib
bad = [l.split()[0] for l in open(sys.argv[1]) if zlib.crc32(bytes.fromhex(l.split()[2])) != int(l.split()[1])]
print(len(bad))' "$W/real.tsv")
expect "MariaDB CRC32 = Python zlib.crc32 (rows differing)" 0 "$py"
php=$(docker exec -i "$NC_CONTAINER" php -r '$n=0; while (($l = fgets(STDIN)) !== false) { [$id,$c,$h] = explode("\t", trim($l)); if (crc32(hex2bin($h)) !== (int)$c) $n++; } echo $n;' < "$W/real.tsv")
expect "MariaDB CRC32 = PHP crc32 (rows differing)" 0 "$php"

echo "== put and check, real rows"
call /cluster/cache/clear empty.json >/dev/null 2>&1 || true
python3 - "$W" <<'EOF'
import base64, json, sys
w = sys.argv[1]
rows = [l.split() for l in open(f"{w}/real.tsv")]
json.dump({"faces": [{"id": int(i), "crc": int(c), "embedding": base64.b64encode(bytes.fromhex(h)).decode()} for i, c, h in rows]}, open(f"{w}/put-real.json", "w"))
json.dump({"faces": [[int(i), int(c)] for i, c, h in rows]}, open(f"{w}/check-real.json", "w"))
json.dump({"faces": [[int(i), (int(c) + 1) % 2**32] for i, c, h in rows[:5]]}, open(f"{w}/check-stale.json", "w"))
two = rows[:2]
faces = [{"id": 800_000_000 + k, "crc": int(c), "embedding": base64.b64encode(bytes.fromhex(h)).decode()} for k, (i, c, h) in enumerate(two)]
faces[1]["crc"] = (faces[1]["crc"] + 1) % 2**32
json.dump({"faces": faces}, open(f"{w}/put-bad.json", "w"))
EOF
push_files
expect "cache empty after clear" 0 "$(cache_count)"
expect "put of 20 real faces" 200 "$(call /cluster/cache/put put-real.json | status)"
expect "cache holds them" 20 "$(cache_count)"
expect "check: nothing missing" '{"missing":[]}' "$(call /cluster/cache/check check-real.json | tail -1)"
expect "check: another checksum counts as missing (count)" 5 "$(call /cluster/cache/check check-stale.json | tail -1 | python3 -c 'import json,sys; print(len(json.load(sys.stdin)["missing"]))')"

echo "== a put with one bad checksum stores nothing"
expect "put with a bad checksum" 400 "$(call /cluster/cache/put put-bad.json | status)"
expect "cache unchanged" 20 "$(cache_count)"

echo "== synthetic pool of $N"
docker exec "$FW_CONTAINER" rm -rf /tmp/gen
docker exec -i "$FW_CONTAINER" python3 - /tmp/gen "$N" < "$DEV_DIR/exapp_cluster_gen.py" | sed 's/^/    /'
docker cp "$FW_CONTAINER:/tmp/gen/." "$W/" >/dev/null; docker exec "$FW_CONTAINER" rm -rf /tmp/gen
push_files
put_fail=0; put_max=0
for f in "$W"/put-[0-9]*.json; do
  r=$(call /cluster/cache/put "$(basename "$f")" | head -1)
  [ "$(awk '{print $2}' <<<"$r")" = 200 ] || put_fail=$((put_fail + 1))
  ms=$(awk '{print $3}' <<<"$r"); [ "$ms" -gt "$put_max" ] && put_max=$ms
done
expect "puts of 2,000 faces (~$(( $(stat -c %s "$W/put-000.json") / 1024 )) KB each) failing" 0 "$put_fail"
echo "    slowest put: ${put_max} ms"
expect "cache holds the pool" $((N + 20)) "$(cache_count)"
check_body=$(python3 -c "import json; b=json.load(open('$W/cluster.json')); print(json.dumps({'faces': [[p[1], p[2]] for p in b['pool']]}))")
echo "$check_body" > "$W/check-pool.json"; push_files
r=$(call /cluster/cache/check check-pool.json)
expect "check of $N pairs ($(( $(stat -c %s "$W/check-pool.json") / 1024 )) KB)" '200 {"missing":[]}' "$(head -1 <<<"$r" | awk '{print $2}') $(tail -1 <<<"$r")"

echo "== /cluster on $N, /heartbeat meanwhile"
call /cluster cluster.json > "$W/cluster.out" &
cpid=$!
hb_max=0; hb_n=0; hb_fail=0
while kill -0 $cpid 2>/dev/null; do
  t=$(docker exec "$NC_CONTAINER" curl -s -o /dev/null -m 10 -w '%{http_code} %{time_total}' http://facewright:9030/heartbeat)
  [ "${t%% *}" = 200 ] || hb_fail=$((hb_fail + 1))
  ms=$(awk '{printf "%d", $2 * 1000}' <<<"$t"); [ "$ms" -gt "$hb_max" ] && hb_max=$ms; hb_n=$((hb_n + 1))
done
wait $cpid
expect "/cluster status" 200 "$(status < "$W/cluster.out")"
echo "    round trip $(head -1 "$W/cluster.out" | awk '{print $3}') ms; ExApp: $(tail -1 "$W/cluster.out" | python3 -c 'import json,sys; d=json.load(sys.stdin); a=[x["action"] for x in d["decisions"]]; print(f"compute {d["compute_ms"]} ms, total {d["total_ms"]} ms, decisions {len(a)}:", {k: a.count(k) for k in set(a)}, f"labels changed {len(d["labels"])}")')"
need "heartbeats sampled during /cluster" "$hb_n" 2
expect "heartbeats failing during /cluster" 0 "$hb_fail"
echo "    $hb_n heartbeats, slowest ${hb_max} ms"
[ "$hb_max" -lt 1000 ] && ok "heartbeat under 1 s during /cluster" || bad "heartbeat took ${hb_max} ms during /cluster"

echo "== /cluster refuses a pool it cannot match"
r=$(call /cluster cluster-missing.json)
expect "face never put" "409 {\"missing\":[$((900000000 + N))]}" "$(head -1 <<<"$r" | awk '{print $2}') $(tail -1 <<<"$r")"
r=$(call /cluster cluster-badcrc.json)
expect "wrong checksum in the pool" "409 {\"missing\":[900000003]}" "$(head -1 <<<"$r" | awk '{print $2}') $(tail -1 <<<"$r")"

echo "== clean up"
expect "clear" 200 "$(call /cluster/cache/clear empty.json | status)"
expect "cache empty" 0 "$(cache_count)"
docker exec "$NC_CONTAINER" rm -rf /tmp/exapp-cluster
echo "cache file on disk after clear: $(docker exec "$FW_CONTAINER" du -h /data/embeddings.sqlite | cut -f1)"
echo; echo "exapp-cluster: $PASS passed, $FAIL failed"
[ "$FAIL" = 0 ]

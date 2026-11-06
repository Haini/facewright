#!/bin/bash
# Two parallel loops of overlapping People writes (moves between three people, detaches, ignores, renames) on the
# same faces. Afterwards Recognize's tables must still match ours (A, C, D all 0): the case a delete-then-insert
# projection would fail with duplicate keys, and where two pushes of the same rows race.
# Every response status is counted; a request that fails is allowed (a deadlock victim), a copy that disagrees is not.
# Changes data: restore a snapshot afterwards.   dev/project-concurrency.sh   (LOOPS=40 per worker, WORKERS=2)
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
: "${LOOPS:=40}"; : "${WORKERS:=2}"; u=$ADMIN_USER
P=($(words "$(csv "SELECT cluster_id FROM oc_facewright_user_faces WHERE user_id='$u' AND state=1 GROUP BY cluster_id HAVING COUNT(*)>=4 ORDER BY cluster_id LIMIT 3")"))
need "people with 4+ faces" "${#P[@]}" 3
F=$(csv "SELECT id FROM oc_facewright_user_faces WHERE user_id='$u' AND cluster_id=${P[0]} ORDER BY id LIMIT 4")
IDS=$(csv "SELECT id FROM oc_facewright_user_faces WHERE user_id='$u' AND cluster_id IN (${P[0]},${P[1]},${P[2]}) ORDER BY id LIMIT 12")
need "faces to fight over" "$(wc -w <<<"$(words "$IDS")")" 12
echo "  people ${P[*]}; faces $IDS"
out="$WORK_DIR/conc"; rm -rf "$out"; mkdir -p "$out"
worker() {   # worker N : LOOPS random writes; one status per line into out/N
  local n=$1 i op f a b
  local arr=($(words "$IDS"))
  for i in $(seq 1 "$LOOPS"); do
    f=${arr[$((RANDOM % ${#arr[@]}))]}; a=${P[$((RANDOM % 3))]}; b=${arr[$((RANDOM % ${#arr[@]}))]}
    case $((RANDOM % 5)) in
      0|1) admin_ocs POST /faces/move "{\"ids\":[$f,$b],\"clusterId\":$a}" ;;
      2)   admin_ocs POST /faces/detach "{\"ids\":[$f]}" ;;
      3)   admin_ocs POST /faces/ignore "{\"ids\":[$f]}"; admin_ocs POST /faces/unignore "{\"ids\":[$f]}" ;;
      4)   admin_ocs PUT "/people/$a" "{\"title\":\"conc-$n-$i\"}" ;;
    esac | python3 -c 'import sys,json
raw=sys.stdin.read(); dec=json.JSONDecoder(); i=0; seen=0   # one request may print several documents (ignore, then unignore)
while i < len(raw):
    while i < len(raw) and raw[i].isspace(): i += 1
    if i >= len(raw): break
    try: obj, i = dec.raw_decode(raw, i); print(obj["ocs"]["meta"]["statuscode"]); seen += 1
    except Exception: print("bad"); break
if not seen and not raw.strip(): print("bad")' >> "$out/$n"
  done
}
echo "== $WORKERS workers x $LOOPS operations"
for n in $(seq 1 "$WORKERS"); do worker "$n" & done; wait
cat "$out"/* | sort | uniq -c | awk '{printf "  status %s: %s\n", $2, $1}'
total=$(cat "$out"/* | wc -l); need "responses recorded (at least one per operation)" "$total" "$((WORKERS * LOOPS))"
ok=$(cat "$out"/* | grep -c '^200$'); bad=$((total - ok))
check "every request succeeded (no failed responses)" 0 "$bad"
echo "== afterwards"
occ facewright:project --dry-run | sed 's/^/    /'
check "Recognize's tables match ours (A, C, D all 0)" 0 "$(occ facewright:project --dry-run >/dev/null 2>&1; echo $?)"
verify_step "the concurrent writes"
summary "projection under concurrent writes"

#!/bin/bash
# Each person's stored size and cover (facewright_cluster_stats, kept by FaceStore): the cases the other suites may not
# reach. A person's cover face leaving it (the full recompute), a better face joining (the incremental one), merge,
# delete, a clustering run after a reset, and opposite moves between two people at the same time (the locks).
# verify_step checks every stored size and cover after each step, including that each cover is the best face.
# Changes data: restore a snapshot afterwards.   dev/people-stats.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
u=$ADMIN_USER
status() { python3 -c 'import sys,json; d=json.load(sys.stdin)["ocs"]; print(d["meta"]["statuscode"])'; }
cover() { one "SELECT IFNULL(cover_id,'-') FROM oc_facewright_cluster_stats WHERE cluster_id=$1"; }
size() { one "SELECT face_count FROM oc_facewright_cluster_stats WHERE cluster_id=$1"; }
real_size() { one "SELECT COUNT(*) FROM oc_facewright_user_faces WHERE user_id='$u' AND cluster_id=$1"; }
listed() {  # the person's count and cover as the People list's API answers them
  admin_ocs GET "/people?limit=200&minCount=0" | python3 -c "import sys,json; d=[p for p in json.load(sys.stdin)['ocs']['data']['items'] if p['id']==$1]; print(f\"{d[0]['count']}/{d[0]['cover']}\" if d else 'missing')"; }

echo "== data"
read -r P Q <<<"$(sql "SELECT cluster_id FROM oc_facewright_user_faces WHERE user_id='$u' AND state=1 GROUP BY cluster_id HAVING COUNT(*)>=5 ORDER BY COUNT(*) DESC, cluster_id LIMIT 2" | paste -sd' ')"
need "two people with 5+ faces" "$([ -n "$Q" ] && echo 1 || echo 0)" 1
echo "  P=$P ($(size $P) faces, cover $(cover $P)), Q=$Q ($(size $Q) faces, cover $(cover $Q))"
check "the list answers P's stored size and cover" "$(size $P)/$(cover $P)" "$(listed $P)"
verify_step "nothing yet"

echo "== P's cover face leaves P (P recomputes, Q compares)"
CP=$(cover $P); np=$(size $P); nq=$(size $Q)
check "move P's cover face into Q" 200 "$(admin_ocs POST /faces/move "{\"ids\":[$CP],\"clusterId\":$Q}" | status)"
check "   P has one face fewer" "$((np-1))" "$(size $P)"
check "   Q has one face more" "$((nq+1))" "$(size $Q)"
check "   P's cover is another face now" yes "$([ "$(cover $P)" != "$CP" ] && echo yes || echo no)"
verify_step "cover face leaves"

echo "== it comes back (the best face joining P becomes its cover again)"
check "move it back into P" 200 "$(admin_ocs POST /faces/move "{\"ids\":[$CP],\"clusterId\":$P}" | status)"
check "   P's cover is that face again" "$CP" "$(cover $P)"
check "   sizes as at the start" "$np/$nq" "$(size $P)/$(size $Q)"
verify_step "best face joins"

echo "== detach, ignore, unignore P's cover"
check "detach P's cover" 200 "$(admin_ocs POST /faces/detach "{\"ids\":[$CP]}" | status)"
check "   P's size" "$((np-1))" "$(size $P)"
verify_step "detach the cover"
check "ignore it" 200 "$(admin_ocs POST /faces/ignore "{\"ids\":[$CP]}" | status)"
verify_step "ignore the detached cover"
check "unignore it" 200 "$(admin_ocs POST /faces/unignore "{\"ids\":[$CP]}" | status)"
check "move it back into P" 200 "$(admin_ocs POST /faces/move "{\"ids\":[$CP],\"clusterId\":$P}" | status)"
check "   P's cover is that face again" "$CP" "$(cover $P)"
verify_step "back into P"

echo "== opposite moves at the same time (P->Q and Q->P), 8 rounds"
fails=0
for round in 1 2 3 4 5 6 7 8; do
  a=$(csv "SELECT id FROM oc_facewright_user_faces WHERE user_id='$u' AND cluster_id=$P ORDER BY RAND() LIMIT 2")
  b=$(csv "SELECT id FROM oc_facewright_user_faces WHERE user_id='$u' AND cluster_id=$Q ORDER BY RAND() LIMIT 2")
  admin_ocs POST /faces/move "{\"ids\":[$a],\"clusterId\":$Q}" > "$WORK_DIR/ps-a.json" &
  admin_ocs POST /faces/move "{\"ids\":[$b],\"clusterId\":$P}" > "$WORK_DIR/ps-b.json" &
  wait
  for f in "$WORK_DIR/ps-a.json" "$WORK_DIR/ps-b.json"; do [ "$(status < "$f")" = 200 ] || { fails=$((fails+1)); head -c 300 "$f"; echo; }; done
done
check "all 16 moves answered 200" 0 "$fails"
check "   P's stored size is its real one" "$(real_size $P)" "$(size $P)"
check "   Q's stored size is its real one" "$(real_size $Q)" "$(size $Q)"
verify_step "concurrent opposite moves"

echo "== merge Q into P, then delete P"
nq=$(size $Q); np=$(size $P)
check "merge Q into P" 200 "$(admin_ocs POST /people/merge "{\"keep\":$P,\"merge\":[$Q]}" | status)"
check "   P holds both" "$((np+nq))" "$(size $P)"
check "   Q's row is gone" 0 "$(one "SELECT COUNT(*) FROM oc_facewright_cluster_stats WHERE cluster_id=$Q")"
verify_step "merge"
check "delete P" 200 "$(admin_ocs POST /people/delete "{\"ids\":[$P]}" | status)"
check "   P's row is gone" 0 "$(one "SELECT COUNT(*) FROM oc_facewright_cluster_stats WHERE cluster_id=$P")"
verify_step "delete"

echo "== reset the user and cluster again"
occ facewright:cluster "$u" --reset >/dev/null 2>&1
check "no stored size left for the user's old people" 0 "$(one "SELECT COUNT(*) FROM oc_facewright_cluster_stats s LEFT JOIN oc_facewright_clusters c ON c.id = s.cluster_id WHERE s.user_id='$u' AND c.id IS NULL")"
need "the clustering made people again" "$(one "SELECT COUNT(*) FROM oc_facewright_cluster_stats WHERE user_id='$u' AND face_count > 0")" 1
verify_step "reset and clustering"

echo "== --recount rebuilds the same"
before=$(sql "SELECT cluster_id, face_count, IFNULL(cover_id,0) FROM oc_facewright_cluster_stats ORDER BY cluster_id" | md5sum)
sql "UPDATE oc_facewright_cluster_stats SET face_count = face_count + 1, cover_id = NULL"
check "verify fails on broken sizes" 1 "$(occ facewright:own-tables --verify >/dev/null 2>&1; echo $?)"
occ facewright:own-tables --recount >/dev/null
check "--recount restores every row" "$before" "$(sql "SELECT cluster_id, face_count, IFNULL(cover_id,0) FROM oc_facewright_cluster_stats ORDER BY cluster_id" | md5sum)"
verify_step "recount"

summary "people stats"

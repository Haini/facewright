#!/bin/bash
# Capture every read path of the People code into files, then compare two captures. Stage D was verified by
# capturing on the old code, changing the code, capturing again and diffing: error-free output is not evidence.
#
#   dev/reads.sh capture DIR          capture into DIR
#   dev/reads.sh compare DIR_A DIR_B  diff two captures (exit 1 if they differ)
#   dev/reads.sh stable               capture twice and require identical output (a baseline that is not
#                                     deterministic cannot detect anything)
#
# What is captured: the People API (list with covers, search, paging, minimum size, unassigned, ignored, one
# person and its faces, the boxes on six files, suggestions) for the admin user and for the second user, the
# requests that must fail (the second user asking for the admin's people), face crops for the owner and for
# the other user (status, ETag and a hash of the image), the People CLI views, the reset, reopen and backfill
# dry-runs, the unclustered count, and every suggestion centroid recomputed from the embeddings and hashed.
# Which people and files are used is chosen from the database, so the same data gives the same capture.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

capture() {
  local O=$1; rm -rf "$O"; mkdir -p "$O"
  local u=$ADMIN_USER
  # --- what to look at, chosen from the data
  local P; P=($(words "$(csv "SELECT cluster_id FROM oc_facewright_user_faces WHERE user_id='$u' AND state=1 GROUP BY cluster_id ORDER BY COUNT(*) DESC, cluster_id LIMIT 3")"))
  local SEARCH; SEARCH=$(one "SELECT LEFT(title,1) FROM oc_recognize_face_clusters WHERE user_id='$u' AND title<>'' ORDER BY id LIMIT 1")
  local FILES; FILES=$(csv "SELECT DISTINCT f.file_id FROM oc_facewright_faces f JOIN oc_facewright_user_faces x ON x.face_id=f.id WHERE x.user_id='$u' ORDER BY f.file_id LIMIT 6")
  local BFILES; BFILES=$(csv "SELECT DISTINCT f.file_id FROM oc_facewright_faces f JOIN oc_facewright_user_faces x ON x.face_id=f.id WHERE x.user_id='$BOB_USER' ORDER BY f.file_id LIMIT 3")
  local CROPS; CROPS=$(csv "SELECT x.id FROM oc_facewright_user_faces x JOIN oc_facewright_faces f ON f.id=x.face_id JOIN oc_filecache fc ON fc.fileid=f.file_id WHERE x.user_id='$u' ORDER BY x.id LIMIT 3")
  local NOFILE; NOFILE=$(csv "SELECT x.id FROM oc_facewright_user_faces x JOIN oc_facewright_faces f ON f.id=x.face_id LEFT JOIN oc_filecache fc ON fc.fileid=f.file_id WHERE x.user_id='$u' AND fc.fileid IS NULL ORDER BY x.id LIMIT 1")
  need "people to look at" "${#P[@]}" 1; need "files to look at" "$(wc -w <<<"$(words "$FILES")")" 1
  {
    echo "people: ${P[*]}"; echo "search: $SEARCH"; echo "files: $FILES"; echo "bob files: $BFILES"; echo "crops: $CROPS; no photo: $NOFILE"
  } > "$O/selection.txt"

  # --- SuggestionService recomputing every centroid from the embeddings, first and always: a cached centroid is
  # stored as float32 and a fresh one is a double, so the same suggestion differs in the ninth digit depending on
  # whether the cache was warm. Rebuilding it up front makes every read in the capture see the same cache.
  sql "DELETE FROM oc_facewright_cluster_centroids"
  occ facewright:people "$u" --suggest --faces="${P[0]}" >/dev/null 2>&1
  local BP; BP=$(one "SELECT cluster_id FROM oc_facewright_user_faces WHERE user_id='$BOB_USER' AND state=1 ORDER BY id LIMIT 1")
  [ -n "$BP" ] && occ facewright:people "$BOB_USER" --suggest --faces="$BP" >/dev/null 2>&1
  # --- People API, admin
  get() { admin_ocs GET "$1" > "$O/$2.json"; }
  get "/people?limit=500&offset=0" people-all
  get "/people?limit=500&minCount=3" people-min3
  get "/people?limit=500&search=$SEARCH" people-search
  get "/people?limit=5&offset=5" people-page
  get "/people/unassigned?limit=500" unassigned
  get "/people/ignored?limit=500" ignored
  local i=0; for id in "${P[@]}"; do i=$((i+1)); get "/people/$id" person$i; get "/people/$id/faces?limit=500" faces-person$i; done
  get "/people/${P[0]}/suggestions" suggestions-person1
  i=0; for f in $(words "$FILES"); do i=$((i+1)); get "/files/$f/faces" file$i; done
  # --- People API, second user: own data, and what he must not see
  bget() { bob_ocs GET "$1" > "$O/$2.json"; }
  bget "/people?limit=500" bob-people; bget "/people/unassigned?limit=500" bob-unassigned
  i=0; for f in $(words "$BFILES"); do i=$((i+1)); bget "/files/$f/faces" bob-file$i; done
  bget "/people/${P[0]}" bob-asks-for-admin-person
  bget "/people/${P[0]}/faces?limit=50" bob-asks-for-admin-faces
  bget "/people/${P[0]}/suggestions" bob-asks-for-admin-suggestions
  # --- face crops: status, ETag and a hash of the image (a refused crop has only a status: its body carries a token)
  i=0; for f in $(words "$CROPS") $(words "$NOFILE"); do i=$((i+1))
    for who in admin bob; do
      local user=$ADMIN_USER pass=$ADMIN_PASS; [ $who = bob ] && { user=$BOB_USER; pass=$BOB_PASS; }
      docker exec "$NC_CONTAINER" sh -c "curl -s -D /tmp/h -o /tmp/b -u $user:$pass 'http://localhost/apps/facewright_bridge/faces/$f/preview?size=128'; head -1 /tmp/h | tr -d '\r'; if head -1 /tmp/h | grep -q ' 200 '; then grep -i '^etag' /tmp/h | tr -d '\r'; md5sum /tmp/b | cut -c1-32; fi" > "$O/crop$i-$who.txt"
    done
  done
  # --- CLI views
  for user in "$ADMIN_USER" "$BOB_USER"; do
    occ facewright:people "$user" > "$O/cli-people-$user.txt" 2>&1
    occ facewright:people "$user" --unassigned > "$O/cli-unassigned-$user.txt" 2>&1
    occ facewright:people "$user" --ignored > "$O/cli-ignored-$user.txt" 2>&1
    occ facewright:people "$user" --delete-empty --dry-run > "$O/cli-empty-$user.txt" 2>&1
  done
  occ facewright:people "$u" --faces="${P[0]}" > "$O/cli-faces.txt" 2>&1
  occ facewright:people "$u" --suggest --faces="${P[0]}" --no-store 2>&1 | sed -E 's/ *(centroid-time|distance-time|total|peak-memory)=[^ ]*//g' > "$O/cli-suggest.txt"
  occ facewright:reset --dry-run > "$O/cli-reset-dry.txt" 2>&1
  occ facewright:reopen-unassigned --dry-run > "$O/cli-reopen-dry.txt" 2>&1
  occ facewright:backfill-access --dry-run 2>&1 | grep -viE 'elapsed|seconds' > "$O/cli-backfill-dry.txt"
  docker exec -u www-data "$NC_CONTAINER" php -r 'define("OC_CONSOLE",1); require_once "/var/www/html/lib/base.php"; $r=\OCP\Server::get(\OCA\FacewrightBridge\Service\ClusteringService::class)->countUnclusteredByUser(); ksort($r); echo json_encode($r), "\n";' > "$O/unclustered-counts.txt" 2>&1
  sql "SELECT cluster_id, user_id, member_count, MD5(centroid) FROM oc_facewright_cluster_centroids ORDER BY cluster_id" > "$O/centroids.txt"
  # --- what was captured: a capture of nothing would also be "stable"
  echo "captured $(ls "$O" | wc -l) files:"
  python3 - "$O" <<'PY'
import json,glob,os,sys
d=sys.argv[1]
def n(f,key=None):
    try:
        x=json.load(open(f))["ocs"]["data"]
        return len(x.get(key,[])) if key else len(x)
    except Exception: return None
print("  people listed:", n(d+"/people-all.json","items"), "| unassigned:", n(d+"/unassigned.json","items"), "| faces of person 1:", n(d+"/faces-person1.json","items"))
print("  boxes on file 1:", n(d+"/file1.json"), "| centroids hashed:", max(0,sum(1 for _ in open(d+"/centroids.txt"))))
bad=[os.path.basename(f) for f in sorted(glob.glob(d+"/*.json")) if json.load(open(f))["ocs"]["meta"]["status"]!="ok"]
print("  API captures that are errors (only bob-asks-for-admin-* should be):", bad)
print("  crops:", {os.path.basename(f)[:-4]: open(f).readline().strip().split(" ",1)[1] for f in sorted(glob.glob(d+"/crop*.txt"))})
PY
}

compare() {
  if diff -rq "$1" "$2" >/dev/null; then echo "IDENTICAL: $(ls "$1" | wc -l) captured outputs"; return 0; fi
  echo "DIFFERENT:"; diff -rq "$1" "$2" | sed 's#^Files .*/\([^/]*\) and .*#  \1#; s#^Only in \(.*\): \(.*\)#  only in \1: \2#'; return 1
}

case "${1:-}" in
  capture) capture "${2:?usage: reads.sh capture DIR}" ;;
  compare) compare "${2:?DIR_A}" "${3:?DIR_B}" ;;
  stable)  capture "$WORK_DIR/reads-a" >/dev/null && capture "$WORK_DIR/reads-b" >/dev/null && \
           compare "$WORK_DIR/reads-a" "$WORK_DIR/reads-b" && echo "STABLE: two captures of unchanged data are identical" ;;
  *) sed -n '2,19p' "$0"; exit 2 ;;
esac

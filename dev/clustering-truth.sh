#!/bin/bash
# Clustering against ground truth, and a regression net for "consistent but wrong" table states.
# Loads PEOPLE (default 20) people from the Georgia Tech set under a user of their own, runs detection and
# clustering, and asks dev/truth.py whether the result is pure, complete and the same in Recognize's table.
# Then it makes mistakes on purpose (a face moved to the wrong person, two people merged) and requires the oracle to
# CATCH each one, and finally recovers by reclustering. Changes data: restore a snapshot afterwards.
#   dev/clustering-truth.sh          PEOPLE=50 for the whole set (about 750 detections)
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
: "${PEOPLE:=20}"; : "${GT_USER:=gt}"; : "${GT_PASS:=dev-gt-password-123}"
"$DEV_DIR/fetch-dataset.sh" || exit 2
truth() { python3 "$DEV_DIR/truth.py" "$GT_USER" "$@"; }
st() { truth --quiet "$@" >/dev/null 2>&1; echo $?; }
gt_ocs() { ocs "$GT_USER" "$GT_PASS" "$@"; }
ids() { csv "SELECT cluster_id FROM oc_facewright_user_faces WHERE user_id='$GT_USER' AND state=1 GROUP BY cluster_id ORDER BY cluster_id LIMIT $1"; }
face_of() { one "SELECT id FROM oc_facewright_user_faces WHERE user_id='$GT_USER' AND cluster_id=$1 ORDER BY id LIMIT 1"; }

# Uploading and detecting is most of this suite and says nothing about clustering, so its result is cached: the rows
# detection left for this user are kept in .work and loaded while nothing that decides them has changed. DETECT_FRESH=1
# forces the full path. A snapshot restore resets the face tables but not the files, so the user and photos stay valid.
REPO_DIR="$(cd "$DEV_DIR/.." && pwd)"
cache_key() { { echo "$PEOPLE"; cat "$REPO_DIR/ex_app/lib/main.py" "$REPO_DIR/facewright_bridge/lib/Service/DetectionService.php" "$REPO_DIR/facewright_bridge/lib/Service/FaceVectorCodec.php"; } | sha256sum | cut -c1-16; }
cache="$WORK_DIR/gt-cache/$(cache_key).sql"
photos() { one "SELECT COUNT(*) FROM oc_filecache WHERE path LIKE 'files/gtdb/%' AND size>0 AND storage=(SELECT storage FROM oc_filecache WHERE path='files/gtdb' ORDER BY fileid DESC LIMIT 1)"; }
want=$((PEOPLE * 15))
echo "== set up a user with $PEOPLE people"
need "people available in the dataset" 50 "$PEOPLE"
if [ -z "${DETECT_FRESH:-}" ] && [ -s "$cache" ] && [ -n "$(one "SELECT 1 FROM oc_users WHERE uid='$GT_USER'")" ] && [ "$(photos)" = "$want" ]; then
  echo "  (detection restored from $(basename "$cache"); DETECT_FRESH=1 runs it for real)"
  docker exec -i "$DB_CONTAINER" mariadb -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" 2>/dev/null < "$cache"
  GT_PASS=$(fast_pass "$GT_USER" "$GT_PASS")
  check "photos uploaded, none empty" "$want" "$(photos)"
else
  # A snapshot restore resets the database but not the data directory, so start from nothing on both sides.
  [ -n "$(one "SELECT 1 FROM oc_users WHERE uid='$GT_USER'")" ] && occ user:delete "$GT_USER" -n >/dev/null 2>&1
  docker exec "$NC_CONTAINER" rm -rf "/var/www/html/data/$GT_USER"; rm -f "$WORK_DIR/tokens/$GT_USER"
  docker exec -u www-data -e OC_PASS="$GT_PASS" "$NC_CONTAINER" php occ user:add --password-from-env --display-name="Ground truth" "$GT_USER" >/dev/null
  docker exec -u www-data "$NC_CONTAINER" php -r 'define("OC_CONSOLE",1); require_once "/var/www/html/lib/base.php"; $s=\OCP\Server::get(\OCA\FacewrightBridge\Service\UserSettingsService::class); $s->setEnabled($argv[1], true); $s->setIncludedPaths($argv[1], ["gtdb"]);' "$GT_USER"
  GT_PASS=$(fast_pass "$GT_USER" "$GT_PASS")
  docker exec "$NC_CONTAINER" rm -rf /tmp/gtdb_src; docker cp "$WORK_DIR/gtdb" "$NC_CONTAINER:/tmp/gtdb_src"
  check "create the folder" 201 "$(dav $GT_USER $GT_PASS MKCOL "files/$GT_USER/gtdb")"
  [ "$FAIL" -eq 0 ] || { summary "clustering against ground truth (setup failed)"; exit 2; }
  n=0; for i in $(seq 1 "$PEOPLE"); do s=$(printf 's%02d' $i); for f in $(docker exec "$NC_CONTAINER" ls /tmp/gtdb_src/$s); do
    dav $GT_USER $GT_PASS PUT "files/$GT_USER/gtdb/${s}_$f" -T "/tmp/gtdb_src/$s/$f" >/dev/null; n=$((n+1)); done; done
  docker exec "$NC_CONTAINER" rm -rf /tmp/gtdb_src
  check "photos uploaded, none empty" "$want" "$(photos)"
  echo "== detection"
  for i in $(seq 1 "$PEOPLE"); do s=$(printf 's%02d' $i); for j in $(seq -w 1 15); do occ facewright:detect "$GT_USER" "gtdb/${s}_$j.jpg" >/dev/null 2>&1; done; done
  mkdir -p "$WORK_DIR/gt-cache"; rm -f "$WORK_DIR"/gt-cache/*.sql
  dump() { docker exec "$DB_CONTAINER" mariadb-dump -u"$DB_USER" -p"$DB_PASS" --single-transaction --no-create-info --skip-add-locks --skip-disable-keys "$DB_NAME" "$1" --where="$2" 2>/dev/null; }
  { dump oc_facewright_faces "id IN (SELECT face_id FROM oc_facewright_user_faces WHERE user_id='$GT_USER')"
    for t in oc_facewright_user_faces oc_facewright_detection_faces oc_facewright_processed; do dump "$t" "user_id='$GT_USER'"; done; } > "$cache"
fi
check "one face found in every photo" "$want" "$(one "SELECT COUNT(*) FROM oc_facewright_user_faces WHERE user_id='$GT_USER'")"

echo "== clustering"
occ facewright:cluster "$GT_USER" | grep -E 'cluster\(s\)|written'
truth --people "$PEOPLE" --each 15; check "$PEOPLE people of 15, pure, complete, same in Recognize's table" 0 "$(st --people $PEOPLE --each 15)"
verify_step "clustering the dataset"

echo "== the oracle must catch mistakes (a checker that cannot fail proves nothing)"
C=($(words "$(ids 3)")); need "three people to play with" "${#C[@]}" 3
A=${C[0]}; B=${C[1]}; F=$(face_of $A)
gt_ocs POST /faces/move "{\"ids\":[$F],\"clusterId\":$B}" >/dev/null
check "a face moved to the wrong person is caught (impure)" 1 "$(st)"
check "   and nothing else is wrong with the tables" 0 "$(occ facewright:own-tables --verify >/dev/null 2>&1; echo $?)"
gt_ocs POST /faces/move "{\"ids\":[$F],\"clusterId\":$A}" >/dev/null
check "moved back: clean again" 0 "$(st --people $PEOPLE --each 15)"
gt_ocs POST /people/merge "{\"keep\":$A,\"merge\":[$B]}" >/dev/null
check "two people merged into one are caught (impure, and the wrong count)" 1 "$(st --people $PEOPLE)"
D=$(one "SELECT id FROM oc_facewright_user_faces WHERE user_id='$GT_USER' AND cluster_id=$A ORDER BY id LIMIT 1")
sql "UPDATE oc_recognize_face_detections SET cluster_id=${C[2]} WHERE id=$D"
check "Recognize's table drifting from ours is caught (agree)" 1 "$(st)"
sql "UPDATE oc_recognize_face_detections SET cluster_id=$A WHERE id=$D"
check "   restored, still impure only by the merge" 1 "$(st --people $PEOPLE)"

F2=$(face_of ${C[2]})
gt_ocs POST /faces/move "{\"ids\":[$F2],\"newTitle\":\"split\"}" >/dev/null
check "an identity split over two people is caught (complete)" 1 "$(st --people $PEOPLE)"
check "   and tolerated when splits are allowed (still wrong count)" 1 "$(st --allow-split --people $PEOPLE)"

echo "== recovery: reset and recluster gives the truth back"
occ facewright:cluster "$GT_USER" --reset | grep -E 'cluster\(s\)|written'
truth --people "$PEOPLE" --each 15; check "$PEOPLE people of 15 again" 0 "$(st --people $PEOPLE --each 15)"
verify_step "reset and recluster"
summary "clustering against ground truth"

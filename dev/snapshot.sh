#!/bin/bash
# Save and restore our own face tables of the dev database. The suites change data on purpose (they delete
# people, reset users, move faces); restoring a snapshot between them is how a run starts from known data.
#
#   dev/snapshot.sh save [name]      dump our tables to dev/.work/snapshots/<name>.sql (default: original)
#   dev/snapshot.sh restore [name]   load it, empty the own copy tables and refill them from ours, verify
#   dev/snapshot.sh list
#
# A snapshot holds OUR tables (faces, user_faces, clusters, cluster_stats, processed, centroids, dismissals, Memories'
# covers), with their AUTO_INCREMENT values, so the id counters come back too. It does not hold the link table,
# Recognize's tables or the own copy (the copy is rebuilt by `occ facewright:project`). A file in the old format
# (Recognize's tables, rebuilt with --migrate) is refused; the last one is original-stock.sql.
# Take the "original" snapshot once, from a dev stack you consider good. Snapshots are local (gitignored): they
# refer to photos that live in the dev volume, so they are not portable to another machine.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
TABLES="oc_facewright_faces oc_facewright_user_faces oc_facewright_clusters oc_facewright_cluster_stats oc_facewright_processed oc_facewright_cluster_centroids oc_facewright_merge_dismissals oc_memories_covers"
name=${2:-original}; file="$WORK_DIR/snapshots/$name.sql"
case "${1:-}" in
  save)
    docker exec "$DB_CONTAINER" mariadb-dump -u"$DB_USER" -p"$DB_PASS" --single-transaction "$DB_NAME" $TABLES 2>/dev/null > "$file"
    echo "saved $name: $(grep -c '^INSERT INTO' "$file") INSERT statements, $(du -h "$file" | cut -f1)" ;;
  restore)
    [ -f "$file" ] || { echo "no snapshot '$name' (dev/snapshot.sh save $name first)"; exit 2; }
    if grep -q 'oc_recognize_face_detections' "$file"; then
      echo "snapshot '$name' is in the old format (it holds Recognize's tables); the old format is original-stock.sql and cannot be restored by this script"; exit 2; fi
    docker exec -i "$DB_CONTAINER" mariadb -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" 2>/dev/null < "$file"
    sql "TRUNCATE oc_facewright_bridge_face_detections; TRUNCATE oc_facewright_bridge_face_clusters; DELETE FROM oc_jobs WHERE class LIKE '%ClusterScanJob'"
    occ config:app:delete facewright_bridge projection_dirty_seq >/dev/null 2>&1; occ config:app:delete facewright_bridge projection_clean_seq >/dev/null 2>&1
    occ config:app:delete facewright_bridge projection_last_error >/dev/null 2>&1
    occ facewright:project >/dev/null || { echo "restored $name but the own tables could not be filled"; exit 1; }
    run_verify; if [ $VRC -eq 0 ]; then echo "restored $name; verify clean"; else echo "restored $name but VERIFY FAILED"; exit 1; fi ;;
  list) ls -l "$WORK_DIR/snapshots" | awk 'NR>1 {printf "%8s  %s\n", $5, $9}' ;;
  *) sed -n '2,12p' "$0"; exit 2 ;;
esac

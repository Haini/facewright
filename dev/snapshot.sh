#!/bin/bash
# Save and restore the face-related tables of the dev database. The suites change data on purpose (they delete
# people, reset users, move faces); restoring a snapshot between them is how a run starts from known data.
#
#   dev/snapshot.sh save [name]      dump the tables to dev/.work/snapshots/<name>.sql (default: original)
#   dev/snapshot.sh restore [name]   load it, empty our own two tables, backfill them, verify, unpause
#   dev/snapshot.sh list
#
# Take the "original" snapshot once, from a dev stack you consider good. Snapshots are local (gitignored): they
# refer to photos that live in the dev volume, so they are not portable to another machine.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
TABLES="oc_recognize_face_detections oc_recognize_face_clusters oc_facewright_faces oc_facewright_detection_faces oc_facewright_processed oc_facewright_cluster_centroids oc_facewright_merge_dismissals oc_memories_covers"
name=${2:-original}; file="$WORK_DIR/snapshots/$name.sql"
case "${1:-}" in
  save)
    docker exec "$DB_CONTAINER" mariadb-dump -u"$DB_USER" -p"$DB_PASS" --single-transaction "$DB_NAME" $TABLES 2>/dev/null > "$file"
    echo "saved $name: $(grep -c '^INSERT INTO' "$file") INSERT statements, $(du -h "$file" | cut -f1)" ;;
  restore)
    [ -f "$file" ] || { echo "no snapshot '$name' (dev/snapshot.sh save $name first)"; exit 2; }
    docker exec -i "$DB_CONTAINER" mariadb -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" 2>/dev/null < "$file"
    sql "TRUNCATE oc_facewright_user_faces; TRUNCATE oc_facewright_clusters; DELETE FROM oc_jobs WHERE class LIKE '%ClusterScanJob'"
    occ config:app:set facewright_bridge processing_paused --value=true >/dev/null
    occ facewright:own-tables --migrate --chunk=100000 2>&1 | grep -E 'Total|people:'
    occ config:app:delete facewright_bridge processing_paused >/dev/null
    occ config:app:delete facewright_bridge own_tables_after_id >/dev/null 2>&1
    if occ facewright:own-tables --verify >/dev/null; then echo "restored $name; verify clean"; else echo "restored $name but VERIFY FAILED"; exit 1; fi ;;
  list) ls -l "$WORK_DIR/snapshots" | awk 'NR>1 {printf "%8s  %s\n", $5, $9}' ;;
  *) sed -n '2,12p' "$0"; exit 2 ;;
esac

#!/bin/bash
# The direct-call harness over the access-sync, detection and reset readers (reads_direct.php), as a capture and a
# compare. Read-only. USERS (comma list) and SAMPLE (files per group) can be set; to point it at another instance
# override NC_CONTAINER and USERS (see live-capture.sh).
#
#   dev/reads-direct.sh capture OUT.json | compare A.json B.json | stable
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
: "${USERS:=$ADMIN_USER,$BOB_USER}"
capture() {
  docker cp "$DEV_DIR/reads_direct.php" "$NC_CONTAINER:/tmp/reads_direct.php" >/dev/null
  docker exec -u www-data -e USERS="$USERS" -e SAMPLE="${SAMPLE:-150}" "$NC_CONTAINER" php /tmp/reads_direct.php > "$1" 2> "$1.err"
  local rc=$?; docker exec "$NC_CONTAINER" rm -f /tmp/reads_direct.php
  [ -s "$1.err" ] && head -c 400 "$1.err"; rm -f "$1.err"
  [ $rc -eq 0 ] && python3 -c "import json,sys; d=json.load(open('$1')); print('captured', len(d['files']), 'files;', d['sample']['shared'], 'shared')"
}
case "${1:-}" in
  capture) capture "${2:?usage: reads-direct.sh capture OUT.json}" ;;
  compare) python3 "$DEV_DIR/compare_direct.py" "${2:?A.json}" "${3:?B.json}" ;;
  stable)  capture "$WORK_DIR/direct-a.json" >/dev/null && capture "$WORK_DIR/direct-b.json" >/dev/null && \
           python3 "$DEV_DIR/compare_direct.py" "$WORK_DIR/direct-a.json" "$WORK_DIR/direct-b.json" && echo "STABLE: two captures identical" ;;
  *) sed -n '2,8p' "$0"; exit 2 ;;
esac

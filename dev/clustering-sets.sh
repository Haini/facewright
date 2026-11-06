#!/bin/bash
# Does a change to clustering change who ends up with whom? Reset and recluster the two users and save the member
# sets (independent of numbering), then compare a save from the old code with one from the new, from the SAME
# restored data. Clustering is deterministic, so equal input must give equal sets.
#   dev/snapshot.sh restore; dev/clustering-sets.sh save .work/sets-old.json   (old code)
#   dev/snapshot.sh restore; dev/clustering-sets.sh save .work/sets-new.json   (new code)
#   dev/clustering-sets.sh compare .work/sets-old.json .work/sets-new.json
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
case "${1:-}" in
  save)
    out=${2:?usage: clustering-sets.sh save OUT.json}
    occ facewright:cluster "$ADMIN_USER" --reset --min-faces=3 | grep -E 'cluster\(s\)|written'
    occ facewright:cluster "$BOB_USER" --reset --min-faces=1 | grep -E 'cluster\(s\)|written'
    DB_CONTAINER=$DB_CONTAINER DB_USER=$DB_USER DB_PASS=$DB_PASS DB_NAME=$DB_NAME python3 "$DEV_DIR/members.py" "$out" "$ADMIN_USER,$BOB_USER" ;;
  compare)
    python3 - "${2:?A.json}" "${3:?B.json}" <<'PY'
import json, sys
a, b = json.load(open(sys.argv[1])), json.load(open(sys.argv[2])); bad = False
for u in a:
    same_p, same_o = a[u]["people"] == b[u]["people"], a[u]["other"] == b[u]["other"]
    print(f"{u}: people identical={same_p} ({len(a[u]['people'])} vs {len(b[u]['people'])}); other states identical={same_o}")
    bad |= not (same_p and same_o)
sys.exit(1 if bad else 0)
PY
    ;;
  *) sed -n '2,9p' "$0"; exit 2 ;;
esac

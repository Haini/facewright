#!/bin/bash
# Two traps in our own tables, the source of truth: a share copy repeated after a crash (the rows exist, the marker
# does not) must be harmless, and FaceStore must refuse a person on a state that has none.
# Changes data: restore a snapshot afterwards.   dev/own-traps.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
u=$ADMIN_USER
echo "== a copy to someone who already has the rows"
docker cp "$DEV_DIR/own_traps.php" "$NC_CONTAINER:/tmp/own_traps.php" >/dev/null
out=$(docker exec -u www-data -e U1="$u" -e U2="$BOB_USER" "$NC_CONTAINER" php /tmp/own_traps.php 2>&1 | tail -1); docker exec "$NC_CONTAINER" rm -f /tmp/own_traps.php
echo "  $out"
f() { python3 -c "import json,sys; print(json.loads(sys.argv[1]).get(sys.argv[2]))" "$out" "$1" 2>/dev/null; }
check "the first copy wrote rows" True "$(python3 -c "import json,sys; print(json.loads(sys.argv[1])['first']>0)" "$out" 2>/dev/null)"
check "the second copy writes none and does not throw" 0 "$(f second)"
check "the marker is back after it (the crash this heals)" 1 "$(f marked)"
check "no duplicate rows" 0 "$(f dupes)"
echo "== FaceStore refuses a person on a state that has none"
check "setState(UNASSIGNED, person) throws" 1 "$(f refused)"
verify_step "after all of it"
summary "own-tables traps"

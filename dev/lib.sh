#!/bin/bash
# Shared by every script in dev/. Source it; do not run it.
#
# Everything here talks to the compose dev stack through `docker exec`. Names and passwords default to the
# ones in docker-compose.yml and can be overridden from the environment (NC_CONTAINER, DB_CONTAINER, ...).
# To use the read-only scripts against another instance, override the containers; see README.md.
#
# Written for bash. Note that zsh does not split unquoted variables: ids are passed around as comma lists
# and split explicitly with `words`.

DEV_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(dirname "$DEV_DIR")"

: "${NC_CONTAINER:=recognaize-nextcloud-1}"
: "${DB_CONTAINER:=recognaize-db-1}"
: "${DB_USER:=nextcloud}"
: "${DB_PASS:=dev-nextcloud-password}"
: "${DB_NAME:=nextcloud}"
: "${ADMIN_USER:=admin}"
: "${ADMIN_PASS:=dev-admin-password}"
: "${BOB_USER:=bob}"
: "${BOB_PASS:=dev-bob-password-123}"
: "${WORK_DIR:=$DEV_DIR/.work}"
mkdir -p "$WORK_DIR/snapshots"

# Inside the Nextcloud container; the app answers on localhost there.
OCS_BASE="http://localhost/ocs/v2.php/apps/facewright_bridge/api/v1"
DAV_BASE="http://localhost/remote.php/dav"

occ() { docker exec -u www-data "$NC_CONTAINER" php occ "$@"; }

# SQL: rows tab-separated without a header (sql), or with one (sqlh); `one` is the first value; `csv` one column
# as a comma list. Warnings about passing the password on the command line are dropped.
sql()  { docker exec "$DB_CONTAINER" mariadb -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -N -e "$1" 2>/dev/null; }
sqlh() { docker exec "$DB_CONTAINER" mariadb -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "$1" 2>/dev/null; }
one()  { sql "$1" | head -1; }
csv()  { sql "$1" | paste -sd, -; }
words() { tr ',' ' ' <<<"$1"; }

# People API: ocs USER PASS METHOD PATH [JSON BODY]  -> response body
ocs() {
  local u=$1 p=$2 m=$3 req=$4 body=${5:-} sep='?'
  [[ "$req" == *\?* ]] && sep='&'
  if [ -n "$body" ]; then
    docker exec "$NC_CONTAINER" curl -s -u "$u:$p" -H 'OCS-APIRequest: true' -H 'Accept: application/json' \
      -H 'Content-Type: application/json' -X "$m" -d "$body" "$OCS_BASE$req${sep}format=json"
  else
    docker exec "$NC_CONTAINER" curl -s -u "$u:$p" -H 'OCS-APIRequest: true' -H 'Accept: application/json' \
      -X "$m" "$OCS_BASE$req${sep}format=json"
  fi
}
admin_ocs() { ocs "$ADMIN_USER" "$ADMIN_PASS" "$@"; }
bob_ocs()   { ocs "$BOB_USER" "$BOB_PASS" "$@"; }

# WebDAV: dav USER PASS METHOD RELATIVE-URL [curl args...]  -> HTTP status
dav() {
  local u=$1 p=$2 m=$3 url=$4; shift 4
  docker exec "$NC_CONTAINER" curl -s -o /dev/null -w '%{http_code}' -u "$u:$p" -X "$m" "$@" "$DAV_BASE/$url"
}
admin_dav() { dav "$ADMIN_USER" "$ADMIN_PASS" "$@"; }
bob_dav()   { dav "$BOB_USER" "$BOB_PASS" "$@"; }

# A face crop: crop USER PASS FACE_ID -> HTTP status
crop() { docker exec "$NC_CONTAINER" curl -s -o /dev/null -w '%{http_code}' -u "$1:$2" "http://localhost/apps/facewright_bridge/faces/$3/preview?size=64"; }

# --- results -----------------------------------------------------------------------------------------------
PASS=0; FAIL=0
check() {  # check LABEL EXPECTED ACTUAL
  if [ "$2" = "$3" ]; then PASS=$((PASS+1)); printf '  ok    %-54s %s\n' "$1" "$3"
  else FAIL=$((FAIL+1)); printf '  FAIL  %-54s expected [%s] got [%s]\n' "$1" "$2" "$3"; fi
}
# A test that needs data must say so, and stop (exit 2) instead of passing without testing anything.
need() {   # need LABEL ACTUAL MINIMUM
  if [ "${2:-0}" -ge "$3" ] 2>/dev/null; then printf '  have  %-54s %s (need %s)\n' "$1" "$2" "$3"
  else printf '  MISSING PRECONDITION: %s: have %s, need %s. Run dev/check-fixture.sh.\n' "$1" "${2:-none}" "$3"; exit 2; fi
}
verify_step() {  # verify_step LABEL : the invariant checker after a step
  local out; out=$(occ facewright:own-tables --verify 2>&1); local rc=$?
  if [ $rc -eq 0 ]; then PASS=$((PASS+1)); printf '  ok    verify after %s\n' "$1"
  else FAIL=$((FAIL+1)); printf '  FAIL  verify after %s\n' "$1"; echo "$out" | grep -E 'must|failed' | sed 's/^/        /'; fi
}
summary() {  # summary NAME : prints the totals; returns non-zero if anything failed
  echo; echo "$1: passed $PASS, failed $FAIL"; [ "$FAIL" -eq 0 ]
}

# The test photo must contain a real face the detector will find; see make-portrait.py. It is copied into the
# Nextcloud container once.
ensure_portrait() {
  if docker exec "$NC_CONTAINER" test -f /tmp/portrait.jpg; then return 0; fi
  if [ ! -f "$WORK_DIR/portrait.jpg" ]; then
    echo "  MISSING PRECONDITION: no test photo. Run: python3 dev/make-portrait.py (see dev/README.md)"; exit 2; fi
  docker cp "$WORK_DIR/portrait.jpg" "$NC_CONTAINER:/tmp/portrait.jpg"
}

# The DAV battery talks to Recognize's tree, whose own API-key check would refuse a plain curl.
api_key_off() { occ config:app:set recognize require_api_key --value=false >/dev/null; sleep 6; }
api_key_on()  { occ config:app:delete recognize require_api_key >/dev/null 2>&1; }

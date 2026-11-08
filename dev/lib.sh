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

# An app password for USER, made once and kept in .work/tokens. Nextcloud checks a real password against its Argon2id
# hash on every Basic-auth request (0.37 s of CPU each, whatever the parallelism) and an app password by a plain hash
# lookup (0.04 s), which makes a suite about ten times faster with the same requests. Falls back to the password, so a
# missing container or a failed `occ` costs speed, not a false result. The Memories UI flows log in with the real one.
fast_pass() {  # fast_pass USER PASSWORD
  local u=$1 p=$2 f="$WORK_DIR/tokens/$1" t=""
  [ -f "$f" ] && t=$(cat "$f")
  if [ -n "$t" ] && [ "$(docker exec "$NC_CONTAINER" curl -s -o /dev/null -w '%{http_code}' -u "$u:$t" -H 'OCS-APIRequest: true' http://localhost/ocs/v2.php/cloud/user 2>/dev/null)" = 200 ]; then
    echo "$t"; return
  fi
  t=$(docker exec -u www-data -e NC_PASS="$p" "$NC_CONTAINER" php occ user:auth-tokens:add --password-from-env --name=dev-suite "$u" 2>/dev/null | grep -oE '[A-Za-z0-9]{40,}' | head -1)
  if [ -n "$t" ]; then mkdir -p "$WORK_DIR/tokens"; echo "$t" > "$f"; echo "$t"; else echo "$p"; fi
}
ADMIN_PASS=$(fast_pass "$ADMIN_USER" "$ADMIN_PASS")
BOB_PASS=$(fast_pass "$BOB_USER" "$BOB_PASS")

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

# Restart the web server and wait until it answers. The web process revalidates its compiled PHP only every 60 s
# (opcache.revalidate_freq), so a test that edits a source file must restart it, or it runs against the old code.
reload_web() {
  docker exec "$NC_CONTAINER" apache2ctl -k restart >/dev/null 2>&1
  local i; for i in $(seq 1 30); do
    [ "$(docker exec "$NC_CONTAINER" curl -s -o /dev/null -w '%{http_code}' http://localhost/status.php)" = 200 ] && return 0; sleep 1
  done
  echo "web server did not come back"; return 1
}

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
# Runs a PHP check script inside the Nextcloud container and counts its results. The script prints
# "check <label> <expected> <actual>" (three JSON values) and "info <text>" lines; anything else is a failure.
php_checks() {  # php_checks SCRIPT.php [ARGS...]
  local script=$1; shift
  docker cp "$DEV_DIR/$script" "$NC_CONTAINER:/tmp/$script" >/dev/null
  local kind rest
  while read -r kind rest; do
    case $kind in
      info) echo "  $rest" ;;
      check) if python3 - "$rest" <<'PY'
import json, sys
dec = json.JSONDecoder(); s = sys.argv[1]; vals = []; i = 0
while i < len(s):
    if s[i] == " ": i += 1; continue
    v, i = dec.raw_decode(s, i); vals.append(v)
label, expected, actual = vals
ok = expected == actual
print(f"  {'ok  ' if ok else 'FAIL'}  {label}" + ("" if ok else f": expected {str(expected)[:200]}, got {str(actual)[:200]}"))
sys.exit(0 if ok else 1)
PY
        then PASS=$((PASS + 1)); else FAIL=$((FAIL + 1)); fi ;;
      *) echo "  ??    $kind $rest"; FAIL=$((FAIL + 1)) ;;
    esac
  done < <(docker exec -u www-data "$NC_CONTAINER" php "/tmp/$script" "$@" 2>&1)
  docker exec "$NC_CONTAINER" rm -f "/tmp/$script"
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

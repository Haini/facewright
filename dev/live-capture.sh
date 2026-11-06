#!/bin/bash
# Read-only capture of what the People code reads on ANOTHER instance (live), to diff before and after a deploy.
# Every command is a listing or a --dry-run. Nothing is written, and no names are stored in this repo: pass the
# users on the command line.
#
#   NC_CONTAINER=<app container> dev/live-capture.sh OUT_DIR user1,user2
#   (run on the old code, run again to prove it is deterministic, deploy, run a third time, diff -r)
# Pause processing first: a running detection or clustering job changes the numbers between captures.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
out=${1:?usage: live-capture.sh OUT_DIR user1,user2}; users=${2:?usage: live-capture.sh OUT_DIR user1,user2}
rm -rf "$out"; mkdir -p "$out"
for u in $(words "$users"); do
  occ facewright:people "$u"                              > "$out/people-$u.txt" 2>&1
  occ facewright:people "$u" --unassigned                 > "$out/unassigned-$u.txt" 2>&1
  occ facewright:people "$u" --ignored                    > "$out/ignored-$u.txt" 2>&1
  occ facewright:people "$u" --delete-empty --dry-run     > "$out/empty-$u.txt" 2>&1
done
occ facewright:reset --dry-run                            > "$out/reset-dry.txt" 2>&1
occ facewright:reopen-unassigned --dry-run                > "$out/reopen-dry.txt" 2>&1
USERS=$users "$DEV_DIR/reads-direct.sh" capture "$out/direct.json" > "$out/direct.log"
bad=0
for u in $(words "$users"); do   # an unknown user prints nothing and no error, so an empty people list is the signal
  [ -s "$out/people-$u.txt" ] || { echo "WARNING: no people listed for '$u' (unknown user, or none yet)"; bad=1; }
done
grep -liE 'exception|error|could not' "$out"/*.txt 2>/dev/null | sed 's/^/WARNING: errors in /' | grep . && bad=1
echo "captured $(ls "$out" | wc -l) files ($(du -sh "$out" | cut -f1))"
exit $bad

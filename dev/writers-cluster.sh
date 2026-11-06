#!/bin/bash
# The clustering writers: reopen-unassigned, a pass that assigns, a pass that assigns nothing (marks faces
# unclusterable), clearing those marks, and a full reset for a small and a large user. Each step checks that
# something actually changed, because a step with nothing to do passes without testing anything. Changes data:
# restore a snapshot afterwards.   dev/writers-cluster.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
u=$ADMIN_USER
n() { one "SELECT COUNT(*) FROM oc_facewright_user_faces WHERE user_id='$1' AND state=$2"; }
dist() { echo "pending=$(n $1 0) assigned=$(n $1 1) unassigned=$(n $1 2) unclusterable=$(n $1 3) ignored=$(n $1 4)"; }

echo "== data"
unas=$(n $u 2); need "$u: unassigned faces to reopen" "$unas" 1
echo "  $u: $(dist $u)"; echo "  $BOB_USER: $(dist $BOB_USER)"
verify_step "baseline"

echo "== reopen-unassigned"
check "reopened exactly the unassigned faces" "$unas" "$(occ facewright:reopen-unassigned --user=$u | grep -o 'Reopened [0-9]*' | grep -o '[0-9]*')"
check "   they are pending now" "$unas" "$(n $u 0)"
check "   none unassigned left" 0 "$(n $u 2)"
verify_step "reopen-unassigned"

echo "== a pass that assigns"
pend=$(n $u 0)
occ facewright:cluster "$u" --min-faces=3 | grep -E 'cluster\(s\)|written'
check "   fewer faces pending than before" yes "$([ "$(n $u 0)" -lt "$pend" ] && echo yes || echo no)"
verify_step "cluster (assigns)"

echo "== a pass that assigns nothing marks the rest unclusterable"
left=$(n $u 0); need "faces still pending after the first pass" "$left" 1
occ facewright:cluster "$u" --min-faces=3 | grep -E 'Marked|written'
check "   none pending, all marked unclusterable" 0 "$(n $u 0)"
verify_step "cluster (marks)"

echo "== clearing the marks (what a threshold change does)"
marked=$(n $u 3); need "unclusterable faces to reopen" "$marked" 1
reopened=$(docker exec -u www-data "$NC_CONTAINER" php -r 'define("OC_CONSOLE",1); require_once "/var/www/html/lib/base.php"; echo \OCP\Server::get(\OCA\FacewrightBridge\Service\ClusteringService::class)->clearUnclusterableMarks($argv[1]);' "$u")
check "reopened every unclusterable face" "$marked" "$reopened"
check "   none unclusterable left" 0 "$(n $u 3)"
verify_step "clearUnclusterableMarks"

echo "== full resets"
sb=$(n $BOB_USER 1); need "$BOB_USER: assigned faces" "$sb" 1
occ facewright:cluster "$BOB_USER" --reset --min-faces=1 | grep -E 'cluster\(s\)|written'
check "$BOB_USER: everything pending again, then assigned" 0 "$(n $BOB_USER 0)"
verify_step "cluster bob --reset"
ign=$(n $u 4)
occ facewright:cluster "$u" --reset --min-faces=3 | grep -E 'cluster\(s\)|written'
check "$u: ignored faces survive a reset" "$ign" "$(n $u 4)"
verify_step "cluster admin --reset"
echo "  $u: $(dist $u)"
summary "clustering writers"

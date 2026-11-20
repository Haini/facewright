#!/bin/bash
# Swap the Memories app folder in the dev container between the App Store build and the fork's build
# (docs/memories-fork.md, M4 and M9).
#
#   dev/fork-swap.sh save-store OUT.tar     keep the installed folder as a tar (ownership kept); do this FIRST, on the store build
#   dev/fork-swap.sh fork FORK.tar.gz       replace the folder with the fork's build and pin it (an empty .git/)
#   dev/fork-swap.sh store STORE.tar        replace the folder with the saved store build (no .git/, so updates are offered again)
#   dev/fork-swap.sh status                 what is deployed: version, whether it is pinned, update offers
#
# The version is the same on both sides, so no `occ upgrade` is needed. The web server is restarted so no opcache
# keeps the old code. The folder is removed and unpacked as www-data, so it ends up owned like any app.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
APPS=/var/www/html/custom_apps
nc() { docker exec -u www-data "$NC_CONTAINER" "$@"; }

case "${1:-}" in
  save-store)
    [ -n "${2:-}" ] || { sed -n '2,9p' "$0"; exit 2; }
    nc test ! -e "$APPS/memories/.git" || { echo "refusing: the deployed folder is pinned, so it is not the store build"; exit 1; }
    nc test ! -e "$APPS/memories/appinfo/signature.json" || true
    docker exec "$NC_CONTAINER" tar -C "$APPS" -cf - memories > "$2"
    echo "saved $(tar -tf "$2" | wc -l) entries to $2" ;;
  fork)
    [ -f "${2:-}" ] || { echo "no such tarball: ${2:-}"; exit 2; }
    tar -tzf "$2" | grep -q '^memories/appinfo/info.xml$' || { echo "$2 has no memories/appinfo/info.xml"; exit 2; }
    if tar -tzf "$2" | grep -q '^memories/appinfo/signature.json$'; then echo "refusing: $2 still carries the store's signature.json"; exit 1; fi
    nc rm -rf "$APPS/memories"
    docker exec -i -u www-data "$NC_CONTAINER" tar -C "$APPS" --no-same-owner -xzf - < "$2"
    nc mkdir "$APPS/memories/.git"
    reload_web ;;
  store)
    [ -f "${2:-}" ] || { echo "no such tar: ${2:-}"; exit 2; }
    nc rm -rf "$APPS/memories"
    docker exec -i -u www-data "$NC_CONTAINER" tar -C "$APPS" --no-same-owner -xf - < "$2"
    reload_web ;;
  status)
    occ app:list 2>&1 | grep -E ' memories:'
    if nc test -e "$APPS/memories/.git"; then echo "pinned (.git/ present)"; else echo "not pinned"; fi
    if nc test -e "$APPS/memories/appinfo/signature.json"; then echo "signature.json: present"; else echo "signature.json: absent"; fi
    occ app:update --showonly 2>&1 | tail -2 ;;
  *) sed -n '2,9p' "$0"; exit 2 ;;
esac

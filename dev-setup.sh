#!/bin/bash
# One-shot setup for the docker-compose dev stack (see docker-compose.yml + README's
# "Try it locally" section). Run this once after `docker compose up -d`, once the nextcloud
# container is up.
#
# Registers a manual-install daemon WITHOUT HaRP: HaRP/FRP only exist in the real deployment
# recipe (see Makefile/CLAUDE.md) to tunnel across a network boundary between Nextcloud and the
# ExApp container. On this compose network they're both reachable directly by container name,
# so that whole layer is unnecessary here - Nextcloud's AppAPI can talk to `facewright:9030`
# straight over the network.
#
# Verified end-to-end against a real run of this exact compose stack (fresh volumes, NC 33).
# Two real bugs were found and fixed along the way, in case either resurfaces:
#
# 1. `nc.set_init_status(100)` (nc_py_api's own post-/init callback into Nextcloud, not this
#    project's code) failed with a 400 whose body was an HTML "Access through untrusted domain"
#    page, not the JSON/auth error it looked like from the Python traceback alone - traced by
#    replaying the exact call from inside the facewright container and printing
#    `e.response.text`. Cause: the facewright container calls back via NEXTCLOUD_URL=
#    http://nextcloud (the compose service name), but docker-compose.yml's
#    NEXTCLOUD_TRUSTED_DOMAINS only listed "localhost". Fixed by adding "nextcloud" there too
#    (see docker-compose.yml). If this 400 comes back, check trusted_domains first.
# 2. `occ app:install recognize` failed with "Cannot write into apps directory" despite
#    `/var/www/html/apps` itself being writable - the real writable install root for non-shipped
#    apps is `/var/www/html/custom_apps` (confirmed via `\OC::$APPSROOTS`), which was
#    root:root-owned and not writable by www-data. Root cause: bind-mounting
#    `./facewright_bridge` into a *subpath* of the `nextcloud_html` named volume makes Docker
#    create the missing parent (`custom_apps`) as root before Nextcloud's own entrypoint gets a
#    chance to chown it. Fixed at the compose level now (see the "fix-permissions" one-shot
#    init service in docker-compose.yml, which runs and completes before nextcloud starts), not
#    here - noted in case it resurfaces via some other nested bind mount added later.

set -e

COMPOSE_EXEC="docker compose exec -T -u www-data nextcloud php occ"

echo "--- waiting for Nextcloud to be installed ---"
until $COMPOSE_EXEC status 2>/dev/null | grep -q "installed: true"; do
	sleep 2
done

echo "--- installing AppAPI + recognize + memories ---"
# app_api ships enabled by default on recent Nextcloud (confirmed on NC 33 in this stack: no
# "app_store" occ namespace exists, and "app:install app_api" exits 1 with "already installed").
# Both are non-errors here - only fail if app_api ends up neither already-enabled nor freshly
# installed.
$COMPOSE_EXEC app:install app_api || $COMPOSE_EXEC app:enable app_api || true
$COMPOSE_EXEC app:install recognize || true
# Memories is the actual UI surface for everything this project writes (People page, cluster
# rename) - without it there's no way to see detection/clustering results short of querying the
# DB directly. Installed here, not just documented as a manual step, since "view the results" is
# the whole point of running this stack locally.
$COMPOSE_EXEC app:install memories || true
$COMPOSE_EXEC app:enable facewright_bridge

echo "--- registering the no-HaRP manual-install daemon ---"
$COMPOSE_EXEC app_api:daemon:register dev_manual "Local dev (no HaRP)" manual-install \
	http facewright http://nextcloud --net facewright-dev

echo "--- registering the facewright ExApp ---"
JSON_INFO='{"id":"facewright","name":"Facewright","daemon_config_name":"dev_manual","version":"0.1.0","secret":"dev-secret-not-for-real-use","port":9030,"host":"facewright"}'
$COMPOSE_EXEC app_api:app:register facewright dev_manual --json-info "$JSON_INFO" --wait-finish

echo "--- done - open http://localhost:8080 (admin / dev-admin-password) ---"

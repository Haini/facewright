.DEFAULT_GOAL := help

# Deployment-specific values (your Nextcloud URL, HaRP cert path, a real APP_SECRET, etc.) live
# in deploy.env, which is gitignored - copy deploy.env.example to deploy.env and fill it in.
# Targets that actually need those values enforce that via check-nc-url/check-harp-certs below,
# so `make help`/`make build`/the `dev-*` local-compose targets work with no deploy.env at all.
-include deploy.env

APP_ID := facewright
APP_NAME := Facewright
APP_VERSION := 0.1.0
# Must fall in one of HaRP's frps allowPorts ranges (see /run/harp/frps.toml inside the
# appapi-harp container) - check yours before assuming a value is free (ranges/usage vary by
# instance and change over time).
APP_PORT ?= 23001

# Sensible defaults for a typical AppAPI + HaRP manual-install setup (see CLAUDE.md's
# "Deployment and operations" section) - override
# in deploy.env if yours differ.
NC_CONTAINER ?= nextcloud-app-1
DAEMON_NAME ?= manual_install_harp
DOCKER_NETWORK ?= gateway
HARP_HOST ?= appapi-harp:8780
HARP_FRP_ADDRESS ?= appapi-harp:8782

# No sane default for these two - they're specific to your instance. Required by the targets
# that use them (see check-nc-url/check-harp-certs).
NC_URL ?=
HARP_CERTS_DIR ?=

# Must match the harp container's own HP_SHARED_KEY env var - fetch it rather than hardcoding:
#   docker exec appapi-harp env | grep HP_SHARED_KEY
HARP_SHARED_KEY ?= $(shell docker exec appapi-harp printenv HP_SHARED_KEY 2>/dev/null)
# Regenerate for real use: openssl rand -hex 24. Do not commit a real secret to this file.
APP_SECRET ?= changeme-generate-your-own

JSON_INFO := "{\"id\":\"$(APP_ID)\",\"name\":\"$(APP_NAME)\",\"daemon_config_name\":\"$(DAEMON_NAME)\",\"version\":\"$(APP_VERSION)\",\"secret\":\"$(APP_SECRET)\",\"port\":$(APP_PORT)}"

.PHONY: check-nc-url
check-nc-url:
	@test -n "$(NC_URL)" || { echo "NC_URL is not set. Copy deploy.env.example to deploy.env and fill in your Nextcloud instance's URL." >&2; exit 1; }

.PHONY: check-harp-certs
check-harp-certs:
	@test -n "$(HARP_CERTS_DIR)" || { echo "HARP_CERTS_DIR is not set. Copy deploy.env.example to deploy.env and fill in the path to HaRP's FRP client certs." >&2; exit 1; }

.PHONY: help
help:
	@echo "  Welcome to $(APP_NAME) $(APP_VERSION)!"
	@echo " "
	@echo "  build             builds the local CPU-only image"
	@echo " "
	@echo "  > One-time setup on this Nextcloud instance:"
	@echo "  daemon-register   registers the '$(DAEMON_NAME)' manual-install daemon config"
	@echo " "
	@echo "  > Per-deploy (in order):"
	@echo "  run               runs the facewright container, tunneling through HaRP"
	@echo "  register          registers/updates the running ExApp with AppAPI"
	@echo "  unregister        unregisters the ExApp from AppAPI"
	@echo " "
	@echo "  > Troubleshooting:"
	@echo "  bust-cache        evicts HaRP's cached host/port record for this app (see CLAUDE.md -"
	@echo "                    HaRP caches ExApp records forever until explicitly told otherwise;"
	@echo "                    re-run this after re-registering with a different port)"
	@echo "  verify            hits /heartbeat and /detect through the real Nextcloud/HaRP path"

.PHONY: build
build:
	docker build --tag facewright:latest .

.PHONY: daemon-register
daemon-register: check-nc-url
	docker exec -u www-data $(NC_CONTAINER) php occ app_api:daemon:register \
		$(DAEMON_NAME) "Harp Manual Install" manual-install http \
		$(HARP_HOST) $(NC_URL) \
		--net $(DOCKER_NETWORK) --harp \
		--harp_frp_address $(HARP_FRP_ADDRESS) \
		--harp_shared_key "$(HARP_SHARED_KEY)"

.PHONY: run
run: check-nc-url check-harp-certs
	docker rm -f facewright 2>/dev/null || true
	docker run -d --name facewright --network $(DOCKER_NETWORK) --restart unless-stopped \
		-e APP_ID=$(APP_ID) \
		-e APP_VERSION=$(APP_VERSION) \
		-e APP_SECRET=$(APP_SECRET) \
		-e APP_PORT=$(APP_PORT) \
		-e NEXTCLOUD_URL=$(NC_URL) \
		-e HP_SHARED_KEY=$(HARP_SHARED_KEY) \
		-e HP_FRP_ADDRESS=appapi-harp \
		-e HP_FRP_PORT=8782 \
		-v $(HARP_CERTS_DIR):/certs/frp:ro \
		facewright:latest

.PHONY: register
register:
	docker exec -u www-data $(NC_CONTAINER) php occ app_api:app:unregister $(APP_ID) --silent --force || true
	docker exec -u www-data $(NC_CONTAINER) php occ app_api:app:register $(APP_ID) $(DAEMON_NAME) --json-info $(JSON_INFO) --wait-finish

.PHONY: unregister
unregister:
	docker exec -u www-data $(NC_CONTAINER) php occ app_api:app:unregister $(APP_ID) --silent --force

.PHONY: bust-cache
bust-cache:
	docker exec appapi-harp curl -s -X DELETE -o /dev/null -w "DELETE_STATUS:%{http_code}\n" \
		"http://127.0.0.1:8200/exapp_storage/$(APP_ID)"

.PHONY: verify
verify: check-nc-url
	@AUTH=$$(printf ':%s' "$(APP_SECRET)" | base64 -w0); \
	echo "--- heartbeat ---"; \
	docker exec $(NC_CONTAINER) curl -s -o /dev/null -w "HTTP_STATUS:%{http_code}\n" \
		-H "AA-VERSION: 32" -H "EX-APP-ID: $(APP_ID)" -H "EX-APP-VERSION: $(APP_VERSION)" \
		-H "AUTHORIZATION-APP-API: $$AUTH" -H "harp-shared-key: $(HARP_SHARED_KEY)" \
		-H "ex-app-port: $(APP_PORT)" -H "ex-app-host: 127.0.0.1" \
		"$(NC_URL)/exapps/$(APP_ID)/heartbeat"

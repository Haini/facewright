.DEFAULT_GOAL := help

APP_ID := recognaize
APP_NAME := recognAIze
APP_VERSION := 0.1.0
APP_PORT := 9030

# This homelab's live AppAPI setup (see CLAUDE.md for how these were discovered):
NC_CONTAINER := nextcloud-app-1
NC_URL := https://cloud.example.com
DAEMON_NAME := manual_install_harp
DOCKER_NETWORK := gateway
HARP_HOST := appapi-harp:8780
HARP_FRP_ADDRESS := appapi-harp:8782
# Must match the harp container's own HP_SHARED_KEY env var - fetch it rather than hardcoding:
#   docker exec appapi-harp env | grep HP_SHARED_KEY
HARP_SHARED_KEY ?= $(shell docker exec appapi-harp printenv HP_SHARED_KEY)
# Regenerate for real use: openssl rand -hex 24. Do not commit a real secret to this file.
APP_SECRET ?= changeme-generate-your-own

JSON_INFO := "{\"id\":\"$(APP_ID)\",\"name\":\"$(APP_NAME)\",\"daemon_config_name\":\"$(DAEMON_NAME)\",\"version\":\"$(APP_VERSION)\",\"secret\":\"$(APP_SECRET)\",\"port\":$(APP_PORT)}"


.PHONY: help
help:
	@echo "  Welcome to $(APP_NAME) $(APP_VERSION)!"
	@echo " "
	@echo "  build             builds the local CPU-only image"
	@echo " "
	@echo "  > One-time setup on this Nextcloud instance:"
	@echo "  daemon-register   registers the '$(DAEMON_NAME)' manual-install daemon config"
	@echo " "
	@echo "  > Per-deploy (container must already be running via 'make run'):"
	@echo "  run               runs the recognaize container, tunneling through HaRP"
	@echo "  register          registers/updates the running ExApp with AppAPI"
	@echo "  unregister        unregisters the ExApp from AppAPI"

.PHONY: build
build:
	docker build --tag recognaize:latest .

.PHONY: daemon-register
daemon-register:
	docker exec -u www-data $(NC_CONTAINER) php occ app_api:daemon:register \
		$(DAEMON_NAME) "Harp Manual Install" manual-install http \
		$(HARP_HOST) $(NC_URL) \
		--net $(DOCKER_NETWORK) --harp \
		--harp_frp_address $(HARP_FRP_ADDRESS) \
		--harp_shared_key "$(HARP_SHARED_KEY)"

.PHONY: run
run:
	docker rm -f recognaize 2>/dev/null || true
	docker run -d --name recognaize --network $(DOCKER_NETWORK) \
		-e APP_ID=$(APP_ID) \
		-e APP_VERSION=$(APP_VERSION) \
		-e APP_SECRET=$(APP_SECRET) \
		-e APP_PORT=$(APP_PORT) \
		-e NEXTCLOUD_URL=$(NC_URL) \
		-e HP_SHARED_KEY=$(HARP_SHARED_KEY) \
		-e HP_FRP_ADDRESS=appapi-harp \
		-e HP_FRP_PORT=8782 \
		recognaize:latest

.PHONY: register
register:
	docker exec -u www-data $(NC_CONTAINER) php occ app_api:app:unregister $(APP_ID) --silent --force || true
	docker exec -u www-data $(NC_CONTAINER) php occ app_api:app:register $(APP_ID) $(DAEMON_NAME) --json-info $(JSON_INFO) --wait-finish

.PHONY: unregister
unregister:
	docker exec -u www-data $(NC_CONTAINER) php occ app_api:app:unregister $(APP_ID) --silent --force

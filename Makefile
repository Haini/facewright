.DEFAULT_GOAL := help

APP_ID := recognaize
APP_NAME := recognAIze
APP_VERSION := 0.1.0
JSON_INFO := "{\"id\":\"$(APP_ID)\",\"name\":\"$(APP_NAME)\",\"daemon_config_name\":\"manual_install\",\"version\":\"$(APP_VERSION)\",\"secret\":\"12345\",\"port\":9030}"


.PHONY: help
help:
	@echo "  Welcome to $(APP_NAME) $(APP_VERSION)!"
	@echo " "
	@echo "  build        builds the local CPU-only image"
	@echo " "
	@echo "  > Commands for manual registration of the ExApp (it must already be running):"
	@echo " "
	@echo "  register     registers the running $(APP_NAME) container against a deploy daemon"
	@echo "               named 'manual_install'. Adjust NC_CONTAINER / daemon name to match"
	@echo "               your setup before running."

.PHONY: build
build:
	docker build --tag recognaize:latest .

# Adjust NC_CONTAINER to the name of your Nextcloud container.
NC_CONTAINER := nextcloud

.PHONY: register
register:
	docker exec $(NC_CONTAINER) sudo -u www-data php occ app_api:app:unregister $(APP_ID) --silent --force || true
	docker exec $(NC_CONTAINER) sudo -u www-data php occ app_api:app:register $(APP_ID) manual_install --json-info $(JSON_INFO) --wait-finish

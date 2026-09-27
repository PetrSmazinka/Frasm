# =============================================================================
# Frasm – common development and deployment tasks
# -----------------------------------------------------------------------------
# Shortcuts for the most frequent workflows. Every target calls the Frasm CLI;
# run `php bin/frasm list` for all available commands.
# =============================================================================

PHP   ?= php
FRASM := $(PHP) bin/frasm
HOST  ?= 127.0.0.1
PORT  ?= 8000
REF   ?= master

.DEFAULT_GOAL := help
.PHONY: help serve fresh migrate seed routes cache worker update

help: ## Show this help
	@awk 'BEGIN {FS = ":.*## "} \
		/^##@/ {printf "\n%s\n", substr($$0, 5)} \
		/^[a-z-]+:.*## / {printf "  make %-10s %s\n", $$1, $$2}' $(MAKEFILE_LIST)
	@printf "\nAll commands: php bin/frasm list\n"

##@ Development

serve: ## Start the development server (HOST=127.0.0.1 PORT=8000)
	@$(FRASM) serve --host=$(HOST) --port=$(PORT)

fresh: ## Recreate the database and seed the admin (destroys all data)
	@$(FRASM) db:reset --seed

##@ Database

migrate: ## Apply the core schema and pending migrations
	@$(FRASM) db:migrate

seed: ## Create or update the administrator account
	@$(FRASM) db:seed

##@ Production

routes: ## List all routes
	@$(FRASM) route:list

cache: ## Rebuild the route cache (after every deploy)
	@$(FRASM) route:cache

worker: ## Process queued jobs until the queue is empty (cron mode)
	@$(FRASM) queue:work --once

update: ## Update the framework (REF=<tag|branch>, MIGRATE=1 also migrates)
	@./install.sh --update --ref $(REF) $(if $(MIGRATE),--migrate,) .

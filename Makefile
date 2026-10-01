# =============================================================================
# Frasm – project tasks
# -----------------------------------------------------------------------------
# `make help` lists the shortcuts below and every Frasm command. Any command
# of bin/frasm can be run through make; pass its arguments in ARGS:
#
#   make db:reset ARGS="--seed --force"
#   make token:create ARGS="home-assistant --days=365"
#
# Automation (cron, systemd) should call `php bin/frasm` directly: make may not
# be installed on servers and would not forward signals to the queue worker.
#
# PHP only in a Docker container: frasm.mk (written by install.sh --docker, not
# versioned) sets DOCKER_CONTAINER, DOCKER_WORKDIR (the project inside it) and
# optionally DOCKER_USER; every command then runs there through `docker exec`.
# =============================================================================

-include frasm.mk

PHP   ?= php
ifdef DOCKER_CONTAINER
$(if $(DOCKER_WORKDIR),,$(error frasm.mk: DOCKER_CONTAINER requires DOCKER_WORKDIR))
# Default: your user with the project's group (as on a host where you belong to the web server group)
DOCKER_USER ?= $(shell id -u):$(shell ls -ldn . | awk '{print $$4}')
# A terminal only when there is one (password prompts), so make also works from scripts
PHP := docker exec $(if $(shell [ -t 0 ] && echo tty),-it,-i) -u $(DOCKER_USER) -w $(DOCKER_WORKDIR) $(DOCKER_CONTAINER) php
DOCKER_ARGS := --docker $(DOCKER_CONTAINER) --docker-workdir $(DOCKER_WORKDIR) --docker-user $(DOCKER_USER)
endif
FRASM := $(PHP) bin/frasm
HOST  ?= 127.0.0.1
PORT  ?= 8000
REF   ?= master
ARGS  ?=

MAKEFLAGS += --no-builtin-rules --no-print-directory
.SUFFIXES:
.DEFAULT_GOAL := help
.PHONY: help serve fresh migrate seed routes cache worker test update

help: ## Show this help and all Frasm commands
	@awk 'BEGIN {FS = ":.*## "} \
		/^##@/ {printf "\n%s\n", substr($$0, 5)} \
		/^[a-z-]+:.*## / {printf "  make %-12s %s\n", $$1, $$2}' $(MAKEFILE_LIST)
	@printf "\nEvery Frasm command is also available as: make <command> ARGS=\"...\"\n\n"
	@$(FRASM) list

##@ Development

serve: ## Start the development server (HOST=127.0.0.1 PORT=8000)
	@$(FRASM) serve --host=$(HOST) --port=$(PORT)

fresh: ## Recreate the database and seed the administrator (destroys all data)
	@$(FRASM) db:reset --seed $(ARGS)

##@ Database

migrate: ## Apply the core schema and pending migrations
	@$(FRASM) db:migrate

seed: ## Create or update the administrator account
	@$(FRASM) db:seed

##@ Deployment

routes: ## List all routes
	@$(FRASM) route:list

cache: ## Rebuild the route cache (after every deploy)
	@$(FRASM) route:cache

worker: ## Process queued jobs until the queue is empty
	@$(FRASM) queue:work --once $(ARGS)

test: ## Run the tests in app/Tests (ARGS="blog --stop", ARGS=--fresh)
	@$(FRASM) test $(ARGS)

update: ## Update the framework (REF=<tag|branch>, MIGRATE=1 also migrates)
	@./install.sh --update --ref $(REF) $(if $(MIGRATE),--migrate,) $(DOCKER_ARGS) .

# Never try to rebuild the Makefile or frasm.mk through the catch-all rule below
Makefile frasm.mk: ;

# Any other goal is forwarded to the Frasm CLI (make db:rollback, make push:vapid, ...)
%:
	@$(FRASM) $@ $(ARGS)

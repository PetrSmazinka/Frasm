# -----------------------------------------------------------------------------
# Frasm Framework Automation Makefile
# -----------------------------------------------------------------------------
PHP       := php
PORT      := 8000
HOST      := 127.0.0.1
PUBLIC_DIR := public

.PHONY: help serve migrate rollback wipe reset seed fresh prune routes routes-cache routes-clear vapid

# Default target: display help
help:
	@echo "Available commands:"
	@echo "  make serve       Start local PHP development server (http://$(HOST):$(PORT))"
	@echo "  make migrate     Run all pending database migrations"
	@echo "  make rollback    Rollback the latest migration batch"
	@echo "  make reset       Wipe database and re-run all migrations"
	@echo "  make seed        Seed or synchronize the default administrator account"
	@echo "  make fresh       Reset database and seed immediately (wipe + migrate + seed)"
	@echo "  make prune       Purge expired remember-me tokens from database"
	@echo "  make wipe        Drop all database tables completely"
	@echo "  make routes      List all registered routes"
	@echo "  make routes-cache  Build the route cache (run after deploy)"
	@echo "  make routes-clear  Delete the route cache"
	@echo "  make vapid       Generate VAPID keys for Web Push notifications"

# -----------------------------------------------------------------------------
# Development Server
# -----------------------------------------------------------------------------
serve:
	@echo "Starting development server on http://$(HOST):$(PORT)..."
	@$(PHP) -S $(HOST):$(PORT) -t $(PUBLIC_DIR)

# -----------------------------------------------------------------------------
# Database & Migrations
# -----------------------------------------------------------------------------
migrate:
	@$(PHP) bin/db.php migrate

rollback:
	@$(PHP) bin/db.php rollback

wipe:
	@$(PHP) bin/db.php wipe

reset:
	@$(PHP) bin/db.php reset

seed:
	@$(PHP) bin/seed-admin.php

fresh:
	@$(PHP) bin/db.php reset --seed

# -----------------------------------------------------------------------------
# Maintenance & Cron Tasks
# -----------------------------------------------------------------------------
prune:
	@$(PHP) bin/prune-tokens.php

# -----------------------------------------------------------------------------
# Routing
# -----------------------------------------------------------------------------
routes:
	@$(PHP) bin/routes.php list

routes-cache:
	@$(PHP) bin/routes.php cache

routes-clear:
	@$(PHP) bin/routes.php clear

# -----------------------------------------------------------------------------
# Web Push
# -----------------------------------------------------------------------------
vapid:
	@$(PHP) bin/push.php vapid

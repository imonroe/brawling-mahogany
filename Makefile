# One-command entry points. Everything here is a thin wrapper over compose or
# the project's own scripts — nothing happens in this file that could not be
# typed out, so a reader can always see what a target really does.

COMPOSE ?= docker compose
APP ?= $(COMPOSE) exec app

.DEFAULT_GOAL := help

.PHONY: help
help: ## List the targets
	@grep -hE '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2}'

.PHONY: setup
setup: ## Boot the whole stack on a clean machine
	@test -f .env || cp .env.example .env
	@grep -q '^DB_PASSWORD=.\+' .env || { \
		echo "Set DB_PASSWORD in .env first — it is the only value you have to choose."; \
		exit 1; \
	}
	$(COMPOSE) build
	$(COMPOSE) up -d
	$(APP) php artisan key:generate
	# Compose injects env_file values when a container is created, so the
	# containers started above are still holding the empty APP_KEY that
	# key:generate has just replaced. Recreate the three that read it.
	$(COMPOSE) up -d --force-recreate app worker scheduler
	$(APP) php artisan migrate --force
	@echo
	@echo "  App      http://localhost:$${APP_PORT:-8000}"
	@echo "  Mailpit  http://localhost:$${MAIL_UI_PORT:-8025}"
	@echo "  Horizon  http://localhost:$${APP_PORT:-8000}/horizon"

.PHONY: up
up: ## Start the stack
	$(COMPOSE) up -d

.PHONY: down
down: ## Stop the stack
	$(COMPOSE) down

.PHONY: deps
deps: ## Install dependencies after a composer.json or package.json change
	# Dependencies live in volumes so the host's are not shadowed, which means
	# a rebuild does not pick up a new package — this does.
	$(APP) composer install
	$(APP) npm ci

.PHONY: reset
reset: ## Delete the stack's volumes — including the database. Destructive.
	# `down -v` removes every volume in the project: the dependency caches,
	# Caddy's certificates, and postgres-data. Local data is gone. For a new
	# dependency you want `make deps`.
	$(COMPOSE) down -v

.PHONY: logs
logs: ## Follow the application logs
	$(COMPOSE) logs -f app worker

.PHONY: shell
shell: ## A shell in the app container
	$(APP) bash

.PHONY: migrate
migrate: ## Run migrations
	$(APP) php artisan migrate

.PHONY: fresh
fresh: ## Drop everything and migrate from scratch
	$(APP) php artisan migrate:fresh --seed

.PHONY: test
test: ## Run the PHP test suite
	$(APP) php artisan test

.PHONY: check
check: ## Everything CI runs
	$(APP) composer check
	$(APP) npm run check

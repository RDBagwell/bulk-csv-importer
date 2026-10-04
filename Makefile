# One command to boot everything: `make up`.

# The recipes below are POSIX shell. Native Windows make (PowerShell / cmd)
# runs them through cmd.exe and fails with confusing errors, so stop early
# with directions instead. Git Bash sets MSYSTEM and has a shell; it passes.
ifeq ($(OS),Windows_NT)
ifeq ($(MSYSTEM),)
$(error This Makefile needs a Unix shell. On Windows, run it inside WSL2 (see README "Windows"), \
or run the docker compose commands listed there from PowerShell)
endif
endif

COMPOSE := docker compose
APP     := $(COMPOSE) exec app
# The containers run as your user so files they write stay editable. Root
# cannot be remapped this way, so root hosts fall back to 1000.
export USER_ID  ?= $(shell [ "$$(id -u)" = 0 ] && echo 1000 || id -u)
export GROUP_ID ?= $(shell [ "$$(id -g)" = 0 ] && echo 1000 || id -g)

.PHONY: up down build install migrate fresh test lint analyse bench-data shell logs

# Horizon and the scheduler run artisan, which needs vendor/, so they start
# only after dependencies are installed and the database is migrated.
up: .env ## Build, start the stack, install dependencies, migrate and build assets
	$(COMPOSE) up -d --build --wait app mailpit
	$(MAKE) install migrate
	$(COMPOSE) up -d --wait
	@echo "App:     http://localhost:$${APP_PORT:-8080}"
	@echo "Horizon: http://localhost:$${APP_PORT:-8080}/horizon"
	@echo "Mailpit: http://localhost:$${FORWARD_MAILPIT_DASHBOARD_PORT:-8025}"

.env:
	cp .env.example .env

install:
	$(APP) composer install --no-interaction
	@grep -q '^APP_KEY=base64' .env || $(APP) php artisan key:generate
	$(APP) npm ci
	$(APP) npm run build

migrate:
	$(APP) php artisan migrate --force

fresh: ## Drop all tables and migrate again
	$(APP) php artisan migrate:fresh --force

down:
	$(COMPOSE) down

# The DB overrides keep the suite off the dev database; see phpunit.xml.
test:
	$(COMPOSE) exec -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: -e DB_URL= app php artisan test
	$(APP) npm test

lint:
	$(APP) vendor/bin/pint --test
	$(APP) npm run check

analyse:
	$(APP) vendor/bin/phpstan analyse --memory-limit=1G

bench-data: ## Generate the benchmark input files
	$(APP) php artisan importer:generate 100000
	$(APP) php artisan importer:generate 1000000

shell:
	$(APP) bash

logs:
	$(COMPOSE) logs -f --tail=100

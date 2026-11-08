.DEFAULT_GOAL = help
SHELL:=/bin/bash

DOCKER=docker
DC=$(DOCKER) compose
DCE=$(DC) exec
PHP=$(DCE) php php
CONSOLE=$(PHP) bin/console
COMPOSER=$(DCE) php composer
YARN=$(DCE) node yarn
ENV ?= dev

.PHONY: help
help:
	@grep -E '(^([a-zA-Z_-]+ ?)+:.*?##.*$$)|(^##)' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[32m%-30s\033[0m %s\n", $$1, $$2}' | sed -e 's/\[32m##/[33m/'

##
## —— Environment ⚙️ ————————————————
.PHONY: start
start: submodules docker-build up vendor yarn ## Start the project (submodules + Docker + vendors) on https://console.depverify.localhost

.PHONY: stop
stop: ## Stop the project
	@$(DC) down --remove-orphans

.PHONY: up
up: ## Start the containers (php, database, node/Vite) and wait until they are healthy
	@$(DC) up -d --wait --remove-orphans

.PHONY: docker-build
docker-build: ## Build Docker images
	@$(DC) build --pull

.PHONY: logs
logs: ## Follow container logs
	@$(DC) logs -f

.PHONY: submodules
submodules: ## Fetch git submodules (Romain-MILLAN-Tag)
	@git submodule update --init --recursive

QUEUE_NAME ?= async
VERBOSITY ?= -v
LIMIT ?= 1
.PHONY: consume
consume: ## Run a messenger consumer (QUEUE_NAME=async VERBOSITY=-v LIMIT=1)
	@$(CONSOLE) messenger:consume $(QUEUE_NAME) $(VERBOSITY) --limit=$(LIMIT)

##
## —— Dependencies 🔒️————————————————
.PHONY: composer
composer: ## Run composer, pass the parameter "c=" to run a given command, example: make composer c='req symfony/lock'
	@$(eval c ?=)
	@$(COMPOSER) $(c)

.PHONY: vendor
vendor: ## Install vendors according to the current composer.lock file
vendor: c=install --prefer-dist --no-progress
vendor: composer

.PHONY: vendor-build
vendor-build: ## Install production vendors
	@$(COMPOSER) install --no-dev --optimize-autoloader

.PHONY: yarn
yarn: ## Install JS dependencies (always through the node container, never npm)
	@$(YARN) install

##
## —— Cache 🗃️ ————————————————
.PHONY: cc
cc: ## Clear cache (ENV=dev)
	@$(CONSOLE) cache:clear -e $(ENV)

##
## —— Assets ✨ ————————————————
.PHONY: watch
watch: ## Follow the Vite dev server (it runs in the node container started by `make up`)
	@$(DC) logs -f node

.PHONY: assets-build
assets-build: yarn ## Build production assets (public/build)
	@$(YARN) build

.PHONY: icon
icon: ## Vendor a Lucide icon into assets/icons, example: make icon i=lucide:bell
	@:$(if $(i),,$(error Usage: make icon i=lucide:<name>))
	@$(CONSOLE) ux:icons:import $(i)

.PHONY: ux
ux: ## Install a UX Toolkit shadcn component, example: make ux r=dialog (then convert its controller to TypeScript)
	@:$(if $(r),,$(error Usage: make ux r=<recipe>))
	@$(CONSOLE) ux:install $(r) --kit=shadcn
	@echo "⚠️  Convert any new assets/controllers/*_controller.js to TypeScript (make typecheck fails on .js files)."

.PHONY: typecheck
typecheck: ## Type-check the front-end (tsc --noEmit, strict) and forbid JavaScript files in assets/
	@if find assets -name '*.js' -not -path 'assets/romain-millan-tag/*' | grep .; then \
		echo "❌ JavaScript files found in assets/: write TypeScript (.ts) only."; \
		exit 1; \
	fi
	@$(YARN) tsc -p tsconfig.json

##
## —— Database 🗃️————————————————
.PHONY: db-diff
db-diff: ## Generate a new migration
	@$(CONSOLE) doctrine:migrations:diff

.PHONY: db-migrate
db-migrate: ## Execute all pending migrations
	@$(CONSOLE) doctrine:migrations:migrate --no-interaction

.PHONY: db-migrate-prev
db-migrate-prev: ## Revert the last migration
	@$(CONSOLE) doctrine:migrations:migrate 'prev' --no-interaction

.PHONY: db-reset
db-reset: ## Drop, create and migrate the database
	@echo "💥 Drop the database."
	@$(CONSOLE) doctrine:database:drop --force --if-exists
	@echo "🏗️ Create new database."
	@$(CONSOLE) doctrine:database:create
	@echo "🚚 Run all migrations."
	@$(MAKE) db-migrate

.PHONY: db-dump
db-dump: ## Dump the dev database to var/DUMP, example: make db-dump DUMP=backup.sql
	@:$(if $(DUMP),,$(error Usage: make db-dump DUMP=<file.sql>))
	@echo '🗃️ Saving database.'
	@$(DCE) database save $(DUMP)
	@$(DC) cp database:/$(DUMP) ./var/$(DUMP)
	@echo '✅ Database saved to var/$(DUMP).'

.PHONY: db-import
db-import: ## Replace the dev database with var/DUMP, example: make db-import DUMP=backup.sql
	@:$(if $(DUMP),,$(error Usage: make db-import DUMP=<file.sql>))
	@$(DC) cp ./var/$(DUMP) database:/$(DUMP)
	@echo '🗃️ Resetting and importing database.'
	@$(DCE) database reset $(DUMP)
	@echo '✅ var/$(DUMP) imported.'

.PHONY: adminer
adminer: ## Show how to reach Adminer (dev database UI)
	@echo "Adminer: http://localhost:8080 (system PostgreSQL, server database, dev credentials app / !ChangeMe! / app)"

.PHONY: db-test-prepare
db-test-prepare: ## Create and migrate the test database
	@$(CONSOLE) doctrine:database:create --if-not-exists --env=test
	@$(CONSOLE) doctrine:migrations:migrate --no-interaction --allow-no-migration --env=test

##
## —— Tests 📊 & Code Quality ✅————————————————
.PHONY: test
test: db-test-prepare ## Run PHPUnit (FILTER=… to filter, COVERAGE=1 for an HTML report in var/phpunit-coverage)
ifdef FILTER
	@$(DC) exec -e APP_ENV=test php vendor/bin/phpunit --filter $(FILTER)
else ifdef COVERAGE
	@$(DC) exec -e APP_ENV=test -e XDEBUG_MODE=coverage php vendor/bin/phpunit --coverage-html var/phpunit-coverage
else
	@$(DC) exec -e APP_ENV=test php vendor/bin/phpunit
endif

.PHONY: infection
infection: db-test-prepare ## Run mutation tests on Domain/Application layers (100% MSI required)
	@$(DC) exec -e APP_ENV=test -e XDEBUG_MODE=coverage php vendor/bin/infection --min-msi=100 --min-covered-msi=100 --threads=max

.PHONY: phpcs
phpcs: ## Check PHP code style (dry-run)
	@$(PHP) vendor/bin/php-cs-fixer fix --diff --dry-run --allow-risky=yes

.PHONY: phpcsfix
phpcsfix: ## Fix PHP code style
	@$(PHP) vendor/bin/php-cs-fixer fix --allow-risky=yes

.PHONY: phpstan
phpstan: ## Run static analysis (PHPStan, level max)
	@$(CONSOLE) cache:warmup
	@$(PHP) vendor/bin/phpstan analyse --memory-limit=1G

.PHONY: rector
rector: ## Show Rector refactorings (dry-run)
	@$(PHP) vendor/bin/rector process --dry-run

.PHONY: rector-fix
rector-fix: ## Apply Rector refactorings
	@$(PHP) vendor/bin/rector process

.PHONY: lint
lint: ## Lint container, Twig and YAML
	@$(CONSOLE) lint:container
	@$(CONSOLE) lint:twig templates/
	@$(CONSOLE) lint:yaml config

.PHONY: audit
audit: ## Check PHP and JS dependencies for known vulnerabilities
	@$(COMPOSER) audit
	@$(YARN) npm audit --all --recursive

.PHONY: qa
qa: lint typecheck phpcs phpstan rector test infection ## Run every quality gate (what CI will run)

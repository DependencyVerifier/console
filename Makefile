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

# Owner role credentials (migrations, audit partitions): read on the host, passed only to the CLI commands below
# with `-e DATABASE_MIGRATIONS_URL` (no value on the command line, so it never shows in `ps`). Never loaded by
# symfony/runtime, hence never visible to the web workers. owner.env (git-ignored) overrides the dev defaults.
OWNER_ENV := $(firstword $(wildcard .docker/database/owner.env) .docker/database/owner.env.dist)
include $(OWNER_ENV)
export DATABASE_MIGRATIONS_URL
# Runs bin/console as the owner role: DATABASE_URL is swapped inside the container, never on the host command line.
OWNER_CONSOLE=$(DCE) -e DATABASE_MIGRATIONS_URL php sh -c 'DATABASE_URL="$$DATABASE_MIGRATIONS_URL" exec bin/console "$$@"' --

.PHONY: help
help:
	@grep -hE '(^([a-zA-Z_-]+ ?)+:.*?##.*$$)|(^##)' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[32m%-30s\033[0m %s\n", $$1, $$2}' | sed -e 's/\[32m##/[33m/'

##
## —— Environment ⚙️ ————————————————
.PHONY: start
start: submodules docker-build up vendor yarn db-migrate ## Start the project (submodules + Docker + vendors) on https://console.depverify.localhost

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
.PHONY: db-roles
db-roles: ## (Re)create the depverify_owner / depverify_app roles on an existing volume (idempotent)
	@$(DCE) database sh /docker-entrypoint-initdb.d/10-roles.sh

.PHONY: db-diff
db-diff: ## Generate a new migration (as the owner role)
	@$(OWNER_CONSOLE) doctrine:migrations:diff

.PHONY: db-migrate
db-migrate: ## Execute all pending migrations (as the owner role)
	@$(OWNER_CONSOLE) doctrine:migrations:migrate --no-interaction

.PHONY: db-migrate-prev
db-migrate-prev: ## Revert the last migration (as the owner role)
	@$(OWNER_CONSOLE) doctrine:migrations:migrate 'prev' --no-interaction

.PHONY: db-reset
db-reset: ## Drop, create and migrate the database (as the owner role)
	@echo "💥 Drop the database."
	@$(OWNER_CONSOLE) doctrine:database:drop --force --if-exists
	@echo "🏗️ Create new database."
	@$(OWNER_CONSOLE) doctrine:database:create
	@echo "🚚 Run all migrations."
	@$(MAKE) db-migrate

.PHONY: audit-partitions
audit-partitions: ## Create the next audit partitions and drop the expired ones (as the owner role)
	@$(OWNER_CONSOLE) app:audit:maintain-partitions

.PHONY: rate-limiter-prune
rate-limiter-prune: ## Delete expired rate limiter states
	@$(CONSOLE) app:rate-limiter:prune

.PHONY: worker-restart
worker-restart: ## Restart the Messenger worker (it never reloads code, so after a change to a message handler)
	@$(DC) restart worker

.PHONY: worker-logs
worker-logs: ## Follow the Messenger worker
	@$(DC) logs -f worker

.PHONY: inventory-maintain
inventory-maintain: ## Inventory maintenance, stale inventories, history beyond retention, unreferenced snapshots (cron, hourly)
	@$(CONSOLE) app:inventory:maintain

.PHONY: vuln-sync
vuln-sync: ## Queue the sync of the advisories (osv.dev, by package name), CISA KEV and FIRST EPSS (cron: every 6 hours; the worker runs it)
	@$(CONSOLE) app:vulnerability:sync

.PHONY: eol-sync
eol-sync: ## Queue the sync of the end of life cycles (endoflife.date) and of the latest releases of direct dependencies (Packagist, npm) (cron: daily)
	@$(CONSOLE) app:vulnerability:sync-lifecycle

.PHONY: findings-refresh
findings-refresh: ## Queue the computation of the findings of every inventoried team (cron: daily, after eol-sync)
	@$(CONSOLE) app:exposure:refresh

.PHONY: fleet-maintain
fleet-maintain: ## Fleet maintenance, inactive agents, late orders, request nonces (cron, every few minutes)
	@$(CONSOLE) app:fleet:maintain

.PHONY: notifications-check
notifications-check: ## Notify the silent agents and the expiring risk acceptances, queue the deliveries due again (cron: every 5 minutes)
	@$(CONSOLE) app:notification:check

.PHONY: notifications-digest
notifications-digest: ## Queue the daily notification digests, and on Monday the weekly ones (cron: daily, 07:00)
	@$(CONSOLE) app:notification:digest

.PHONY: agent-release-sync
agent-release-sync: ## Download, verify (AGENT_RELEASE_PUBKEY) and store the latest release of the agent, served by install.sh (cron: daily)
	@$(CONSOLE) app:fleet:sync-agent-release

.PHONY: console-key-rotate
console-key-rotate: ## Move the approved agents to the next console key (APP_AGENT_SIGNING_KEY_NEXT); run until all are on it
	@$(CONSOLE) app:fleet:rotate-console-key

.PHONY: agent-release-import
agent-release-import: ## Import a signed release of the agent from a folder (dir=…: SHA256SUMS, SHA256SUMS.minisig, binaries)
	@:$(if $(dir),,$(error Usage: make agent-release-import dir=<folder>))
	@$(CONSOLE) app:fleet:import-agent-release $(dir)

.PHONY: fleet-fake-agent
fleet-fake-agent: ## Dev demo agent: a='enroll --token=dvet_…', a=poll, a='run --polls=10', a=inventory (name=… for another one)
	@$(CONSOLE) app:fleet:fake-agent $(a) $(if $(name),--name=$(name))

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

.PHONY: mailpit
mailpit: ## Show how to reach Mailpit (dev mail catcher)
	@echo "Mailpit: http://localhost:8025 (SMTP mailpit:1025 inside the Docker network)"

.PHONY: super-admin
super-admin: ## Create a super admin (runtime role, password typed hidden), example: make super-admin email=you@example.com
	@:$(if $(email),,$(error Usage: make super-admin email=<address>))
	@$(DC) exec php bin/console app:user:create-super-admin $(email)

.PHONY: adminer
adminer: ## Show how to reach Adminer (dev database UI)
	@echo "Adminer: http://localhost:8080 (system PostgreSQL, server database, database app). Roles: depverify_app / !ChangeMeApp! (runtime), depverify_owner / !ChangeMeOwner! (owner), app / !ChangeMe! (superuser, init only)"

.PHONY: db-test-prepare
db-test-prepare: ## Create and migrate the test database (as the owner role)
	@$(OWNER_CONSOLE) doctrine:database:create --if-not-exists --env=test
	@$(OWNER_CONSOLE) doctrine:migrations:migrate --no-interaction --allow-no-migration --env=test

##
## —— Tests 📊 & Code Quality ✅————————————————
.PHONY: test
test: db-test-prepare ## Run PHPUnit (FILTER=… to filter, COVERAGE=1 for an HTML report in var/phpunit-coverage)
ifdef FILTER
	@$(DC) exec -e APP_ENV=test -e TEST_OWNER_DATABASE_URL="$$DATABASE_MIGRATIONS_URL" php vendor/bin/phpunit --filter $(FILTER)
else ifdef COVERAGE
	@$(DC) exec -e APP_ENV=test -e XDEBUG_MODE=coverage -e TEST_OWNER_DATABASE_URL="$$DATABASE_MIGRATIONS_URL" php vendor/bin/phpunit --coverage-html var/phpunit-coverage
else
	@$(DC) exec -e APP_ENV=test -e TEST_OWNER_DATABASE_URL="$$DATABASE_MIGRATIONS_URL" php vendor/bin/phpunit
endif

.PHONY: infection
infection: db-test-prepare ## Run mutation tests on Domain/Application layers (100% MSI required)
	@$(DC) exec -e APP_ENV=test -e XDEBUG_MODE=coverage -e TEST_OWNER_DATABASE_URL="$$DATABASE_MIGRATIONS_URL" php php -d memory_limit=1G vendor/bin/infection --with-uncovered --min-msi=100 --min-covered-msi=100 --threads=max

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
	@$(CONSOLE) lint:yaml config translations --parse-tags

.PHONY: audit
audit: ## Check PHP and JS dependencies for known vulnerabilities
	@$(COMPOSER) audit
	@$(YARN) npm audit --all --recursive

.PHONY: qa
qa: lint typecheck phpcs phpstan rector test infection ## Run every quality gate (what CI will run)

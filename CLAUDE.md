# DepRadar console (`console/`)

Symfony web app, licensed **AGPL-3.0-only**. Multi-team UI, signed agent API, inventory store, matching engine, notifications.

**Read first:** `docs/cadrage.md` §5 (data model), §7 / §7bis (security, auth, permissions), §8 (analysis engine), §9 / §9bis (stack, architecture, sizing); `docs/design.md` for every UI change. Generic Symfony guidance from the skeleton: `AGENTS.md`.

Local URL: **https://console.depradar.localhost** (Caddy local CA: trust it or accept the certificate in the browser). Health: `/_healthcheck`.

## Architecture: DDD, one bounded context per top-level namespace

```
src/<Context>/
├── Domain/          # Model/ (aggregates, entities, value objects; Doctrine attribute mapping), events, repository interfaces, domain services
├── Application/     # use cases: Command/<Feature>/<VerbNoun>.php + Handler/, Query/<Feature>/... + Handler/, ports (interfaces)
├── Infrastructure/  # adapters: Doctrine repositories, HTTP clients, Messenger, probes…
└── UI/              # Controller/ (invokable, one per action, + RouteCollection enum), forms, Twig components, CLI commands
```

| Namespace | Responsibility (`docs/cadrage.md`) |
|---|---|
| `Shared\` | Kernel, CQRS buses, health probes, routing helpers, web shell (layouts, sidebar, footer, previews) |
| `Identity\` | Accounts, authentication (password + TOTP / email code, optional passkeys), invitations, notification-only accounts |
| `Organization\` | Teams, quotas, projects, environments, memberships, permissions (`PermissionResolver`, voters) |
| `Fleet\` | Hosts, cloud scopes, agents, enrollment tokens, signed agent API, orders |
| `Inventory\` | Inventory sources, snapshots (CycloneDX SBOM), components, app instances, container images |
| `Vulnerability\` | Advisories (OSV, KEV, EPSS), EOL cycles, sync of public data sources |
| `Exposure\` | Matching, findings, risk acceptances, drift |
| `Notification\` | Channels, rules, digests, delivery |
| `Audit\` | Append-only audit log |

Rules (enforced by `tests/Architecture/LayerDependencyTest.php`):
- **Domain** depends only on its own Domain and `Shared\Domain` (+ `Doctrine\ORM\Mapping`, `Symfony\Component\Uid`). No framework services.
- **Application** depends only on Domain/Application of its own context and of `Shared` (+ DI attributes, PSR). It talks to infrastructure through interfaces (ports).
- A business context **never imports another business context**. Collaboration goes through `Shared` contracts, domain events, or the other context's queries via the query bus.
- Infrastructure and UI may depend on anything.

Wiring: `composer.json` PSR-4 (one entry per context), `config/services.yaml` (one resource per context, `Domain/Model` excluded), `config/routes.yaml` (one import of `UI/Controller` per context, name prefix `<context>_`), `config/packages/doctrine.yaml` (one mapping per `Domain/Model`). **When adding a context, update all four plus the architecture test and `infection.json5`.**

## CQRS (Messenger, three buses)

- `command.bus` (default): state changes, wrapped in a Doctrine transaction. Handlers **never flush**.
- `query.bus`: reads, returns a result.
- `event.bus`: domain events (`Shared\Domain\Event\DomainEvent`), zero or more handlers.
- Messages implement `Shared\Application\Bus\{Command, Query<TResult>}`. Handlers implement `{CommandHandler, QueryHandler, EventHandler}`, which `_instanceof` in `services.yaml` routes to the right bus, so there is **no `#[AsMessageHandler]`** in Application.
- Dispatch through the ports `CommandBus` / `QueryBus` / `EventBus` (Messenger adapters in `Shared\Infrastructure\Bus` unwrap `HandlerFailedException`).
- Naming: `Application/Command/<Feature>/<VerbNoun>.php` → `Application/Command/<Feature>/Handler/<VerbNoun>Handler.php`, same for `Query/`. Example: `Shared\Application\Health\Query\GetHealthReport` + `Handler\GetHealthReportHandler`.

## UI conventions

- Controllers: invokable, `#[Route(path: …, name: RouteCollection::CASE->value)]` with the context's `UI\Controller\RouteCollection` enum (implements `Shared\UI\Routing\RouteCollectionInterface`). Use `->prefixed()` for `path()` / redirects.
- Templates: `templates/<context>/…` (snake_case). Kit and app components in `templates/components/` (`AppSidebar`, `AppFooter`, `MetaGrid`, `StatusDot`, `SeverityBadge`, `ThemeToggle`, `RomainMillanTag`). Layouts: `shared/layout/app.html.twig` (sidebar shell + footer) and `shared/layout/auth.html.twig`. Page blocks are `breadcrumb` and `main`: `content` is reserved inside component tags.
- Footer signature: the **Romain-MILLAN-Tag git submodule** (`assets/romain-millan-tag`), imported in `assets/styles/app.css` with `layer(base)` and rendered by `<twig:RomainMillanTag />`. Update it with `git submodule update --remote console/assets/romain-millan-tag`.
- **No external resource** (fonts, scripts, CDN): everything is served by the instance. FrankenPHP's CDN hot-reload snippet was removed on purpose; Vite handles HMR (reload the page by hand after a Twig change).
- **Front-end code is TypeScript only**: no `.js` file in `assets/`. `make typecheck` (strict `tsc`, in `make qa`) fails if one appears. Stimulus controllers are `assets/controllers/<name>_controller.ts` with `declare` typed targets and values; kit controllers coming from `make ux` must be converted. Config in `tsconfig.json`, Vite config in `vite.config.ts`.
- **No Mercure**: removed from Caddy, Compose and Turbo (no real-time hub in this project). Don't reinstall `symfony/mercure-bundle`.
- `templates/shared/preview/` + `Shared\UI\Controller\Preview\*` are static design previews (`/`, `/_preview/login`). Remove them when real screens land.

## Healthcheck

`GET /_healthcheck` (`Shared\UI\Controller\HealthcheckController`) asks `GetHealthReport` on the query bus. That handler runs every `Shared\Application\Health\HealthProbe` (`#[AutoconfigureTag]`; today `DatabaseHealthProbe`, `SELECT 1`).
- Healthy: 200 with the page adapted from Romain-MILLAN-Healthcheck (`templates/shared/healthcheck.html.twig`, standalone, no web font, tag CSS inlined from the submodule).
- Unhealthy: 503 JSON `{"status":"down","checks":{…}}`, without failure details.
- Always `no-store` and `noindex`. The `php` container healthcheck (`compose.yaml`) calls it over `http://php/_healthcheck`. Add a probe per critical dependency (queues, mailer…); never weaken it. Keep it public when the firewall arrives.

## Stack

Symfony 8.1, PHP 8.5, FrankenPHP + Caddy (`dunglas/symfony-docker`), PostgreSQL 18, Doctrine ORM (UUID v7), Messenger, Twig + Symfony UX (Turbo, Stimulus, Twig/Live Components, Icons), Symfony Reprise + Vite (TypeScript), Tailwind CSS v4, UX Toolkit `shadcn` kit, self-hosted IBM Plex fonts, Lucide icons vendored in `assets/icons/`.

## Commands (`make help`, from `console/`)

```bash
make start            # submodules + build + up + vendors (first run)
make up / make stop   # containers: php, database, node (Vite dev server :5173)
make test             # PHPUnit (prepares the test DB); FILTER=… / COVERAGE=1
make qa               # lint + typecheck + phpcs + phpstan (level max) + rector + test + infection (100% MSI): run before committing
make typecheck        # strict tsc on assets/ + vite.config.ts, and no .js allowed in assets/
make phpcsfix / make rector-fix
make composer c='req vendor/package'      # PHP packages (let Flex recipes do the wiring)
docker compose exec node yarn add -D …    # JS packages: always yarn via the node container, never npm
make ux r=dialog / make icon i=lucide:bell
make db-diff / make db-migrate          # migrations are organized by year: migrations/<YYYY>/Version….php (namespace DoctrineMigrations)
make db-dump DUMP=backup.sql / make db-import DUMP=backup.sql   # pg_dump / psql via .docker/database/scripts, files in var/
make adminer          # dev DB UI on http://localhost:8080 (system PostgreSQL, server database, app / !ChangeMe! / app)
```

Database: **PostgreSQL 18**, kept on purpose over MariaDB. The design relies on partial unique indexes ("unique while active"), transactional DDL, partitioning with foreign keys, JSONB and `LISTEN/NOTIFY` (see `docs/cadrage.md` §9). Use these features rather than working around them. After `db-import`, the first request of each FrankenPHP worker may fail once (its connection was dropped), then it reconnects.

## Code conventions

- `declare(strict_types=1)`, `final` / `readonly` classes, constructor injection, attributes, `?string` over `string|null`.
- Strings: `use function Symfony\Component\String\u;`, never native string functions. Slugs: inject `SluggerInterface`.
- `#[AutoconfigureTag]` on interfaces without an explicit name; inject with `#[AutowireIterator(Interface::class)]`.
- Tests: `tests/<Context>/{Unit,Integration,Functional}/…` mirroring `src`, namespace `Tests\`, `#[Test]` + `it_should_*` snake_case methods. Domain/Application code must keep **100% MSI**.
- Authorization: voters check **permissions** through the `PermissionResolver`, never roles. Listings are scope-filtered, quotas enforced in Application handlers.
- Secrets: hashed when never read back (tokens), AES-256-GCM at rest otherwise. Never log request bodies.

## Notes

- `config/bundles.php` registers `TalesFromADevTwigExtraTailwindBundle` by hand: no Flex recipe exists, and the shadcn kit needs its `tailwind_classes` filter.
- Not installed yet (add with the feature that needs them): Security, Scheduler, Mailer / Notifier, rate limiter, WebAuthn, async Messenger transport.

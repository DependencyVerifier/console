# DepRadar console (`console/`)

Symfony web app, licensed **AGPL-3.0-only**. Multi-team UI, signed agent API, inventory store, matching engine, notifications.

**Read first:** `docs/cadrage.md` §5 (data model), §7 / §7bis (security, auth, permissions), §8 (analysis engine), §9 / §9bis (stack, sizing); `docs/design.md` for every UI change. Generic Symfony guidance from the skeleton: `AGENTS.md`.

## Stack

- Symfony 8.1 (latest stable 8.x), PHP 8.5, FrankenPHP + Caddy, from the `dunglas/symfony-docker` template (`Dockerfile`, `compose.yaml`, `compose.override.yaml` for dev, `compose.prod.yaml` for production, `frankenphp/`).
- PostgreSQL 18 (Doctrine ORM). UUID v7 primary keys (`symfony/uid`, native `uuid` type).
- Twig + Symfony UX: Turbo, Stimulus, Twig Components, Live Components, UX Icons.
- Assets: **Symfony Reprise + Vite** (`vite.config.js`, entry `assets/app.js`, Stimulus via `assets/controllers.json`). Reprise is experimental, so check its changelog when upgrading.
- **Tailwind CSS v4** (`@tailwindcss/vite`) + **Symfony UX Toolkit `shadcn` kit**. Components live in `templates/components/`, controllers in `assets/controllers/`. They are our code, adapt them freely.
- Self-hosted fonts (IBM Plex Sans / Mono via `@fontsource`). Icons are imported into `assets/icons/` (no runtime Iconify calls).

## Commands (from `console/`)

```bash
docker compose up --wait                       # php + database + node (Vite dev server on :5173)
docker compose exec php bin/console …          # Symfony console
docker compose exec php composer require …     # PHP packages (let Flex recipes do the wiring)
docker compose exec node yarn add -D …         # JS packages: always yarn, through the node container, never npm
docker compose exec node yarn build            # production asset build
docker compose exec php bin/console ux:install <recipe> --kit=shadcn   # add a UI component
docker compose exec php bin/console ux:icons:import lucide:<name>      # vendor a new icon
docker compose exec php bin/console lint:twig templates
```

The app is served on https://localhost (Caddy local CA: trust it or accept the certificate in the browser).

## Conventions

- `final` classes, `declare(strict_types=1)`, constructor injection, PHP attributes (`#[Route]`, `#[IsGranted]`, `#[AsMessageHandler]`…).
- Strings: `use function Symfony\Component\String\u;`, never native string functions. Slugs: inject `SluggerInterface`.
- `#[AutoconfigureTag]` on interfaces without an explicit name; inject with `#[AutowireIterator(Interface::class)]`.
- Authorization: voters check **permissions** through the `PermissionResolver`, never roles. Listings are scope-filtered. Quotas are enforced in domain services.
- Secrets: hashed when they must never be read back (tokens), AES-256-GCM at rest otherwise. Never log request bodies.
- UI: build pages from the kit components and the DepRadar ones (`AppSidebar`, `MetaGrid`, `StatusDot`, `SeverityBadge`, `ThemeToggle`). Layouts: `layout/app.html.twig` (sidebar shell) and `layout/auth.html.twig`. Page blocks are `breadcrumb` and `main`, because the `content` name is reserved inside component tags. Load **no external resource** (fonts, scripts, CDN). The only exception is the dev-only FrankenPHP hot-reload snippet.
- `templates/demo/` + `DemoController` are static design previews (`/` and `/_preview/login`). Remove them when the real screens land.

## Notes

- `config/bundles.php` registers `TalesFromADevTwigExtraTailwindBundle` by hand: the package has no Flex recipe and the shadcn kit needs its `tailwind_classes` filter.
- Not installed yet (add with the feature that needs them): Security, Messenger, Scheduler, Mailer / Notifier, rate limiter, WebAuthn.

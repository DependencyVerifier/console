<p align="center"><img src="public/favicon.svg" width="72" height="72" alt="DependencyVerifier"></p>

# DependencyVerifier console

Self-hosted console of [DependencyVerifier](https://github.com/DependencyVerifier): see what really runs in production across your teams (hosts, runtimes, Composer and npm dependencies, OS packages, container images) and which vulnerabilities and end-of-life dates touch it. Inventories come from the [agent](https://github.com/DependencyVerifier/agent); data from OSV, CISA KEV, EPSS and endoflife.date.

> Status: 1.0 — self-hosted, one server, Docker Compose.

## Stack

Symfony 8 · PHP 8.5 · FrankenPHP · PostgreSQL 18 · Twig + Symfony UX · Vite (TypeScript) · Tailwind CSS v4. Domain-driven layout (`src/<Context>/{Domain,Application,Infrastructure,UI}`), CQRS on Symfony Messenger.

## Installation

Production: see [INSTALL.md](INSTALL.md) (settings, secrets, first start, backups, upgrades). Agents install from the console itself (`/install.sh`, or the `ghcr.io/dependencyverifier/agent` image).

## Development

```bash
git clone --recurse-submodules https://github.com/DependencyVerifier/console.git
cd console && make start   # then open https://console.depverify.localhost
```

`make help` lists every command; `make qa` runs the quality gates (lint, TypeScript, PHP CS Fixer, PHPStan max, Rector, PHPUnit, Infection).

## Documentation

Specification, UI charter and brand assets: [`DependencyVerifier/.github`](https://github.com/DependencyVerifier/.github).

## License

[AGPL-3.0-only](LICENSE).

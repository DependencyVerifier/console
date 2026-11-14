# Installing the console in production

One server with Docker Engine and the Compose plugin, a DNS name pointing to it, ports 80 and 443 open (Caddy
obtains and renews the Let's Encrypt certificate by itself), and an SMTP account with TLS. Everything runs in
containers: `php` (FrankenPHP + Caddy), `worker` (asynchronous queues), `cron` (scheduled commands), `database`
(PostgreSQL 18).

## 1. Get the code

```bash
git clone --recurse-submodules https://github.com/DependencyVerifier/console.git
cd console
git checkout v1.0.0   # a release tag, never main
```

## 2. Settings and secrets

```bash
cp prod.env.dist prod.env && chmod 600 prod.env
cp .docker/database/owner.env.dist .docker/database/owner.env && chmod 600 .docker/database/owner.env
```

Fill `prod.env` (every value is explained in it). To generate the secrets:

```bash
echo "APP_SECRET=$(openssl rand -hex 32)"
echo "APP_ENCRYPTION_KEYS=key1=$(openssl rand -base64 32)"
echo "APP_HASH_PEPPER=key1=$(openssl rand -base64 32)"
echo "APP_AGENT_SIGNING_KEY=$(openssl rand -base64 32)"
for v in POSTGRES_PASSWORD DEPVERIFY_APP_PASSWORD DEPVERIFY_OWNER_PASSWORD; do echo "$v=$(openssl rand -hex 24)"; done
```

In `.docker/database/owner.env`, put the owner password of `prod.env` in `DATABASE_MIGRATIONS_URL`
(`postgresql://depverify_owner:<DEPVERIFY_OWNER_PASSWORD>@database:5432/app?serverVersion=18&charset=utf8`): the
Makefile passes it to the migrations only, it never reaches the web workers.

**Back up `prod.env` outside the server now.** Without `APP_ENCRYPTION_KEYS` the stored secrets (webhook secrets,
TOTP) cannot be read; without `APP_AGENT_SIGNING_KEY` every agent must be installed again.

Every command below runs in a shell where `prod.env` is loaded:

```bash
set -a; . ./prod.env; set +a
```

## 3. Build and start

```bash
docker compose build --pull
docker compose up -d --wait
make db-migrate                      # creates the schema (owner role)
make audit-partitions                # the audit log partitions of the coming months
make agent-release-sync              # the latest verified agent release, for install.sh (then daily by the cron)
make super-admin email=you@example.com
```

Open `https://<SERVER_NAME>`, sign in, set up your second factor (TOTP or a passkey), create the first team.

## 4. What runs by itself

The `cron` service runs `frankenphp/crontab` as `www-data`: agent maintenance and notifications every 5 minutes,
inventory maintenance hourly, vulnerability data every 6 hours, end-of-life data, findings refresh, agent release
sync and digests daily. `docker compose logs cron` shows their output. Nothing else is needed.

## 5. Backups

| What | How | Why |
|---|---|---|
| `prod.env`, `owner.env` | a copy outside the server (password manager, vault) | the keys of everything stored |
| PostgreSQL | `make db-dump DUMP=console-$(date +%F).sql`, then copy `var/console-….sql` away | accounts, teams, inventories, audit log |
| Caddy data (`caddy_data` volume) | optional | certificates are obtained again if lost |

The agent releases volume is rebuilt by `make agent-release-sync`. To restore a dump on a fresh server: steps 1–3
up to `docker compose up`, then `make db-import DUMP=<file>.sql` (the file in `var/`), then `make db-migrate`.

## 6. Upgrading

```bash
set -a; . ./prod.env; set +a
git fetch --tags && git checkout v1.x.y      # read the release notes first
docker compose build --pull
docker compose up -d --wait                  # the worker and the cron restart with the new image
make db-migrate
```

## 7. Changing a key

- `APP_ENCRYPTION_KEYS`, `APP_HASH_PEPPER`: prepend a new `kid=…` and keep the old ones (all of them still decrypt
  and verify), then `docker compose up -d`.
- `APP_AGENT_SIGNING_KEY`: never replace it directly. Set `APP_AGENT_SIGNING_KEY_NEXT`, run `make console-key-rotate`
  until every agent has moved, then make the new key the current one (`docs/cadrage.md`, decision 79).

## Behind a reverse proxy

Set `SYMFONY_TRUSTED_PROXIES` to its addresses, so that the client IP (rate limits, audit log) is the real one. Caddy
must still receive HTTPS, or set `SERVER_NAME=:80` and let the proxy terminate TLS.

# Installing the console in production

One server with Docker Engine and the Compose plugin, a DNS name pointing to it, ports 80 and 443 open (Caddy
obtains and renews the Let's Encrypt certificate by itself), and an SMTP account with TLS. Everything runs in
containers: `php` (FrankenPHP + Caddy), `worker` (asynchronous queues), `cron` (scheduled commands), `database`
(PostgreSQL 18).

## 1. Get the code

```bash
git clone --recurse-submodules https://github.com/DependencyVerifier/console.git
cd console
git checkout v1.0.2   # a release tag, never main
```

## 2. Install

```bash
bin/install-console
```

The script checks the host first (Docker Engine and the Compose plugin ≥ 2.24, `make`, `git`, `openssl`, ports 80 and
443, disk space) and stops on anything missing, with the command to install it: it installs nothing on the host
itself (`bin/install-console --check` runs only these checks).

The first run creates `prod.env` (mode 600) from `prod.env.dist` and stops. Fill `SERVER_NAME`, `DEFAULT_URI`,
`MAILER_DSN` and `MAILER_FROM` (every value is explained in the file), then run it again. It then:

1. generates the secrets left empty in `prod.env` (`APP_SECRET`, `APP_ENCRYPTION_KEYS`, `APP_HASH_PEPPER`,
   `APP_AGENT_SIGNING_KEY`, the three database passwords) and never replaces a value already set;
2. writes `.docker/database/owner.env` (the owner role URL, passed by the Makefile to the migrations only) from
   `DEPVERIFY_OWNER_PASSWORD`;
3. builds the images, starts the database, aligns the database roles with `prod.env` (`make db-roles`), starts
   `php`, `worker` and `cron`, runs the migrations (`make db-migrate`), creates the audit log partitions
   (`make audit-partitions`) and fetches the latest verified agent release (`make agent-release-sync`).

**Back up `prod.env` and `owner.env` outside the server now.** Without `APP_ENCRYPTION_KEYS` the stored secrets
(webhook secrets, TOTP) cannot be read; without `APP_AGENT_SIGNING_KEY` every agent must be installed again.

Create the first account (the password is typed hidden):

```bash
bin/install-console --super-admin you@example.com
```

Every manual command (`make …`, `docker compose …`) runs in a shell where `prod.env` is loaded:

```bash
set -a; . ./prod.env; set +a
```

Open `https://<SERVER_NAME>`, sign in, set up your second factor (TOTP or a passkey), create the first team.

## 3. What runs by itself

The `cron` service runs `frankenphp/crontab` as `www-data`: agent maintenance and notifications every 5 minutes,
inventory maintenance hourly, vulnerability data every 6 hours, end-of-life data, findings refresh, agent release
sync and digests daily. `docker compose logs cron` shows their output. Nothing else is needed.

## 4. Backups

| What | How | Why |
|---|---|---|
| `prod.env`, `owner.env` | a copy outside the server (password manager, vault) | the keys of everything stored |
| PostgreSQL | `make db-dump DUMP=console-$(date +%F).sql`, then copy `var/console-….sql` away | accounts, teams, inventories, audit log |
| Caddy data (`caddy_data` volume) | optional | certificates are obtained again if lost |

The agent releases volume is rebuilt by `make agent-release-sync`. To restore a dump on a fresh server: steps 1–2,
then `make db-import DUMP=<file>.sql` (the file in `var/`), then `make db-migrate`.

## 5. Upgrading

```bash
git fetch --tags && git checkout v1.x.y      # read the release notes first
bin/install-console                          # rebuilds, restarts the worker and the cron, migrates
```

## 6. Changing a key

- `APP_ENCRYPTION_KEYS`, `APP_HASH_PEPPER`: prepend a new `kid=…` and keep the old ones (all of them still decrypt
  and verify), then `docker compose up -d`.
- `APP_AGENT_SIGNING_KEY`: never replace it directly. Set `APP_AGENT_SIGNING_KEY_NEXT`, run `make console-key-rotate`
  until every agent has moved, then make the new key the current one (`docs/cadrage.md`, decision 79).
- `DEPVERIFY_APP_PASSWORD`, `DEPVERIFY_OWNER_PASSWORD`: the roles are created only on a fresh database volume, so a
  new value in `prod.env` changes nothing by itself: run `bin/install-console` again (it realigns the roles and
  `owner.env`).

## Troubleshooting

`password authentication failed for user "depverify_app"` (or `depverify_owner`): the database volume was
initialised with other passwords, for instance by a first `docker compose up` in a shell where `prod.env` was not
loaded (`COMPOSE_FILE` unset, default passwords). The volume keeps its roles across restarts: run
`bin/install-console` again, it resets both roles to the passwords of `prod.env` (`make db-roles`, idempotent).

## Behind a reverse proxy

Set `SYMFONY_TRUSTED_PROXIES` to its addresses, so that the client IP (rate limits, audit log) is the real one. Caddy
must still receive HTTPS, or set `SERVER_NAME=:80` and let the proxy terminate TLS (it then targets port 80 of the
server, sends `X-Forwarded-For` and `X-Forwarded-Proto`, and accepts 20 MB request bodies; `DEFAULT_URI` stays the
public `https://` address). `bin/install-console --check` warns when one of these settings is missing.

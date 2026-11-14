# Rolling out DependencyVerifier on your fleet

From an empty server to every machine reporting: install the console, organise it, install an agent on each server
(or Compose project), approve, then let it run. [INSTALL.md](INSTALL.md) holds the reference for the console itself;
this guide is the whole path.

## 0. What you need

- **For the console**: one Linux server with Docker Engine and the Compose plugin (2 vCPU and 4 GB of RAM are enough
  for a few hundred agents), a DNS name pointing to it (`console.example.com`), ports 80 and 443 open from the
  Internet (Caddy obtains its Let's Encrypt certificate by itself).
- **An SMTP account with TLS** (`smtps://` or STARTTLS). Mandatory: email is a second factor.
- **On every monitored server**: only **outbound HTTPS** to the console. No inbound port.

## 1. Install the console

Follow [INSTALL.md](INSTALL.md), steps 1 to 3. In short:

```bash
git clone --recurse-submodules https://github.com/DependencyVerifier/console.git
cd console && git checkout v1.0.0
cp prod.env.dist prod.env && chmod 600 prod.env
cp .docker/database/owner.env.dist .docker/database/owner.env && chmod 600 .docker/database/owner.env
# fill both files (SERVER_NAME, DEFAULT_URI, MAILER_DSN, MAILER_FROM, the generated secrets)

set -a; . ./prod.env; set +a        # in every new shell
docker compose build --pull
docker compose up -d --wait
make db-migrate
make audit-partitions
make agent-release-sync
make super-admin email=you@example.com
```

> **Back up `prod.env` outside the server now.** Without `APP_ENCRYPTION_KEYS` the stored secrets (webhooks, TOTP)
> cannot be read; without `APP_AGENT_SIGNING_KEY` every agent must be installed again.

Open `https://console.example.com`, sign in (password, then the code sent by email), and in **Account security** set
up a TOTP application or a passkey. Keep your backup codes.

## 2. Organise the console before the agents

1. **Administration → Teams → Create a team**: one team per entity or customer, with its admin.
2. In the team, **Projects → New project**, then the **Environments** tab: `production`, `staging`… **Tick
   "production"** on the real production environments: it drives the home page and the production-first views.
3. **Team members → Invite member** for your colleagues (admin, member, viewer, guest).

## 3. Install the agents

Everything starts from **Enrollment → New token** in the team:

- a **single-use** token: one server, valid one hour;
- a **reusable** token: several servers, a default environment, a lifetime and a maximum number of uses. The handy one
  for a fleet.

The token is shown once. Its dialog gives the ready-to-paste commands, with the console URL and key filled in.

### a) A regular server (systemd): `host` mode

On each server:

```bash
curl --proto '=https' --tlsv1.2 -fsSL https://console.example.com/install.sh | sudo AGENT_TOKEN='dvet_…' sh
```

The script verifies the SHA-256 of the binary, installs it in `/usr/local/bin/depverify-agent`, creates the
`depverify-agent` system user and service, and prints **the agent fingerprint**: write it down.

The agent reports the OS packages (dpkg, apk, rpm), PHP and its FPM pools, Node, Composer, and the applications found
in `/var/www`, `/srv` and `/opt`. If the server also runs containers, add `AGENT_DOCKER=1` (preferably behind a
read-only socket proxy).

From the agent's page in the console: **Configure** adds application paths or exclusions, **Update** upgrades the
agent remotely when a new release is available.

### b) A Docker Compose project: `container` mode, nothing installed on the host

Add the snippet shown in the token dialog to your `compose.yaml` (the agent service pinned by digest, and a read-only
Docker socket proxy), put the token in `.agent_token`, then `docker compose up -d`. See also
[`agent-image`](https://github.com/DependencyVerifier/agent-image).

Docker labels refine what is monitored:

| Label | Effect |
|---|---|
| `dev.depverify.monitor: "false"` | excludes a container |
| `dev.depverify.name: "Public API"` | name shown in the console |
| `dev.depverify.environment: "<environment UUID>"` | attaches the service automatically |
| `dev.depverify.paths: "/var/www/api"` | where to look for dependencies |

`AGENT_SCOPE` sets the perimeter: `compose` (default, the whole Compose project), `labeled` (labelled containers
only), `host` (the whole Docker host, one agent).

### c) Last resort, a PaaS or a restrictive host: `embedded` mode

The agent is copied into your application's image (the Dockerfile is in the token dialog). Use a reusable token and a
volume on `/var/lib/agent`: each replica becomes an agent.

## 4. Approve

In **Agents**, every new agent is "Waiting for approval". **⋯ → Approve**, **compare the fingerprint** with the one
written down at install time (`journalctl -u depverify-agent | grep fingerprint`), approve.

The agent sends its first inventory at once, then one every 6 hours, on every image change, and on demand (**Scan
now**).

**Attach the applications** to their environments (application page → **Attach**) when neither the token nor the
labels did it. Production findings then show on the home page and on the team overview.

## 5. Alerts

In a project, **Notifications**:

- **New rule**: events (vulnerability found or fixed, end of life, outdated dependency, expiring acceptance),
  thresholds (severity, CVSS, EPSS, KEV), environment, delivery by email to the subscribers or by signed webhook;
- **your subscription**: immediate, daily or weekly;
- **Add a recipient**: someone without an account (confirmed by email).

Fleet-wide rules (silent agent, new agent to approve) live in the team's **Notifications** section.

## 6. Day to day

- **Nothing to schedule**: the `cron` service syncs vulnerabilities (every 6 hours), end-of-life data, findings,
  notifications, agent maintenance and the agent release. `docker compose logs cron` shows what it does.
- **Back up PostgreSQL**: `make db-dump DUMP=console-$(date +%F).sql` (with `prod.env` loaded), then copy
  `var/console-….sql` away; keep `prod.env` and `owner.env` safe.
- **Upgrade the console**: read the release notes, then
  `git fetch --tags && git checkout v1.x.y && docker compose build --pull && docker compose up -d --wait && make db-migrate`.
- **Upgrade the agents**: systemd agents from the console (**Update**); container agents by changing the digest of
  their image.
- **Change the console signing key**: never replace it directly, follow [INSTALL.md](INSTALL.md#7-changing-a-key)
  (`APP_AGENT_SIGNING_KEY_NEXT`, then `make console-key-rotate`).

## Recommended order

1. The console and your account.
2. A team, its projects and environments, production ticked.
3. **One** test server with a single-use token, to validate the network path and the approval.
4. One reusable token per environment, then the rest of the fleet with your usual tool (Ansible, an SSH loop…)
   running the same `curl … | sudo AGENT_TOKEN=… sh`.
5. The notification rules, once the first inventories are in.

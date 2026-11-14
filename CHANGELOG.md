# Changelog

## 1.0.0 (unreleased)

First release: a self-hosted console that shows what really runs in production across teams, and what threatens it.

**Accounts and security**
- Invitation-only accounts, email + password then a second factor at every sign-in (TOTP or an email code, backup
  codes, 30-day trusted devices); optional passkeys, to sign in alone, as the second factor or to confirm a sensitive
  action. Notification-only accounts.
- Teams with quotas, projects, environments (production marked), team and project roles with grantable extras.
- Append-only audit log of every action, refusal and sign-in.

**Agents** (agent 0.3.0)
- Enrollment with single or reusable tokens, human approval, pinned console key, signed requests and orders.
- Modes `host` (systemd, `install.sh` verified by SHA-256 and minisign), `container` (official image, read-only
  Docker proxy, Compose scopes and `dev.depverify.*` labels) and `embedded` (inside an application's image).
- Inventories of OS packages (dpkg, apk, rpm incl. SUSE's ndb), PHP / Composer and Node dependencies, runtimes and
  PHP-FPM pools; every 6 hours, on demand and on an image change; 90-day history.
- Remote upgrades, agent and console key rotations.

**Exposure**
- Vulnerabilities from OSV (Packagist, npm, Debian, Ubuntu, Alpine, AlmaLinux, Rocky, RHEL with its EUS / E4S / AUS /
  TUS channels, SUSE and openSUSE), CISA KEV and EPSS; ends of life from endoflife.date; outdated direct dependencies.
- Findings in their context (application, host, project / environment, production), risk acceptance with expiry,
  dashboards per team, project and environment, a production-first home page, the components matrix of an
  environment, advisory pages.

**Notifications**
- Rules per project or for the fleet, email and signed webhooks, daily or weekly digests, silent agents, expiring
  acceptances.

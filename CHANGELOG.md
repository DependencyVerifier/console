# Changelog

## 1.1.0 (2026-11-27)

**Exposure**
- Ends of life of Python, Go, Ruby and Rust runtimes, and of the JDKs by their distribution (Eclipse Temurin, Amazon
  Corretto, Azul Zulu, Red Hat, Microsoft, Oracle).
- The findings of an application or a host hide the fixed ones by default ("Show fixed" brings them back).
- An end of life points to the newest supported cycle, never to the last patch of the cycle that ends ("—" when the
  installed cycle is already the newest).

**Agents and hosts**
- A host shows the name its inventories observe, and its row menu offers "Edit" (display name, IP addresses) to whoever
  holds `host.edit`.
- A host first known by its hostname takes the identity of its Docker engine once an inventory reports it, so a later
  enrollment of the same machine finds the same host.

**Accounts and security**
- Sessions now expire after 2 hours of inactivity (was 30 minutes); the 12-hour absolute limit is unchanged.
- A new password is typed once, with a "Show password" button, its rules under the field, and the account's email
  beside it for password managers. The same button is on the sign-in page.
- Passkeys are offered in the autofill of the email field on the sign-in page, where the browser supports it.
- New passkeys must be discoverable (resident), so each of them can sign in without the email. Passkeys already
  registered are unchanged.
- Removing a passkey also removes it from the password manager, where the browser supports it.
- A cancelled passkey prompt no longer shows an error; a passkey already on the device has its own message.

**Interface and accessibility**
- Dark theme for scrollbars and native controls; the theme button names the current theme and the next one, follows
  the other tabs, and charts take the new colours at once.
- Skip link to the content, main navigation landmark, focus rings kept in Windows high contrast mode, animations
  reduced when the system asks for it.
- Dialogs: a click inside their padding no longer closes them, a form dialog never closes on a click outside (no typed
  value lost), Enter on its close button closes it, Esc and the back gesture close them with their animation, the page
  behind no longer scrolls.
- Row and account menus open above the page where the browser supports it (never cut by a table or the mobile sidebar),
  and Esc in a menu no longer closes the mobile sidebar too.
- Tooltips on hover and on keyboard focus instead of `title` attributes: collapsed sidebar, disabled buttons, EPSS,
  version drift, agent upgrade and key rotation. Identifiers (audit targets, digests) stay whole on the page, shortened
  on screen; a package shows its purl.
- After a refused form, the focus goes to the first invalid field.
- Tables, gauges and loading zones are named by their visible title; secondary columns hide by the width of their card.

## 1.0.3 (2026-11-15)
## 1.0.3 (2026-11-15)

**Installation**
- Fixed: the emails sent from the web (password reset, sign-in codes, invitations) failed with "certificate verify
  failed" in the production image. The `frankenphp` binary carries `cap_net_bind_service`, so OpenSSL ignores
  `SSL_CERT_FILE` there and looks in `/usr/lib/ssl`, absent from the slim image: it now points to the system CA
  certificates. The CLI (worker, cron) was not affected.

## 1.0.2 (2026-11-15)

**Installation**
- Fixed: the production image did not build the front assets (`public/build`, git-ignored), so on a fresh clone the
  pages rendered without any stylesheet or script. A Node stage of the `Dockerfile` now runs `yarn build` before the
  cache warmup, and `public/build` and `node_modules` are no longer taken from the build context.
- Fixed: behind a reverse proxy that terminates TLS (`SERVER_NAME=:80`), `bin/install-console` no longer warns that
  `:80` does not resolve; it warns instead when `SYMFONY_TRUSTED_PROXIES` is empty or `DEFAULT_URI` is not an
  `https://` address. Its summary shows `DEFAULT_URI`. `INSTALL.md` lists what the reverse proxy must do (port 80,
  `X-Forwarded-For` and `X-Forwarded-Proto`, 20 MB request bodies).

## 1.0.1 (2026-11-15)

**Installation**
- `bin/install-console` installs and upgrades the console in one command: it checks the host (Docker and Compose
  ≥ 2.24, `make`, `git`, `openssl`, ports 80 / 443) and stops on anything missing, creates `prod.env`, generates
  the secrets left empty without ever replacing one, writes `owner.env`, then builds, starts and migrates.
  `--check` runs the checks only, `--super-admin <email>` creates the first account. `INSTALL.md` now uses it.
- Fixed: a database volume initialised with other passwords than `prod.env` (`password authentication failed for
  user "depverify_app"`). The script realigns the database roles with `prod.env` on every run, and the containers
  stop at once on this error, pointing to the fix, instead of retrying for a minute.

**Documentation**
- `GUIDE.md`: the whole rollout, from an empty server to every machine reporting (console, teams, agents, approval).

## 1.0.0 (2026-11-14)

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

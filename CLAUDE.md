# DependencyVerifier console (`console/`)

Symfony web app, licensed **AGPL-3.0-only**. Multi-team UI, signed agent API, inventory store, matching engine, notifications.

> **Spec and design** live in the [`DependencyVerifier/.github`](https://github.com/DependencyVerifier/.github) repository: `docs/cadrage.md` (spec, French), `docs/design.md` (UI charter), `docs/brand/` (logo, social images). Any `docs/…` path in this repository refers to that repository.

**Read first:** `docs/cadrage.md` §5 (data model), §7 / §7bis (security, auth, permissions), §8 (analysis engine), §9 / §9bis (stack, architecture, sizing); `docs/design.md` for every UI change. Generic Symfony guidance from the skeleton: `AGENTS.md`.

Local URL: **https://console.depverify.localhost** (Caddy local CA: trust it or accept the certificate in the browser). Health: `/_healthcheck`.

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
| `Shared\` | Kernel, CQRS buses, health probes, routing helpers, web shell (layouts, sidebar, footer, home), UI foundation (Turbo Frames, ActionResponder, CSRF rejection) |
| `Identity\` | Accounts, authentication (password + TOTP / email code, optional passkeys), invitations, notification-only accounts |
| `Organization\` | Teams, quotas, projects, environments, memberships, permissions (`PermissionResolver`, voters) |
| `Fleet\` | Hosts, agents, enrollment tokens, signed agent API (`/agent/v1`), orders (cloud scopes later) |
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
- `command.bus` middleware order (`config/packages/messenger.yaml`, do not reorder): `PublishDomainEvents` (outermost: publishes collected domain events after the commit; a failing reaction is audited as `event.handler.failed`, never requalifies the command) → `AuditFailure` (writes the `failure` / `denied` entry after the rollback) → `ResetUnitOfWorkOnFailure` (resolves the audit scope, then clears the EntityManager) → Doctrine ping / close / transaction → `LockTimeout` (`SET LOCAL lock_timeout '5s'`) → `AuditSuccess` (innermost: `success` entry inside the transaction). `query.bus` has `AuditDeniedQueryMiddleware`; `event.bus` has **no transaction** (it belongs to the command).
- An audited refusal or failure always leaves a bus as `Shared\Application\Audit\AlreadyAudited`: read `cause()` (the business exception) and never audit it again. Commands declare their audit through `AuditableCommand`; refusals are `PermissionDenied` (403), `ResourceNotFound` (404) or a `Shared\Domain\BusinessRuleViolated` subclass (422, `rule:<ruleKey>`).
- Aggregates implement `RecordsEvents` with the `EventRecording` trait; `DomainEventCollector` (Doctrine `onFlush`) gathers the events.
- **No event handler changes persistent state**: it dispatches a command, queues a deferred email, or republishes an integration event.
- Naming: `Application/Command/<Feature>/<VerbNoun>.php` → `Application/Command/<Feature>/Handler/<VerbNoun>Handler.php`, same for `Query/`. Example: `Shared\Application\Health\Query\GetHealthReport` + `Handler\GetHealthReportHandler`.

## UI conventions

- Controllers: invokable, `#[Route(path: …, name: RouteCollection::CASE->value)]` with the context's `UI\Controller\RouteCollection` enum (implements `Shared\UI\Routing\RouteCollectionInterface`). Use `->prefixed()` for `path()` / redirects.
- Templates: `templates/<context>/…` (snake_case). Kit and app components in `templates/components/` (`AppSidebar`, `AppFooter`, `MetaGrid`, `StatusDot`, `SeverityBadge`, `ThemeToggle`, `RomainMillanTag`). Layouts: `shared/layout/app.html.twig` (sidebar shell + footer) and `shared/layout/auth.html.twig`. Page blocks are `breadcrumb` and `main`: `content` is reserved inside component tags.
- Footer signature: the **Romain-MILLAN-Tag git submodule** (`assets/romain-millan-tag`), imported in `assets/styles/app.css` with `layer(base)` and rendered by `<twig:RomainMillanTag />`. Update it with `git submodule update --remote console/assets/romain-millan-tag`.
- **No external resource** (fonts, scripts, CDN): everything is served by the instance. FrankenPHP's CDN hot-reload snippet was removed on purpose; Vite handles HMR (reload the page by hand after a Twig change).
- **Front-end code is TypeScript only**: no `.js` file in `assets/`. `make typecheck` (strict `tsc`, in `make qa`) fails if one appears. Stimulus controllers are `assets/controllers/<name>_controller.ts` with `declare` typed targets and values; kit controllers coming from `make ux` must be converted. Config in `tsconfig.json`, Vite config in `vite.config.ts`.
- **No Mercure**: removed from Caddy, Compose and Turbo (no real-time hub in this project). Don't reinstall `symfony/mercure-bundle`.
- **Listings are DataTables** (`omines/datatables-bundle`, `datatables.net-dt`): a `UI\DataTable\<Name>Table` type whose adapter is `Shared\UI\DataTable\QueryBusAdapter` (it asks a query taking a `Shared\Application\Query\PageRequest` and answering a `Page`: the query handler stays the only judge of scope). The page controller accepts `GET|POST`, builds its tables and returns `$table->getResponse()` on a callback; the template renders `<twig:DataTable :table>` (`datatable_controller.ts` posts `_dt` to the page URL and reloads after any Turbo Stream). Action buttons live in Twig cells (`templates/<context>/cells/`), with CSRF token ids bound to the row (`team_member_remove:<userId>`). Sort keys go through `PageRequest::sortAmong()`, search is escaped for `LIKE`.
- **Turbo Frames (decision 31)**: the zones that are not listings (quota gauges, audit log) are lazy frames rendered with `shared/frame/lazy.html.twig` (Skeleton placeholder + server-rendered error template, `frame_state_controller.ts`). A context declares its frames in a `UI\Frame\TurboFrame` enum (`Shared\UI\Frame\Frame`), with **exactly one** `FrameView` per case (it asks the query bus: the query handler is the only judge). Frame endpoints go through `FrameEndpoint` (303 to the fallback page without `Turbo-Frame`, `private, no-store`, `Vary: Accept, Turbo-Frame`); refusals are rendered in the frame by `FrameExceptionRenderer`. Frame route names end with `_frame`.
- **Actions of the authenticated area** go through `Shared\UI\Action\ActionResponder::handle()`: CSRF failure → `CsrfRejection` (403, one `csrf.rejected` entry), invalid form → 422, refusal (`AlreadyAudited`) → the form again with the status of `RefusalResponseTable`, success → `StreamOrRedirect` (Turbo Stream refreshing the frames of the context's `ActionFrameMap`, else 303). A button alone is a field-less `ActionButtonType` form with its own CSRF token id. Commands showing a secret once implement `RevealsSecret` and need `SecretRevealingResponse` (200, `no-store`, never a redirect or the session). The command closure takes no argument: read the validated fields with `FormData::string()`.
- **Creation forms open in a dialog**: `<twig:FormDialog id title trigger src>` (generic, one per form) holds a lazy frame `<id>-form` loading the form from its own GET|POST endpoint, which only calls `Shared\UI\Action\DialogForm::handle()` (GET: the form in the frame, 303 to the page without `Turbo-Frame`; POST: `ActionResponder`, 422 and refusals stay in the dialog, a success answers the Turbo Stream and `form_dialog_controller.ts` closes the dialog). The form is reloaded at each opening (fresh CSRF token). Example: `AdminTeamCreateController`.
- The authentication pages (login, 2FA, `/reauthenticate`, password forgot / reset, invitations) are plain pages (`data-turbo="false"`) that use the same `CsrfRejection` and refusal table.
- Form theme: `shared/form/theme.html.twig`; refusals added by the app are translation keys. UI strings go through the translator (`translations/messages+intl-icu.en.yaml`, emails in `email+intl-icu.en.yaml`).
- The authenticated layout sets `turbo-cache-control: no-cache` and `turbo-prefetch: false`. Never a `style` attribute in templates (CSP, `NoInlineStyleTest`), kit components included (CSS variables become `[--var:value]` classes).

## Healthcheck

`GET /_healthcheck` (`Shared\UI\Controller\HealthcheckController`) asks `GetHealthReport` on the query bus. That handler runs every `Shared\Application\Health\HealthProbe` (`#[AutoconfigureTag]`; today `DatabaseHealthProbe`, `SELECT 1`).
- Healthy: 200 with the page adapted from Romain-MILLAN-Healthcheck (`templates/shared/healthcheck.html.twig`, standalone, no web font, tag CSS inlined from the submodule).
- Unhealthy: 503 JSON `{"status":"down","checks":{…}}`, without failure details.
- Always `no-store` and `noindex`. The `php` container healthcheck (`compose.yaml`) calls it over `http://php/_healthcheck`. Add a probe per critical dependency (queues, mailer…); never weaken it. Keep it public when the firewall arrives.

## Database roles, audit log, rate limiting

- **Two PostgreSQL roles**, created by `.docker/database/roles.sh` (run by `initdb` on a fresh volume, `make db-roles` on an existing one):
  - `depverify_app`: runtime role of the web workers (`DATABASE_URL`), no DDL, `INSERT` / `SELECT` only on `audit.*`;
  - `depverify_owner`: owns the database, runs migrations and audit partition maintenance, **CLI only**. Its URL (`DATABASE_MIGRATIONS_URL`) lives in `.docker/database/owner.env` (git-ignored, copied from `owner.env.dist`), is never in a `.env*` file nor visible to the web container, and is injected by the Makefile into `db-migrate`, `db-diff`, `db-migrate-prev`, `db-reset`, `db-test-prepare` and `audit-partitions`. `OwnerRoleGuardDriverMiddleware` refuses the owner role outside the CLI.
  - The FrankenPHP entrypoint therefore **does not migrate**: run `make db-migrate` (part of `make start`, and a CI step).
- **Audit log** (`audit.audit_log`, `Audit\Infrastructure\DbalAuditTrail`): append-only, monthly partitions plus `DEFAULT`, row and `TRUNCATE` triggers refuse every change. `make audit-partitions` (`app:audit:maintain-partitions`, owner role) creates the next 12 months, moves `DEFAULT` rows and drops partitions older than `AUDIT_RETENTION_DAYS`. Never put a password, token, code, secret or request body in an audit context (typed, allow-listed keys, 4 KB max).
- **Rate limiting**: always `symfony/rate-limiter` through the `AttemptThrottle` port (never `RateLimiterFactory` in Application). States in `rate_limiter_state` via `DbalRateLimiterStorage`, on the dedicated autocommit `rate_limiter` connection (`lock_timeout 2s`, `statement_timeout 5s`), **fail-closed** (`RateLimiterStorageUnavailable`) and never retried, because the same connection holds the advisory locks (`ExclusiveSection`). Keys are HMAC-hashed. `make rate-limiter-prune` deletes expired states.
- **Secrets** (fail-closed outside dev/test): `APP_SECRET` (≥ 32 bytes), `APP_ENCRYPTION_KEYS` (`kid=base64,…`, AES-256-GCM `SecretBox`, first key encrypts), `APP_HASH_PEPPER` (same format, `TokenHasher` HMAC-SHA-256), `APP_AGENT_SIGNING_KEY` (base64 of a 32-byte Ed25519 seed, the console key agents pin). Values only in `.env.dev` / `.env.test`.
- **HTTP headers** (`SecurityHeadersSubscriber`): strict CSP with a per-request nonce (`csp_nonce()` in Twig, `CspNonce`), no `'unsafe-inline'`, so **no `style=` attribute and no inline script without nonce** in templates; `Referrer-Policy: no-referrer`, `nosniff`, `X-Frame-Options: DENY`, HSTS outside dev, server-generated `X-Request-Id`. Trusted proxies come from `SYMFONY_TRUSTED_PROXIES` (empty by default).

## Identity (authentication)

- Login: email + password (`form_login`, CSRF id `authenticate`, `login_throttling` through `LoginRateLimiter`: account + IP 5 / 15 min, IP 50 / 15 min, a success resets the account + IP window only), then **always a second factor** (scheb/2fa): TOTP when active (`TotpProvider`), else an email code (`EmailCodeProvider`), backup codes through `BackupCodeManager` (only `xxxxx-xxxxx` inputs are dispatched). Trusted device: 30 days, `__Host-depverify_trusted`, revoked by `trustedDeviceVersion`. The providers only dispatch `VerifyTotp` / `VerifyEmailLoginCode` / `UseBackupCode` and read `GetCodeVerificationOutcome`: the single algorithm is `Identity\Application\SecondFactor\SecondFactorAttempt` (User lock, IP exclusive section, peek, aggregate, consume on `Rejected` only, one audit entry).
- `SecurityUser` is a snapshot whose session form holds id, email, authVersion and super admin flag only. Bumping `SessionVersions` (every credential or second factor change, deactivation, super admin revocation) logs every session out.
- scheb redirects to the target page first; the 2FA form comes at the next request. The first-factor post-authentication event is suppressed by scheb: account checks are in `checkPreAuth` (with a dummy hash for equal timing).
- Sessions in PostgreSQL (`sessions`, `PdoSessionHandler` on a native PDO DSN with session timeouts, `__Host-depverify_session`, Secure: functional tests use HTTPS). 30 min idle / 12 h absolute in `SessionLifetimeSubscriber`. Sensitive actions carry `#[RequiresRecentAuthentication(returnRoute: …)]` (10 min).
- Emails leave after the response (`DeferredMailQueue`, `mailer.message_bus: false`); in dev they land in Mailpit (http://localhost:8025, `make mailpit`).
- `access_control`: several roles in one rule mean **any** of them; `/admin` requires `ROLE_SUPER_ADMIN`, which only a fully authenticated token carries.
- First account: `make super-admin email=you@example.com` (runtime role, hidden password or `--password-file` with mode 600).
- Permissions: `InstancePermissionChecker` answers instance permissions (super admin: everything; others: `account.login`…) and is published to the other contexts as the `Shared\Application\Authorization\InstancePermissions` port.
- **Emailed links** (password reset, invitation) land on a public page that swaps the token for an id in the session and answers a relay page (`identity/security/continue.html.twig`: meta refresh + Continue link), never an HTTP redirect: with `SameSite=Strict` a redirect right after a cross-site click would arrive without the session cookie.
- Deferred exclusion constraints (`identity_email_login_code_one_active`, `organization_invitation_one_pending`) are invisible to Doctrine: `make db-diff` proposes to drop their indexes, delete those lines.

## Organization (teams, projects, permissions, quotas)

- Aggregates: `Team` (root of `TeamMembership`, holds `TeamQuotas` copied from `InstanceSettings` at creation, `adminEstablished` once a first `TEAM_ADMIN` joins), `Project` (root of `ProjectMembership` and `Environment`), `Invitation` (7 days, hashed single-use token, one pending per email and target), `PermissionGrant` (append-only business history, beside the audit), `InstanceSettings` (default quotas).
- **The team is the consistency boundary**: every command touching memberships, invitations, projects, environments or quotas locks the team first (`OrganizationAccess::lockTeam()`, `SELECT … FOR UPDATE`, several teams in id order). A child object (invitation, environment, membership) is loaded first, its **real** team / project decides the permission check, never the ids of the URL; out of scope is `ResourceNotFound` (404), visible but not allowed is `PermissionDenied` (403).
- Roles and permissions: `RolePermissions` is the §7bis matrix (defaults ✓ and grantable extras ○, one test per cell); `EffectivePermissions` resolves the actor's grants (super admin short-circuits); `RoleAuthority` enforces the rank (`TeamRole::canAssign()`, `ProjectRole::canAssign()`: equal rank allowed, a `TEAM_ADMIN` covers every project role). An extra permission is granted only if it is ○ for the role and held by the granter; revoking needs no holding.
- `OrganizationPermissionChecker` (the `PermissionChecker` used by voters and frames) composes `InstancePermissions` with `DatabaseActorPermissions` (memberships read once per request, `ResetInterface`).
- Quotas: `QuotaPolicy` blocks at 100 %, super admin included (`QuotaExceeded`, 422, `rule:quota`). Enforced here: `maxProjects`, `maxEnvironmentsPerProject`, `maxMembers` (active members + pending invitations, checked at sending and at acceptance, the accepted invitation not counted twice). `max_agents` and `max_reusable_enrollment_tokens` are enforced by Fleet (their gauges through `QuotaUsageProvider`); the other quotas are stored for their future contexts.
- **Creations are audited on their parent**: `CreateProject` targets the team (`project_id` in the context), `CreateEnvironment` the project (`environment_id`), `InviteMember` the team or the project: a refused creation still has a team scope.
- Identity collaborates only through contracts: `FindPendingInvitation`, `AcceptInvitation`, `FindAccounts` (member emails, never a shared table), `SearchAccounts` (account pickers), the port `AccountProvisioning` (called **inside** `CreateTeam`, same transaction: the first TEAM_ADMIN is an existing account or one invited by Identity, admitted at once; never a nested bus dispatch, the command bus is single-level), and the integration events `AccountDeactivated` / `AccountReactivated`, which `CascadeAccountStatus` turns into `SuspendMembershipsOfUser` / `RestoreMembershipsOfUser` (system actor, idempotent). The invitation flow is orchestrated by Identity's `Invitation*Controller`s; a project invitation for a non-member also creates a `TEAM_GUEST` membership.
- A project or an environment is deleted only when empty: no environment, and no `Shared\Application\Content\ProjectContentProbe` (tagged; Fleet: the default environment of a usable token) declaring content. A deleted team calls every `TeamContentEraser` (tagged; Fleet erases its fleet) inside `DeleteTeam`.
- Test doubles: `tests/Organization/Support` (`World` builds the in-memory repositories and the actor's permissions; `Seed` writes functional fixtures through the aggregates).

## Fleet (enrollment, signed agent API, orders)

Normative contract: `docs/protocol.md` (v1) and its test vectors, copied in `tests/Fleet/Fixtures/protocol` and reproduced byte for byte (`SignaturesTest`, `RequestVerifierTest`, `ProtocolTest`). Decisions: `docs/cadrage.md` decisions 21–34.
- Aggregates: `EnrollmentToken` (single: 1 h, 1 agent; reusable: environment, ≤ 90 days, max uses, `pending → approved → revoked`), `Host` (found or registered at enrollment by `HostFacts::identityFor()`), `Agent` (`pending`, `approved`, `inactive`, `revoked`; one live agent per key fingerprint, partial unique index), `AgentOrder` (closed catalogue `SCAN_NOW` / `SET_CONFIG`, signed at its first delivery, delivered again with the same signature until its result).
- Crypto: `ConsoleSigner` / `SignatureVerifier` ports (sodium adapters), `CanonicalJson` (RFC 8785) signs `order`; `APP_AGENT_SIGNING_KEY` is the console key the agents **pin**: changing it means re-installing every agent.
- **Agent API** (`/agent/v1`, firewall `agent`, stateless, no CSRF, no session): the enrollment is public, `EnrollAgent` checks the proof of possession itself; every other request goes through `AgentRequestAuthenticator`, which runs `RequestVerifier` (protocol §4.3 in order: profile, clock ±300 s, Content-Digest, key, signature, nonce), refuses a revoked agent, consumes the per-agent limiter **after** the signature, and authenticates an `AgentUser` (`ActorAwareUser`: writes are audited for the agent). Every error under `/agent/v1/` is `application/problem+json` (`AgentApiExceptionSubscriber`, `ProblemResponse`), never a redirect; the rule key of a refusal is its problem code (`Problem::ofRule()`). Bodies are read field by field (`Shared\Application\Protocol\AgentPayload`, shared with Inventory, unknown fields ignored; 1 MiB max, 20 MiB for a snapshot upload: `RequestVerifier::maxBytesOf()`, Caddy `request_body` and a `Content-Length` precheck), never logged nor audited.
- Responses after a command: the bus returns nothing, so the controller asks a query afterwards (`FindEnrolledAgent`, `GetPollAnswer`). A plain poll (`RecordAgentPoll`) is a `SelfAuditedCommand` that writes no entry; its refusals are audited by the bus.
- **Quotas** (`FleetQuotas`): `max_agents` counts pending + approved agents, `max_reusable_enrollment_tokens` the active reusable tokens; always **team fleet lock first** (`fleet.team:<id>`, `TransactionLock`), then read again and count. The usage is published to Organization's gauges through `QuotaUsageProvider` (`FleetQuotaUsage`); limits are read with `GetTeamQuota`.
- Organization collaboration: `FleetAccess` asks `GetTeamAccess` (404 out of scope, 403 for a `TEAM_GUEST`) then the `PermissionChecker` (`host.enroll`, `host.approve`, `host.revoke`, `host.delete`, `host.order`); the object's real team decides. `FleetTeamContent` implements `TeamContentEraser` (a deleted team takes its fleet, under the fleet lock) and `ProjectContentProbe` (an environment a usable token enrolls into cannot be deleted).
- UI: team sections reuse Organization's team frame (`FleetPage` renders `fleet/team/*` with `TeamPage`'s header and sidebar group); the `FleetView` of the page is a request attribute (`_fleet`) read by the action cells. The token is shown once in its dialog (`SecretRevealingResponse` in the frame; `form_dialog_controller.ts` closes a dialog only on a Turbo Stream). Inside `<twig:StatusDot>`, never name a variable `status` (the component's prop shadows it).
- Maintenance: `make fleet-maintain` (`app:fleet:maintain`, cron every few minutes, batches of 500): approved agents silent 7 days → `inactive`, inactive 30 days more → revoked, late orders expired, nonces older than 10 minutes purged. `fleet_agent_nonce` is raw SQL, excluded by the Doctrine `schema_filter`.
- Dev demo: `make fleet-fake-agent` (`FakeAgentCommand`, dev env only, `Fleet\Infrastructure\Dev\FakeAgent`) enrolls, polls and answers orders like the agent (pinned console key, §7.2 checks), state in `var/fake-agent/<name>.json`. It verifies orders on the JSON **as received** (objects kept: an empty payload is `{}`, never `[]`).
- Test doubles: `tests/Fleet/Support` (`FleetWorld` in memory, `AgentClient` signs requests as the agent does, `FleetSeed` for functional fixtures, `ProtocolVectors`).

## Inventory (snapshots, applications, images, packages)

Normative contract: `docs/protocol.md` §8 (manifest, snapshot upload, complete) and §8.5 (CycloneDX profile, **no `serialNumber`**). Decisions: `docs/cadrage.md` decisions 35–43.
- Aggregates: `InventoryRun` (id chosen by the agent, `open → completed → processed`, or `rejected` / `discarded`), `SnapshotBlob` (gzip `bytea`, unique per **(team, digest)**, never changed), `HostInventory` (last facts and OS SBOM of a Fleet host), `AppInstance` (identity: host + `subject_key`, stored as `subject_key_hash` for the unique index; `present` / `gone`; assignment `none` / `auto` / `manual`), `ContainerImage` (team + `image_id`), `SnapshotRecord` (history). Packages are raw rows (`inventory_package`, `DbalPackageRepository`, excluded by the Doctrine `schema_filter`), extracted **once per blob**, deleted with it.
- **Agent API** (Inventory UI controllers behind Fleet's `agent` firewall): the agent is known through Fleet's `GetAgentContext` contract (team, host, status, mode, scope, Compose project, default environment of its token). The scope guard runs on the manifest **and** at complete. `StoreSnapshot` is a `SelfAuditedCommand` (no entry per upload). `SnapshotDecoder` inflates in a stream (200 MB cap), checks the digest and the format; nothing else is parsed by the API.
- **Asynchronous processing**: `complete` publishes `InventoryCompleted`, whose handler dispatches `ProcessInventory`, routed to the Doctrine transport `ingest` (`config/packages/messenger.yaml`, `sync://` in the test env, so functional tests see `processed` at once). The Compose `worker` service consumes it: **`make worker-restart` after a code change**, `make worker-logs`; failures go to the `failed` transport. `ProcessInventoryHandler` runs under the system actor in one transaction, is idempotent, and publishes `InventoryProcessed` (for matching).
- `AuditSuccessMiddleware` skips the **send-only pass** of an asynchronous command (`SentStamp` without `ReceivedStamp`): the success entry is written once, by the worker.
- Attachment: label `dev.depverify.environment` of the team, else the token's default environment when the host has a single application; a manual attach / detach (`project.apps.assign` on the target **and** the current project) is never overwritten. The trigger `inventory_app_instance_same_team` refuses an environment of another team.
- Visibility (`InventoryAccess` → `InventoryScope`, from Organization's `GetVisibleProjects` and `ListTeamEnvironments`): a member sees the whole inventory; a `TEAM_GUEST` the applications attached to its projects and the hosts / images carrying them (`InventoryVisibility`), never the unattached ones. The read side is `InventoryReadModel` (DBAL): it filters by what the handlers give it.
- UI: team section Applications and project tab Applications (`InventoryPage` renders `inventory/team/*` in Organization's team frame), object pages for an application, a host (`/teams/{t}/hosts/{h}`, a host not inventoried yet falls back on Fleet's `FindTeamHost`) and an image, all sharing `inventory/team/_object.html.twig`. Never name a template variable `app` (Twig global): the application is `application`.
- `InventoryTeamContent` implements `TeamContentEraser` and `ProjectContentProbe` (an environment carrying applications cannot be deleted).
- Maintenance: `make inventory-maintain` (`app:inventory:maintain`, cron): history beyond `snapshotRetentionDays` (last record of each subject kept), orphan blobs, inventories left open for an hour.
- Dev demo: `make fleet-fake-agent a=inventory` sends a deterministic inventory (container mode: Compose service `demo/web` and its image; host mode: OS packages and `/srv/demo`); a `SCAN_NOW` received in a poll does the same. A second one without change uploads nothing.
- Test doubles: `tests/Inventory/Support` (`InventoryWorld` in memory, `Inv` ids and subjects, `Documents` builds SBOMs and host facts, `InMemoryReadModel`); functional tests drive Fleet's `AgentClient` (`putSnapshot()` sends gzip).

## FrankenPHP worker mode

The console runs as a long-lived FrankenPHP worker, in production too. **No mutable `static` property**; every stateful service implements `ResetInterface`; the EntityManager is reset after a failed command; connections outside the bus (rate limiter, sessions) get session-level timeouts at connect time.

## Stack

Symfony 8.1, PHP 8.5, FrankenPHP + Caddy (`dunglas/symfony-docker`), PostgreSQL 18, Doctrine ORM (UUID v7), Messenger, Twig + Symfony UX (Turbo, Stimulus, Twig/Live Components, Icons), Symfony Reprise + Vite (TypeScript), Tailwind CSS v4, UX Toolkit `shadcn` kit, self-hosted IBM Plex fonts, Lucide icons vendored in `assets/icons/`.

## Commands (`make help`, from `console/`)

```bash
make start            # submodules + build + up + vendors (first run)
make up / make stop   # containers: php, worker (ingest queue), database, node (Vite dev server :5173)
make test             # PHPUnit (prepares the test DB); FILTER=… / COVERAGE=1
make qa               # lint + typecheck + phpcs + phpstan (level max) + rector + test + infection (100% MSI): run before committing
make typecheck        # strict tsc on assets/ + vite.config.ts, and no .js allowed in assets/
make phpcsfix / make rector-fix
docker compose exec -e APP_ENV=test -e XDEBUG_MODE=coverage php vendor/bin/infection --with-uncovered --threads=max src/Organization --test-framework-options="--filter=Organization.Unit"   # one context, its unit tests only (minutes instead of an hour)
make composer c='req vendor/package'      # PHP packages (let Flex recipes do the wiring)
docker compose exec node yarn add -D …    # JS packages: always yarn via the node container, never npm
make ux r=dialog / make icon i=lucide:bell
make db-diff / make db-migrate          # migrations are organized by year: migrations/<YYYY>/Version….php (namespace DoctrineMigrations)
make db-dump DUMP=backup.sql / make db-import DUMP=backup.sql   # pg_dump / psql via .docker/database/scripts, files in var/
make db-roles / make audit-partitions / make rate-limiter-prune / make fleet-maintain / make inventory-maintain
make worker-restart / make worker-logs  # the ingest queue consumer (restart it after a code change)
make fleet-fake-agent a='enroll --token=dvet_…'   # dev demo agent (then a=poll, a='run --polls=10', a=inventory; name=… for another one)
make super-admin email=you@example.com / make mailpit
make adminer          # dev DB UI on http://localhost:8080 (system PostgreSQL, server database, user depverify_app or depverify_owner, database app)
```

CI (`.github/workflows/ci.yml`, GitHub Actions): starts the same Docker stack, runs `make qa` and the production asset build on every push to `main` and every pull request. Keep `make qa` green locally: the CI runs exactly that. Dependabot updates Composer, npm, Docker, Actions and the submodule weekly.

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
- The shadcn kit's Tailwind utilities are **vendored** in `assets/styles/vendor/shadcn-tailwind.css` (from `shadcn@4.23.0`, MIT). Do **not** install the `shadcn` npm package, even if `make ux` suggests `npm install shadcn`: it is a whole CLI whose dependencies carried vulnerabilities (deepmerge, minimatch) for a 16 KB stylesheet. Refresh the vendored file by hand when the kit needs it.
- Not installed yet (add with the feature that needs them): Scheduler, Notifier, WebAuthn (passkeys, step 1b), ExpressionLanguage.
- The kit's `input-otp` is not used: scheb expects a single code field and backup codes are alphanumeric (one `one-time-code` input instead).
- Infection runs with `--with-uncovered`: uncovered Domain / Application code counts as escaped. Doctrine mapping attributes are excluded (`global-ignoreSourceCodeByRegex`). Methods returning an outcome while changing state carry `@phpstan-impure`.
- Identity test doubles live in `tests/Identity/Support` (in-memory repositories, scripted throttle…); functional tests of the flows extend `Tests\Support\IdentityWebTestCase` (HTTPS client, limiter states emptied, typed `fetchString()` / `fetchInt()`) and create accounts with `Tests\Support\Accounts`. The test kernel reboots between requests: change data through the client's container before the request, never on an entity loaded earlier.
- Tests: integration tests run inside a DAMA transaction on the `default` connection; the `rate_limiter` connection is not static (it must see committed data). Concurrency and timeout tests commit through `tests/Support/OwnerConnection` and clean up explicitly. The test logger is a `NullLogger`: Infection aborts on any stderr output.

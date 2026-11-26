# qiick packages: adoption plan for Excubia

Excubia is the first product to adopt the betas of the shared qiick packages. This page lists, per
package, what changes in this repo, which tables move, and whether the production database
(Laravel Cloud) must be recreated. It is a plan: nothing here is built yet.

Sources, read on 2026-11-25: each package's `README.md`, `UPGRADING.md`, `CHANGELOG.md` and
`docs/` on `develop`, the package designs and standards reviews in the knowledge base
(`projects/qiick/packages/`), the decisions of 2026-11-20 to 2026-11-24, and this repo at
`caceb30b`.

## Ground rules

- **Migrations.** Excubia has no stable release, so under the decision of 2026-11-20 ("migrations
  freeze at the first stable release") a schema change edits the table's `create_` migration and
  whoever has a database recreates it. The packages follow the same rule until their `1.0.0`
  (decision of 2026-11-22). This PR aligns the repo rule in `AGENTS.md`, which still said
  "frozen from beta.1".
- **When a reset is needed.** `php artisan migrate` only runs migration files it has not run
  yet. A package that **adds a new table** in a new file needs no reset. A change to the **shape
  of a table that already exists** in production (an edited `create_` migration, or a product
  table replaced by a package table with the same name) needs `migrate:fresh`.
- **Production.** Agents never touch it. The maintainer replaces the database with a new empty
  one after a reset group is merged and deployed (`migrate:fresh` is refused in production; the
  steps are in the [Group A reset checklist](#group-a-reset-checklist)). Before each reset, save
  what must survive (see [Settings across a reset](#settings-across-a-reset)): group A re-enters
  its secrets, group B uses `settings:export`.
- **Install from tags only.** `composer require qiick/<package>:^x.y@beta`, `minimum-stability`
  stays `stable`, no `vcs` or `path` repositories, no `dev-` branches (1.0 checklist item 17d).
- **Security review.** Every adoption PR touches authentication, authorization or secrets. Each
  gets an adversarial review by a second model before merge, and red tests first where behaviour
  changes.

## Package status (2026-11-25)

| Package               | Latest tag                          | In Excubia today          | Ready to adopt     |
| --------------------- | ----------------------------------- | ------------------------- | ------------------ |
| `laravel-api-keys`    | `v0.2.0` (0.3 changes on `develop`) | no (own code)             | at `v0.3.0-beta.1` |
| `laravel-access`      | none (0.1.0 described, not tagged)  | no (own code)             | at its first tag   |
| `laravel-http-basics` | none (pre-0.1.0)                    | no (own code)             | at its first tag   |
| `laravel-outbound`    | `v0.1.1` (0.2 changes on `develop`) | `v0.1.1` (#399)           | at the 0.2 tag     |
| `laravel-oidc`        | `v1.0.0-beta.6`                     | `v1.0.0-beta.6` (#401)    | next beta (beta.7) |
| `laravel-audit`       | none (pre-0.1.0)                    | no (own table, Spatie v5) | at its first tag   |
| `laravel-settings`    | none (design only, no code)         | no (own code)             | when it exists     |

---

## 1. `qiick/laravel-api-keys` (0.3)

The package owns token issue, parsing, verification, scopes, IP allowlist, request restrictions,
rotation, quotas and the refusal format. It ships no migration and no middleware: Excubia keeps the
`api_keys` table, the model, the scope enum and a thin middleware, and implements the package
contracts.

**Code removed or replaced**

| Today                                                                                                                                                         | After                                                                                                                                                      |
| ------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `AuthenticateApiKeyMiddleware` (lookup, hash compare, failure brake, rate limit, guard switch)                                                                | thin middleware: `BearerTokenExtractor` → `ApiKeyAuthenticator::authenticate()` → bind the `api_key` guard as today; `ClientIp::from($request)` for the IP |
| `ApiKey` token helpers (`generateToken`, `publicPrefixFromToken`, `matchesPresentedToken`, `hashToken`, `allowsIp`, `isValidAllowlistEntry`, `TOKEN_PATTERN`) | `TokenCodec`, `IpAllowlist`; the model implements `ApiKeyRecord`                                                                                           |
| `ApiKeyRejectedException`                                                                                                                                     | `ApiKeyRejection` (same RFC 6750 shape and fleet `error` codes, plus `403 request_not_allowed`)                                                            |
| `RecordApiKeyUseAction`                                                                                                                                       | the package `UsageRecorder` plus a `UsageSideEffect` if Excubia still needs one                                                                            |
| `RotateApiKeyAction` internals                                                                                                                                | `RotationService::rotate()` inside a `lockForUpdate` transaction that writes every returned field                                                          |
| `ApiKeyScopeEnum::isHeldBy()` / scope ceiling                                                                                                                 | a product `KeyAuthorizer` (scopes ∩ the owner's current permissions; the owner principal must be active)                                                   |
| `NotifyExpiringApiKeysCommand`, `App\Notifications\ApiKeyExpiringNotification`, schedule entry `excubia:notify-expiring-api-keys`                             | the package command `api-keys:notify-expiring` (lease-claimed, sent once) with a product `ExpiryRecipientResolver`                                         |
| `IpAllowlistEntryRule`                                                                                                                                        | kept as the FormRequest rule, delegating to the package parser so form and runtime cannot disagree                                                         |
| Activity `api_key_id` written by hand                                                                                                                         | `Qiick\ApiKeys\Audit::withApiKeyId()` (public UUID)                                                                                                        |

`CreateApiKeyAction`, `RevokeApiKeyAction`, `ApiKeySecretOnceService`, the controller, the policy,
the FormRequest and the resource stay. Creation checks `LifetimeAssertion` (max lifetime) before
inserting.

**Tables and migrations** (edit `2026_11_16_100000_create_api_keys_table.php`): adopt the package
reference schema (`docs/reference-schema.md`).

- `key_prefix` becomes **unique**.
- New columns: `description`, `token_version`, `hash_algorithm`, `previous_key_prefix`,
  `credential_kind`, `oauth_client_id`, `restrictions`, `rate_limit_per_day`,
  `rate_limit_per_month`, `metadata`, `expiry_notified_at`, `expiry_notice_claimed_at`,
  `last_used_user_agent`, `last_rotated_at`, `exposed_at`, `exposure_source`, `revoked_by`,
  `revoked_reason`, `revocation_note`; index on `expires_at`.
- `owner_principal_id` becomes **NOT NULL, restrict on delete** (decision of 2026-11-23; today it
  is nullable `nullOnDelete`). `CreateApiKeyAction` always sets it, and the user-deletion action
  revokes the keys (`revoked_reason = owner`) instead of relying on a null owner.
- Open question for the maintainer: drop `owner_id` (users) and read the owner through the
  principal, as the reference schema does, or keep it as a product column. Recommended: keep it in
  this step (the policies and the guard read it) and remove it with `laravel-access`.

**Config and environment**

- Publish `config/api-keys.php`; `API_KEYS_PRODUCT_PREFIX=exc`; new keys issue token version 2
  (6-character checksum), version 1 stays accepted. The current `exc_…` shape is the package's
  version 1, so no legacy matcher is needed.
- `excubia.api_key_requests_per_minute` becomes the package default rate limit.
- **Attempt counting** (decided for 0.3.0-beta.2): the package counts failed attempts and
  per-key limits with Laravel's `RateLimiter` on the configured cache store, so Excubia's default
  `database` store works and no extra table is needed. The store must not be `array` outside
  tests (it keeps nothing between requests) or `null` (`php artisan api-keys:check` fails on it).
  On SQLite the database store needs `transaction_mode = IMMEDIATE` and a `busy_timeout`, which
  `config/database.php` already sets; MySQL and PostgreSQL need nothing. `api-keys:check` is not
  wired into `excubia:install` / `excubia:upgrade`: with these defaults it only reports.

**Routes**: none change. `routes/mcp.php` keeps `AuthenticateApiKeyMiddleware::class.':mcp:read'`.

**UI**: the five registry items (`api-key-form`, `api-key-list-row`, `api-key-scope-picker`,
`api-key-secret-dialog`, `api-key-scopes`) are already identical to app-ui `develop`, so the
"backend and UI together" rule is met with no frontend change. `ApiKeyResource` adds nothing the
list row does not show. New fields (description, day / month quotas, restrictions) stay out of the
form until the registry form has them.

**Tests**

- Red first: unique `key_prefix`; NOT NULL restrict owner; a 0.3 v2 token authenticates; an
  oversized failure burst from one IP answers 429 even with a correct key (package brake rule);
  owner losing a permission loses the scope on the next request; the expiry command sends once
  under two concurrent runs.
- Update: `tests/Feature/Settings/ApiKeysTest.php`, `ApiKeyExpiryNotificationCommandTest.php`,
  `tests/Feature/Mcp/McpServerTest.php`, `Principals/PrincipalLifecycleTest.php`,
  `Security/RouteAuthorizationTest.php`, the conformance hooks that create keys, and the browser
  test.
- Tests use the `array` cache store (the package accepts it under `testing`).

**Risks**

- The cache store behind the limits (above): never `array` or `null` in production.
- Refusal bodies change slightly (new codes, `X-RateLimit-*` headers). MCP clients see the same
  401 / 403 / 429 statuses.
- Existing keys are lost with the reset; MCP clients need new keys after the deploy.

**Reset:** yes (the `api_keys` shape changes).

---

## 2. `qiick/laravel-access` (first tag)

The package owns `Gate::before` (API actors never get the super-admin pass), the effective
permission resolver, the 2FA middleware, the `permission` route middleware, the
`AuthorizesWithPermission` FormRequest trait, the user group tables and the `principals` table.

**Code removed or replaced**

| Today                                                     | After                                                                                                            |
| --------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------- |
| `Gate::before` in `AppServiceProvider`                    | `Qiick\Access\GateBefore`                                                                                        |
| `App\Services\EffectivePermissionResolverService`         | the package resolver; `isAdmin()` moves to a small product service                                               |
| `EnsureTwoFactorIsEnabledMiddleware`                      | the package 2FA middleware, with product `TwoFactorPolicy` / `TwoFactorResponder`                                |
| `App\Models\Principal`, the product principal actions     | `Qiick\Access\Principals\Principal` (extended for `apiKeys()`), `PrincipalDirectory`                             |
| `UserGroup` model storage                                 | kept as a product model over the package `user_groups` table, or replaced by the package model                   |
| 34 `$actor->can(PermissionEnum…)` calls in `app/Policies` | the resolver (`hasPermission($actor, …)`); on API paths `can()` turns false once `Gate::before` skips API actors |

`AuthenticateApiKeyMiddleware` calls `AccessContext::enter()` after a key is verified, and the
`KeyAuthorizer` from step 1 calls `PrincipalDirectory::isActive()` and the resolver with
`fresh: true`. `RoleGrantService`, `SuperAdminGuardService` and the break-glass policy stay.

**Tables and migrations**

- Delete `2026_11_02_180000_create_user_groups_tables.php` and
  `2026_11_16_090000_create_principals_table.php`; the package loads its own
  (`2026_11_22_120000_create_user_groups_tables`, `2026_11_24_100000_create_principals_table`).
- Package shapes differ from ours: `user_groups` gains `public_id`, `metadata`,
  `organisation_id` (uuid), `is_instance`, and a unique (`organisation_key`, `name`) instead of a
  global unique name; `user_group_user` gains `expires_at`; `principals` gains `owner_principal_id`,
  `created_by`, `expires_at`, `deactivation_reason`, `metadata`, a uuid `organisation_id`, and
  `user_id` becomes **restrict on delete**.
- **Re-date** the product migrations that reference these tables so they run after the package
  files: `2026_11_09_200000_create_user_group_project_group_table.php` and
  `2026_11_16_100000_create_api_keys_table.php` move to `2026_11_25_…`. Laravel runs migrations
  from every path in filename order, so without the rename a fresh install fails on the foreign
  key.
- `config/permission.php`: `team_foreign_key` becomes `organisation_id` and the team column uuid in
  `2026_11_02_171110_create_permission_tables.php` (teams stay off); `events_enabled` true;
  `register_permission_check_method` false.

**Config**: publish `config/access.php`; `ACCESS_WEB_GUARDS=web`, `ACCESS_API_GUARDS=api_key`
(every guard in `config/auth.php` must be classified or boot fails);
`ACCESS_PERMISSION_ENUM=App\Enums\PermissionEnum`; `access.principals.organisation_required =
false`, `instance_types = []`. Bind `RoleCatalog`, `PermissionVocabulary`, `TwoFactorPolicy`,
`TwoFactorResponder`, `AuthorizationResponder`.

**Routes**: route groups switch to the package `permission:` middleware alias where they check a
permission today; no URL changes.

**UI**: none (the shared `auth.permissions` prop keeps its shape).

**Tests**: red first for the API actor on a super-admin owner's key (no Gate pass, no permission
outside the scopes), a soft-deleted group granting nothing, demotion taking effect on the next
request, and a job dispatched under a key keeping the key's limits. Replace the resolver unit
tests with binding tests; the conformance run covers C10 (owner principal restrict) and the
access probes.

**Risks**

- Policies that relied on `Gate::before` for group permissions: every one is rewritten in the same
  PR, and `RouteAuthorizationTest` must stay green.
- `principals.user_id` restrict: Excubia soft-deletes users, so the FK never fires on a normal
  deletion. `DeleteUserAction` must stop detaching the principal (`user_id = null`) and deactivate
  it with reason `erased` instead. A hard delete (none today) must go through
  `PrincipalDirectory::eraseUser()`.

**Reset:** yes (`user_groups`, `user_group_user`, `principals`, `roles` team column).

---

## 3. `qiick/laravel-http-basics` (first tag)

Request id, locale negotiation, security headers, idempotency and the fleet error shape.

**Code removed or replaced**: `SecurityHeadersMiddleware` → `SetSecurityHeadersMiddleware`
(prepended globally); `SetLocaleMiddleware` → the package one on the `web` and `api` groups,
with the user preference applied through `LocaleNegotiator`; new
`AssignRequestIdMiddleware` prepended (Excubia has no request id today); the machine-door JSON and
`error` augmentation in `bootstrap/app.php` through `MachineDoorJson` /
`ExceptionErrorCodeAugmenter`. The `config/excubia.php` security header keys go away.

**Tables and migrations**: the package adds `create_idempotency_records_table` (new table, used
only with `HTTP_BASICS_IDEMPOTENCY_STORE=database`). The ingest `batchId` de-duplication stays as
it is (frozen ingest contract); idempotency applies only to new write doors, with
`replayable=false` on routes that issue secrets.

**Config**: `HTTP_BASICS_CSP_MODE=off` (the design records Excubia's CSP divergence until nonces
exist), `HTTP_BASICS_SUPPORTED_LOCALES=en,it,de`, HSTS as today.

**Routes**: none. **UI**: none.

**Tests**: port `tests/Feature/Security/SecurityHeadersTest.php` and the locale tests to the
package behaviour; add one for `X-Request-Id` on a 404 and a maintenance 503; keep the ingest
contract tests unchanged.

**Risks**: header and `Content-Language` differences on edge responses; `Vary: Accept-Language`
on API responses. Low.

**Reset:** no (one new table).

---

## 4. `qiick/laravel-outbound` 0.1.1 → 0.2

Excubia already uses 0.1.1 for the SSRF guard, the pinned client and Standard Webhooks signing in
`DeliverAlertJob` (#399). 0.2 adds five mandatory package tables and a delivery coordinator with
retries, `Retry-After`, auto-disable, redelivery and body retention.

**Code**

- Recommended: move the signed alert webhook onto the package tables. The webhook URL and secret
  leave `settings` (`webhook_url`, the encrypted `webhook_secret`, last success / last error) for
  one instance-scoped `webhook_endpoints` row with its secret in `webhook_endpoint_secrets`;
  `AlertDispatcherService` calls `WebhookDeliveryCoordinator::dispatch()` instead of queueing
  `DeliverAlertJob` for the webhook channel. Slack, Telegram, email and in-app stay in
  `DeliverAlertJob` (they are not Standard Webhooks).
- Settings → Alerts shows the endpoint state from the package (last success, failure streak,
  disabled) and offers "re-enable" (`enableEndpoint()`), since auto-disable is on by default.
- Alternative, if the maintainer prefers the smallest change: keep the settings-based webhook and
  only upgrade. The five tables are still created (the package loads them), unused.
- `RetrySchedule::MAX_DELAY_SECONDS` / `SeenWebhookIdStore` breaks do not affect Excubia (it does
  not verify inbound webhooks).

**Tables and migrations**: five new package tables (`webhook_endpoints`,
`webhook_endpoint_secrets`, `webhook_subscriptions`, `webhook_deliveries`,
`webhook_delivery_attempts`). No Excubia migration changes; no scope column (one instance scope).

**Config**: `routes/console.php` schedules `outbound:webhooks:work` (`everyMinute()`, on one server) and
`outbound:webhooks:prune` (`daily()`); `OUTBOUND_WEBHOOK_TIMEOUT_SECONDS` (15 s) is shorter than
the scheduler's own timeout; `User` already uses `Notifiable` (auto-disable email to `created_by`).
Seeders never `create()` secrets directly (`SecretRotator`).

**UI**: the Alerts settings webhook card reads and writes the endpoint; no new pattern needed
(existing form and status badge).

**Tests**: `AlertsTest`, `AlertEventsTest`, `AlertControlsTest`, `WebhookUrlGuardPinTest` move
to the coordinator with `Http::fake()`; red first for "a 410 disables the endpoint" and "a
redelivery keeps the `webhook-id`".

**Risks**: receivers now see retries spread over up to 75 hours and auto-disable after a long
failure streak; the webhook secret must be re-entered after the reset (or carried by the settings
export).

**Reset:** not required by the tables (all new). It rides with group A because the webhook moves
out of `settings` at the same time, and because 0.2 itself says to recreate the database after
each pre-1.0 upgrade.

**Webhook cutover** (step 6 of the [Group A reset checklist](#group-a-reset-checklist)):
restoring the old `settings` rows does not bring the webhook back, because the URL now lives on
the package's endpoint and the secret is generated by Excubia.

---

## 5. `qiick/laravel-oidc` (next beta)

Excubia is on `v1.0.0-beta.6`, the latest tag (#401). The unreleased changes on `develop`
(beta.7) add:

- a unique `(user_id, issuer)` key on `user_identities` (package `create_` migration edited:
  **reset** to get it; the old index keeps working until then);
- the encrypted `id_token` in the session (format version 2, version 1 still read: nobody is
  signed out);
- `max_age` / `auth_time` / requested `acr` checks; a login that asked for an `acr` and got none
  no longer counts as MFA;
- the post-logout route `/auth/oidc/logged-out`, which must be registered at the IdP
  (`php artisan oidc:configure` prints it), and `oidc.logout.redirect_to` set (for example
  `/login`).

**Code**: `composer update qiick/laravel-oidc`; check `config/oidc.php` overrides against the new
keys; no product class changes expected (Excubia does not build `AuthorizationCodeFlowService` by
hand).

**Tests**: the existing SSO tests with the fake IdP; add one for the logged-out return.

**Risks**: the IdP post-logout URI (maintainer action at the IdP); `APP_KEY` rotation now signs SSO
users out unless the old key is in `APP_PREVIOUS_KEYS`.

**Reset:** the update itself can deploy without one. The new unique key reaches production with
the next reset group.

---

## 6. `qiick/laravel-audit` (first tag)

The shared `activity_log` table (Spatie v5), the logging trait with the secret floor, server-side
attribution (who acted and through which credential), GDPR anonymisation and the two-floor purge
(365 days, 730 for the `auth` log).

**Code removed or replaced**: `2026_11_04_000000_create_activity_log_table.php`,
`LogsApplicationActivityTrait` (→ `Qiick\Audit\Concerns\LogsActivity`), most of
`config/activitylog.php`, the base of `App\Models\Activity` (it extends the package model if
Excubia still needs product scopes). The API key middleware binds the credential with
`AuditContext::bindCredential()`; `Audit::withApiKeyId()` calls from step 1 go. `DeleteUserAction`
calls the `AuditAnonymiser`. `ActivityLogService` keeps its visibility filter; its search adds
nothing new.

**Tables and migrations**: the package migration replaces ours: `public_id` (uuid),
`schema_version`, `request_id`, `anonymised_at`, a uuid `organisation_id`, `dateTime` timestamps,
and new indexes.

**Config**: publish `config/audit.php`; add Excubia's secret names if any are not on the floor;
the purge is scheduled by the package (remove any own `activitylog:clean` schedule);
`spatie/laravel-activitylog` constraint becomes `^5.1.3`.

**UI**: the activity log page and its export address rows by `public_id`. No new component.

**Tests**: red first for "a secret attribute is logged as `[redacted]`", "a key request's causer
is the owner principal with `actor_kind = api_key`", "a caller cannot forge `api_key_id`", and
"anonymisation scrubs a deleted user's email from descriptions". Update the activity log tests.

**Risks**: audit rows cannot be updated or deleted through the model any more (any code that does
must move to the anonymiser); the production audit history is lost with the reset (accepted on
2026-11-20).

**Reset:** yes (`activity_log` shape).

---

## 7. `qiick/laravel-settings` (when it exists)

The package does not exist yet (design only). From the design:

**Code removed or replaced**: `2026_11_02_190000_create_settings_table.php`, `App\Models\Setting`
and the storage part of `SettingsRepositoryService` (724 lines). `defaults()` and `SECRET_KEYS`
become a `SettingsSchema`; the typed methods (login, password rule, alert filters) stay as a thin
product service over `Settings`. The repository stops being a singleton (the package memo is
scoped per request or job), which fixes stale settings in queue workers.

**Tables and migrations**: the package table adds `encryption`, `revision` and `metadata`;
`scope_id` gets default `''`.

**UI**: the settings pages keep their forms; edit payloads keep `has_<key>` for secrets.
Optimistic concurrency (`revision`) can be wired later.

**Tests**: secrets never in `forEdit()`, Inertia props or logs; undeclared keys refused; a write
in one process is seen by a worker.

**Risks**: about 60 keys and 42 files use the repository; the secrets (OIDC client secret, Slack
URL, Telegram token) must survive the reset.

**Reset:** yes (`settings` shape). Use `settings:export` before and `settings:import` after,
under the same `APP_KEY`.

---

## Deploy groups

| Group    | Packages                           | Reset                                       | Why together                                                                                                                                           |
| -------- | ---------------------------------- | ------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **A**    | api-keys 0.3, access, outbound 0.2 | one new database after all three are merged | api-keys and access both reshape `api_keys` and `principals` (owner restrict, re-dated migration); outbound moves the webhook secret out of `settings` |
| **B**    | audit, settings                    | one new database after both are merged      | both replace a product table that every page writes to; one settings export / import covers both                                                       |
| no reset | http-basics, oidc beta.7           | none                                        | http-basics adds one table; oidc beta.7 runs on the old index until the next reset                                                                     |

Merge order of group A: #410 (access, which already contains #408, api-keys), then #412
(outbound 0.2.0-beta.3 and oidc 1.0.0-beta.8), then #416 (the alert webhook on outbound). Each
branch contains the one before, so each merge is a fast update of the next. Production
stays on the last pre-group deploy until the whole group is merged, so it never runs half a group:
the maintainer deploys the group's last merge and recreates the database once. If one adoption of
a group slips, the others still deploy (each PR is complete on its own) with one reset per merge,
or wait; the maintainer decides.

http-basics deploys whenever its tag exists. oidc 1.0.0-beta.8 rides with group A (#412): its
`user_identities` and `oidc_logout_revocations` tables are recreated with the group's new
database.

### Settings across a reset

Until `laravel-settings` exists there is no `settings:export`. For group A the maintainer re-enters
the OIDC client secret, Slack URL, Telegram token and alert settings after the reset (or copies
the `settings` rows from the snapshot into the new database under the same `APP_KEY`: the
encrypted values decrypt again). With the outbound adoption the alert webhook URL and signing
secret leave `settings` for the package's webhook tables, so they are set again in
Settings → Integrations in every case (step 6 of the checklist). Group B uses `settings:export` / `settings:import`.

### Group A reset checklist

For the maintainer, after the group's last merge is deployed:

1. **Laravel Cloud panel** (not in the repository): the Scheduler toggle is on, on which cluster
   (App or a worker cluster), the number of replicas (more than one needs `onOneServer()`, which
   Excubia's webhook schedule uses), whether scale-to-zero is on (a sleeping environment wakes
   for scheduled tasks; a task every minute keeps it awake), and a queue worker that processes
   the alert jobs.
2. The cache store is `database` (or Redis), never `array` or `null`.
3. **Renamed environment variables.** The package reads new names, and the old ones are ignored
   silently (the defaults apply: 60 requests per minute, 24 hours, 7 days). In the Cloud
   environment, copy any value that is set to its new name, then delete the old one:

    | Old name (removed)                    | New name                                 | Default |
    | ------------------------------------- | ---------------------------------------- | ------- |
    | `EXCUBIA_API_KEY_REQUESTS_PER_MINUTE` | `API_KEYS_DEFAULT_RATE_LIMIT_PER_MINUTE` | 60      |
    | `API_KEY_ROTATION_GRACE_HOURS`        | `API_KEYS_ROTATION_GRACE_HOURS`          | 24      |
    | `API_KEY_EXPIRY_NOTICE_DAYS`          | `API_KEYS_EXPIRY_NOTICE_DAYS`            | 7       |

    New, no old equivalent: `API_KEYS_FAILED_ATTEMPTS_PER_MINUTE` (failed key lookups per IP,
    default 20).

4. **SSO (oidc 1.0.0-beta.8).** At the IdP, register `{APP_URL}/auth/oidc/logged-out` as a
   post-logout redirect URI (until it is registered, SSO logout does not end the IdP session).
   `oidc.backchannel_logout.max_retention_seconds` (default 30 days) must cover
   `SESSION_LIFETIME` plus the IdP's logout-token lifetime. An IdP on a private network keeps
   `OIDC_ALLOW_PRIVATE_HOSTS=true`. After the deploy, `php artisan oidc:configure` prints the URI
   and checks the rest.
5. **Fresh database.** `migrate:fresh` and `db:wipe` refuse to run in production
   (`DB::prohibitDestructiveCommands()`), and a plain `migrate` on the old database stops at the
   first re-dated `create_` migration ("table already exists"). So the database is replaced, not
   migrated, and the site stays closed to everyone but the maintainer until it is verified:
    1. Write down the settings of step 6 (the secrets are encrypted with `APP_KEY` and are
       re-entered, not copied) and keep the current `APP_KEY`: the rollback needs both.
    2. `php artisan down --secret=<random>` and stop the queue workers and the scheduler, so
       nothing writes to the old database any more; then take its snapshot in the Cloud panel and
       keep the old database itself until the new one is verified.
    3. In the Cloud panel, attach a new empty database to the environment. Never point the app
       at a copy of the old schema.
    4. Deploy the group's last merge with the site still down: the deploy runs
       `php artisan migrate --force` on the empty database and the permission and system project
       seeders.
    5. With the bypass (`{APP_URL}/<random>`, which sets the bypass cookie), run
       `php artisan excubia:setup-link` and open the `…/setup#token=…` link it prints: `/setup` creates the first
       super-admin only for the holder of that one-time link, so nobody else can claim the empty
       install.
6. Still behind the bypass (every browser step below opens `{APP_URL}/<random>` first), re-enter:
   OIDC provider and client secret, Slack webhook URL, Telegram bot token and chat, the alert
   webhook URL and the alert settings; then generate the webhook's signing secret
   (Settings → Integrations → Generate) and configure the receiver with the new one.
7. Rebuild what the reset removed, still behind the bypass: the projects (each gets a new push
   token), the users, groups and custom roles, and new API keys for the MCP clients (every key is
   gone). Webhook jobs of the old `DeliverAlertJob` still waiting in a queue would fail on the new
   code: the `database` queue starts empty with the new database; with Redis, clear only this
   environment's queue (`php artisan queue:clear redis --queue=<its queue>`, never a shared Redis
   flush) before any worker runs.
8. **Smoke test while still down.** Laravel's queue workers pause in maintenance mode and the
   project push API answers 503 without the bypass cookie, so the checks run from the CLI:
    1. `php artisan excubia:system-report`: Excubia checks itself and stores a report on the
       system project without HTTP; it appears on the dashboard (through the bypass).
    2. Settings → Integrations → send a test event to each channel, then deliver it with
       `php artisan queue:work --force --stop-when-empty` (`--force` runs in maintenance mode)
       and `php artisan outbound:webhooks:work`. Each channel receives it, and the alert history
       shows `sent`.
       Rollback (if a check fails): put the old database back on the environment, keep the same
       `APP_KEY`, and redeploy the previous commit.
9. `php artisan up`, then start the queue workers and the scheduler. Give each watched app its new
   `EXCUBIA_APP_TOKEN` and run `php artisan excubia:push` there: its first report on the new
   database closes the cutover. The push API accepts only those new project tokens, so opening it
   now exposes nothing the maintainer has not issued.

## Open questions

1. ~~**Cache store for API keys.**~~ Decided: 0.3.0-beta.2 counts on the configured cache store
   with Laravel's `RateLimiter` (see §1).
2. **`api_keys.owner_id`**: keep until access lands (recommended), or drop now in favour of the
   principal.
3. ~~**Outbound webhook**~~ Decided: the alert webhook moves onto the package tables, built on
   top of a plain upgrade (two stacked PRs). The first attempt is sent right away by a queued
   job; the scheduler retries `everyMinute()->onOneServer()` (no task every few seconds).
4. **Tags.** access, http-basics and audit have no tag yet, and outbound 0.2 is untagged. Each
   adoption waits for its tag (no branch dependencies).

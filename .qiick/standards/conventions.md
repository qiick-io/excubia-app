<!-- Generated from qiick-io/app-ui standards: do not edit, run the sync. The agent files hold the core of this page and point here. -->

# Fleet conventions

One answer per topic for every qiick package and product (decision of 2026-11-24): where two repos solve the same problem, they solve it the same way. A repo that differs writes the exception down with its reason; an unwritten difference is a defect. `F<n>` is the convention's id in the decision and in the checks.

- **F1 Config:** `config/<package>.php` is published with every nested key present; a key missing from an older published copy takes the shipped default; a value present but invalid stops the boot with an error that names the key.
- **F2 `<package>:check`:** every package has one; it validates the config and the environment and exits non-zero, for the deploy pipeline.
- **F3 Off means off:** a feature switched off in config registers nothing: no route, listener, binding, schedule or migration.
- **F4 No static state:** no mutable `static` property or variable; per-request data in `scoped` bindings, so Octane and queue workers start each request and job clean.
- **F5 Events** end with `Event`, in the past tense (`WebhookSignedEvent`).
- **F6 Commands** are `<package>:<verb>` (one namespace, kebab-case verb); every command that deletes has `--dry-run`.
- **F7 Environment variables** a package reads carry its prefix (`HTTP_BASICS_`, `MCP_OAUTH_`).
- **F8 Columns:** `public_id` UUID for anything that leaves the database, `metadata` JSON, `schema_version` for versioned rows, UTC timestamps, `timestamp` for past events and `dateTime` for future instants, `organisation_id` lowercase UUID, index names within 63 characters, binary collation for case-sensitive identifiers.
- **F9 Retention:** data that expires has a prune command scheduled at least daily with `onOneServer()`, deleting in chunks, with `--dry-run`.
- **F10 Logs:** structured context with the same keys everywhere (`request_id`, `organisation_id`, `public_id`), snake_case; never personal data or secrets.
- **F11 Errors:** one JSON error shape (`message`, `error`, optional `errors`) and one fleet registry of `error` codes in app-ui (`standards/error-codes.json`), without collisions.
- **F14 Test fakes** for products ship in the package's `Testing` namespace (`src/Testing/`).
- **F12 Package files:** `docs/public-api.md` (with its test and the deprecation policy), `docs/security-checklist.md` mapped to tests, `docs/decisions/YYYY-MM-DD-<slug>.md`, `UPGRADING.md` per version, `CHANGELOG.md` with compare links, `docs/dependencies.md`.
- **F13 Release order:** a package is released after the packages it depends on; a `1.x` (pre-releases included) never depends on a `0.x`, and a stable release never on a pre-release.
- **F18 Products** share one structure: `docs/beta-readiness.md` (the fleet checklist), `<app>:install` and `<app>:upgrade`, SQLite by default in `.env.example`, tests pinned to an in-memory database, and the package adoption table.
- **F25 Strict Laravel:** products boot with strict models, destructive commands prohibited and HTTPS forced in production, immutable dates and the password defaults; every PHP repo's tests stop stray HTTP requests and processes.
- **F27 Public APIs:** an API is versioned in its path (`/api/v1`), described by a hand-written OpenAPI file that a test compares with the routes, answers errors in the F11 shape, and retires a version only after announcing it with `Deprecation` and `Sunset` headers.
- **F28 UI quality:** a product with a UI calls the shared `ui-quality` and drift checks, and proves its screens in a browser test in light and dark.
- **F15–F17** (repository settings, labels and project, agent rules) are in the repository rules, **F19–F24, F26, F29 and F30** (security policy, secrets, expand / contract, backup, signed tags, supply chain, observability, privacy, runbooks) in the security, data and operations rules.

With a `conventions` object in `qiick-standards.json`, `app-ui-drift / standards` runs the static checks of these rules; an exception is a `conventions.divergences` entry with `reason`, `decision` and `review_by`.

## How the conventions are checked

`standards/scripts/check-conventions.mjs` runs from the standards drift check (`sync-standards.mjs --check`) in a repo whose `qiick-standards.json` has a `conventions` object; a repo without one is not checked, so adopting the conventions is a PR of its own. It reads source text: it proves the shape, the repo's tests and the fleet conformance suite prove the behaviour. Run it with `--all` to see what a repo would have to fix before it adopts them.

```json
{
    "package": true,
    "conventions": {
        "divergences": {
            "F5": {
                "reason": "Event names are the design's public API until 2.0",
                "decision": "knowledge/decisions/2026-11-24-fleet-conventions",
                "review_by": "2027-05-01"
            }
        }
    }
}
```

A divergence skips its check. It fails when it lacks a field, when `review_by` has passed, and when the check would pass without it (remove it). Checks marked _package_ run with `"package": true`, the others in apps too (source under `app/`, schedule in `routes/console.php` or `bootstrap/app.php`).

## F1 Config: nested defaults, fail fast

**Rule.** The package publishes `config/<package>.php` (tag `<package>-config`). Reads go through one typed reader with the shipped defaults as constants, as `Qiick\HttpBasics\Config\PackageConfig` does: a key that is missing takes the default, because `mergeConfigFrom()` merges only the top level and a product that published the file before the key existed would otherwise read `null`. A key that is present with an invalid value (wrong type, out of range, unknown enum value) throws at boot, from the service provider's `boot()`, with the full key in the message (`api-keys.token_version must be 1 or 2.`), as `laravel-api-keys` does. A test publishes a config without a newer key and asserts the default.

**Why.** On 2026-11-24 only http-basics had the nested fallback (analysis X-1); laravel-audit failed loudly on a published config missing a new key (AU-z). http-basics in turn falls back to the default on a wrong type too, so a typo in production silently becomes the default.

**Checked by.** _package_ F1: `config/<package>.php` exists. The fallback and the boot error are proved by the package's own tests (the checklist in `docs/security-checklist.md` names them).

**Exceptions.** None.

## F2 `<package>:check`

**Rule.** Every package registers `<package>:check`. It runs the same validation as boot (F1) plus what boot must not do (reachability of the cache store, a database table, a key file), prints one line per problem with the key or the resource, and exits `1` on any error. Products run every package's check in their deploy and in `<app>:upgrade`.

**Why.** No package except `laravel-api-keys` (`api-keys:check`) had one (analysis X-f), so a misconfigured deploy was found by the first request that needed the setting.

**Checked by.** _package_ F2: a command named `<package>:check` is declared under `src/`.

**Exceptions.** None; a package with nothing to validate ships a check that says so.

## F3 Off means off

**Rule.** When a feature is off in config (`<package>.<feature>.enabled = false`, or the whole package), the package registers no route, middleware alias, listener, schedule entry, migration or container binding for it, and dispatches nothing. A switch that no code reads does not ship.

**Why.** `mcp-oauth.cimd.enabled` was read by no code (analysis MCP-i): turning it on did nothing, and a reviewer assumed SSRF exposure that was not there. A feature that is off but still registers routes is an attack surface the operator believes is closed.

**Checked by.** _package_ F3: when `config/<package>.php` has an `enabled` key, a test must set one to `false`; that test asserts the routes, listeners and bindings are absent.

**Exceptions.** A route that must answer while the feature is off (a discovery document saying it is off) is listed in the package's public API with the reason.

## F4 No static state

**Rule.** No mutable `static` property or `static $variable` in `src/` or `app/`. State that lives for one request or job is a `scoped` binding (Laravel resets it between requests under Octane and between queue jobs); a `singleton` holds only configuration and stateless services. Caches of immutable data (a parsed public suffix list) may be singletons.

**Why.** Octane and long-running queue workers reuse the process: a static value set by one request leaks into the next tenant's. laravel-access resolves permissions through a `scoped` resolver for this reason (analysis AC-e), while laravel-api-keys kept a static translator and laravel-outbound a static `WeakMap`.

**Checked by.** F4: static properties and variables outside `Testing/`; `$wrap` (API resources) and `$objectClasses` (LdapRecord models) are framework configuration and pass.

**Exceptions.** A memo of immutable, request-independent data, with a divergence that names it.

## F5 Event names end with `Event`

**Rule.** Every event class is `<Subject><PastTenseVerb>Event` in an `Events` namespace: `OidcLoginSucceededEvent`, `WebhookEndpointDisabledEvent`. Broadcast and webhook wire names (`broadcastAs()`, webhook `type`) are separate, stable strings.

**Why.** Three styles coexisted (analysis X-2, X-c): `*Event` in laravel-oidc and laravel-outbound and in every product (externa-core, signaculum-core), no suffix in laravel-mcp-oauth and laravel-api-keys, `ActivityAnonymised` in laravel-audit. The suffix is the most widespread form (four repos of seven with events) and is already what the shared architecture test requires (`Events` ⇒ `Event`); the packages without it only passed by listing every event under `ignoring`. Renaming is free before each package's 1.0.

**Checked by.** F5: every class under an `Events/` directory ends with `Event` (the architecture test does the same, but its `ignoring` list is the repo's own).

**Exceptions.** None after 1.0 of the package; before it, a divergence while the rename PR is open.

## F6 Commands: `<package>:<verb>`, `--dry-run` when deleting

**Rule.** Every Artisan command is `<package>:<verb>` or `<package>:<verb>-<object>`, kebab-case, one colon: `outbound:prune-payloads`, `instituta:create-key`, `externa:sync-permissions`. Apps use their short name (`externa`, `excubia`). A command that deletes or erases data has `--dry-run`, which reports what it would delete and changes nothing.

**Why.** `outbound:webhooks:prune` and `instituta:key:create` nest a second namespace; externa-core spreads its commands over `files:`, `ai:`, `users:`, `permissions:` and others, so `php artisan list <app>` does not show what the app owns. `oidc:prune-logout-revocations` and several product prune commands could not be previewed before a first run in production.

**Checked by.** F6: declared signatures (`$signature`, `#[AsCommand]`); a command whose name contains prune, purge, delete, cleanup, erase or forget needs `--dry-run`.

**Exceptions.** A framework command a package extends keeps its name (`passport:*`).

## F7 Environment variables carry the package prefix

**Rule.** Every variable a package's config reads is `<PACKAGE>_<KEY>` (`http-basics` ⇒ `HTTP_BASICS_`). A framework variable (`APP_*`) may be read only as a fallback default.

**Why.** Every package with variables already follows it; the rule keeps the next one from adding `WEBHOOK_TIMEOUT` that collides with a product's own.

**Checked by.** _package_ F7: every `env('…')` in `config/`.

**Exceptions.** None.

## F8 Standard columns

**Rule.**

- `id` is a bigint primary key, never exposed. Anything that leaves the database (API, webhook, MCP, audit, URL) is `public_id`, a UUID (v7) with a unique index (decisions 2026-11-19 fleet core schema, 2026-11-20 fleet contracts).
- A free-form JSON bag is `metadata` (`json`), never `meta`, `extra` or `data`.
- A row whose stored format can change (a serialized payload, a fingerprint) carries `schema_version` (`unsignedSmallInteger`, default `1`). A version of a domain payload keeps its domain name (`payload_version` in laravel-outbound is not a row version).
- Timestamps are written and compared in UTC (`now('UTC')`), whatever `app.timezone` is.
- A past event is `timestamp` (`created_at`, `revoked_at`); an instant in the future is `dateTime` (`expires_at`, `*_until`, `next_*_at`), because MySQL `TIMESTAMP` ends in 2038 and converts through the session time zone (conformance C11).
- An organisation reference is `organisation_id`, a UUID, stored lowercase (`Str::lower()` before writing a value that came from outside).
- Index names stay within 63 characters (PostgreSQL truncates longer names, MySQL refuses past 64); Laravel's generated name is fine within the limit, an explicit name otherwise.
- Identifiers compared case-sensitively (OIDC `issuer` / `subject`, external ids) use a binary collation on MySQL / MariaDB (`utf8mb4_bin`), as laravel-oidc does.

**Why.** Each of these broke or was argued twice: `organization_id` and `string` organisation ids in signaculum-core, `meta` JSON columns in externa-core, `expires_at` as `TIMESTAMP` across the products, a 64-character generated index name in instituta-core, and three spellings of one id in logs and columns.

**Checked by.** F8 over `database/migrations/`: `public_id` / `organisation_id` as UUID, the `organization_id` spelling, future instants as `dateTime`, `metadata` naming, generated index name length (literal table names only). Engine behaviour (collation, UUID columns on PostgreSQL and MySQL) is in the conformance suite (C1, C3, C5, C11, C12).

**Exceptions.** A table shaped by an upstream package (Spatie, Passport) keeps upstream's columns; a migration frozen by a stable release is never edited (`migrations-frozen.yml`): the fix is a new migration or a divergence.

## F9 Retention

**Rule.** Every table whose rows expire has a prune command (`<package>:prune-<rows>`). It deletes in chunks (`chunkById` or a `limit()` loop) so a backlog never locks the table, has `--dry-run` (F6) and an `--hours` / `--days` floor where useful. The package schedules it itself, at least daily with `onOneServer()` and `withoutOverlapping()`, behind a config switch (`<package>.<area>.schedule`, default on) so a product can schedule it differently.

**Why.** laravel-http-basics documented no schedule and deleted in one statement; laravel-mcp-oauth and laravel-oidc left the schedule to a README line every product had to copy (analysis X-f). laravel-audit schedules its own purge (Team Leader decision of 2026-11-24): that is the form that cannot be forgotten.

**Checked by.** F9: a command whose name contains prune or purge deletes in chunks and appears in a schedule statement with `->onOneServer()` (service provider for a package; `routes/console.php`, `bootstrap/app.php` or `app/` for a product).

**Exceptions.** A table pruned by `model:prune` through Laravel's `Prunable` trait, scheduled the same way.

## F10 Logs

**Rule.** Log through `Log::` / the injected logger with a message and a context array. Context keys are snake_case and the shared ones are spelled the same everywhere: `request_id`, `organisation_id`, `public_id` (of the subject), `user_public_id`, `package`. Never log personal data or secrets: no email, name, phone, IP address, user agent, password, token, API key, client secret or authorization header; log the `public_id` instead. The IP and user agent belong to the activity (audit) log, which has a retention.

**Why.** http-basics accepts request ids up to 128 characters while laravel-audit stored 64 and dropped longer ones silently (analysis X-a): one key, two contracts. Logs are copied to third-party tools without the erasure guarantees of the database, so personal data there cannot be erased on request.

**Checked by.** F10: keys in the context arrays of log calls (forbidden keys, `org_id` / `organization_id` / `requestId`-style spellings, snake_case).

**Exceptions.** A security log the operator must have (a blocked SSRF target host) logs the host, never a user's data.

## F11 Errors and the fleet error code registry

**Rule.** A machine error (API, MCP, webhook endpoints of our own) is JSON `{"message": "…", "error": "<code>", "errors": {…}}`: `error` is a stable snake_case code, never translated and never called `code` (decision 2026-11-20 core hardening); `message` is for people; `errors` holds field errors (422). `laravel-http-basics` renders it, optionally as RFC 9457 problem details with `error` kept as an extension. Every code is registered once in app-ui's `standards/error-codes.json` with its owner and meaning; a repo that defines codes lists them in `docs/error-codes.md` (a table whose first column is the code).

**Why.** The same condition had three codes: `too_many_requests` (http-basics, signaculum-core), `rate_limit_exceeded` (laravel-api-keys) and `rate_limited` (instituta-core); `bad_request` in signaculum-core is http-basics' `http_error`. A client of two products had to branch on both.

**Checked by.** F11: every code in `docs/error-codes.md` is registered, owned by the repo or a shared owner (`laravel-http-basics`, `oauth`), and not a `replacedBy` duplicate; every code the registry gives the repo is listed. app-ui's own test fails on a duplicate or non-snake_case code. The response shape is conformance C20.

**Exceptions.** OAuth and OIDC endpoints keep the RFC 6749 / 6750 / 7591 bodies (`error`, `error_description`); their codes are registered under the shared owner `oauth`. A registered duplicate carries `replacedBy` and is removed before its owner's 1.0, or in its next major.

## F14 Test fakes in `Testing`

**Rule.** A package with interfaces products implement or call ships fakes for them in `src/Testing/` (namespace `Qiick\<Package>\Testing`): `Fake*` for recording fakes, `InMemory*` for stores, `Fixed*` for clocks and ids, plus assertion helpers. They are autoloaded with the package (not `autoload-dev`), so a product's tests use them without copying code; production code never resolves them.

**Why.** Every package with contracts already ships them this way (http-basics, api-keys, mcp-oauth, oidc, outbound); the rule fixes the place so a product test always looks in the same namespace.

**Checked by.** _package_ F14: no `Fake*` / `InMemory*` class outside `src/Testing/`; a package with `src/Contracts/` has `src/Testing/`.

**Exceptions.** None.

## F12 The same files in every package

**Rule.** Every package has:

| File                                  | Content                                                                                                                                                            | Reference                                 |
| ------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ----------------------------------------- |
| `docs/public-api.md`                  | every `@api` class, config key, variable, command, event, route, table and error code; a `## Deprecation policy` section                                           | `qiick/laravel-http-basics`               |
| `tests/Arch/PublicApiTest.php`        | fails when a class is neither `@api` nor `@internal`, or an `@api` class is missing from the page                                                                  | `qiick/laravel-http-basics`               |
| `docs/security-checklist.md`          | one row per security property: `# \| Property \| Covering test \| Status`                                                                                          | `qiick/laravel-http-basics`               |
| `docs/decisions/YYYY-MM-DD-<slug>.md` | one file per decision, titled `# YYYY-MM-DD: <title>`, with who decided, what and why                                                                              | `qiick/laravel-api-keys`                  |
| `UPGRADING.md`                        | a section for every released version (`## From X to Y`), with the commands, config and schema steps                                                                | `qiick/laravel-api-keys`                  |
| `CHANGELOG.md`                        | Keep a Changelog: `[Unreleased]`, `[X.Y.Z] - YYYY-MM-DD`, and a compare link for every version (`[X.Y.Z]: https://github.com/qiick-io/<repo>/compare/vA...vX.Y.Z`) | `qiick/laravel-oidc`                      |
| `docs/dependencies.md`                | the dependency register: every `require` entry with its reason, maintainer, licence, risk and exit plan                                                            | the dependency register rule (app-ui#226) |

`README.md`, `SECURITY.md`, `CONTRIBUTING.md` and `LICENSE` are there already in every package. When a repo adopts the conventions, the sync writes `docs/public-api.md` and `docs/security-checklist.md` from `standards/templates/` once, as starting points.

**Why.** On 2026-11-24 the public API list existed in three of seven packages under two names (`public-api.md`, `PUBLIC-API.md`), the deprecation policy lived in a README, an UPGRADING file or nowhere (analysis X-g), a security checklist mapped to tests existed only in http-basics, and laravel-oidc and laravel-outbound had no upgrade section for some released versions. A reviewer or a product could not find the same thing in the same place twice.

**Checked by.** _package_ F12: the files and their shape (exact file names, case included); the register's contents by the dependency register check.

**Exceptions.** None.

## F13 Release order

**Rule.** A package is released after every qiick package it requires; a product after the packages it adopts. A version line `1.x` (a `1.0.0-beta.N` included) requires only `1.x` qiick packages; a stable release requires only stable ones (`^1.0`, never `@beta`). The order today, from the `require` graph and the decision of 2026-11-24:

1. `laravel-http-basics`, `laravel-outbound`, `laravel-api-keys`, `laravel-access`, `laravel-audit` (no qiick dependency);
2. `laravel-oidc` and `laravel-mcp-oauth` (both require `laravel-outbound`);
3. the products, each after the packages it adopts.

**Why.** `laravel-oidc` ships `1.0.0-beta.6` on `laravel-outbound ^0.1`, so its 1.0 would freeze an API on top of one that may still break (analysis X-b, OIDC-l); `externa-core 1.5.1`, a stable release, requires `laravel-oidc ^1.0@beta`.

**Checked by.** F13: the repo's newest version in `CHANGELOG.md` against its `qiick/*` constraints in `composer.json`.

**Exceptions.** A pre-release of a dependency in a product's pre-release (`excubia-app` beta on `laravel-oidc @beta`) is fine; F13 only fails once the product's own line is stable.

## F18 The same product structure

**Rule.** Every product (`"app": true`):

- keeps `docs/beta-readiness.md`, the fleet checklist (`standards/templates/beta-readiness.md`, written first for signaculum-core): the items are the fleet's, the Status column is the product's, and section 7 is its package adoption table;
- installs and upgrades with `<app>:install` and `<app>:upgrade` (the distribution rules);
- ships `.env.example` on SQLite, so a fresh clone runs without a database server; a local MySQL or PostgreSQL database is the app's one database, named after it (`excubia_app`), never a copy per branch or version;
- pins tests to an in-memory database in `phpunit.xml` (`DB_DATABASE=:memory:`); a test never writes `.env` or touches the development database;
- sets in `qiick-standards.json` what its type needs:

| Repo type     | `qiick-standards.json`                                                                        | Shared workflows (job id)                                                                                                                         | Rule pages                               |
| ------------- | --------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------- |
| Product       | `php`, `app`, `uiPreset`, `frontendRoots`, `oxlint` or `eslint`, `conformance`, `conventions` | `app-ui-drift`, `pr-title`, `php-quality`, `ui-quality`, `migrations-frozen`, `distribution-smoke`, `conformance`                                 | all, `stack` and `distribution` included |
| Package       | `php`, `package`, `conventions`                                                               | `app-ui-drift` (`registry: false`), `pr-title`, `php-quality` (`phpstan-min-level: max`), `package-matrix`, `migrations-frozen` (with migrations) | all but `stack`, `distribution` and `ui` |
| Frontend only | `uiPreset`, `frontendRoots`                                                                   | `app-ui-drift`, `pr-title`, `ui-quality`                                                                                                          | all but the PHP pages                    |

**Why.** Each product invented its own: signaculum-core wrote the beta checklist, the others have none; signaculum-core#176 rewrote the shared `.env` from a test; and on 2026-11-09 per-version database copies filled the disk.

**Checked by.** F18 (products): the checklist file, the two commands, `.env.example` and `phpunit.xml`. The checklist's content is reviewed in the release PR.

**Exceptions.** A product without a self-hosted distribution writes ➖ with the reason in its checklist; the commands stay.

## F25 Strict Laravel

**Rule.** A product's `AppServiceProvider::boot()` calls:

- `Model::shouldBeStrict(! app()->isProduction())`: lazy loading, silently discarded attributes and missing attributes throw outside production (the checks rules);
- `DB::prohibitDestructiveCommands(app()->isProduction())`: no `migrate:fresh`, `db:wipe` or `migrate:reset` against production;
- `URL::forceHttps(app()->isProduction())`: every generated URL is `https` behind a proxy that terminates TLS;
- `Date::use(CarbonImmutable::class)`: a date passed around is never changed in place;
- `Password::defaults(…)`: one password rule for registration, reset and the settings screen.

Every PHP repo's test setup (`tests/Pest.php` or `tests/TestCase.php`) calls `Http::preventStrayRequests()` and `Process::preventStrayProcesses()`, so a test that forgot its fake fails instead of calling the network or a binary.

**Why.** On 2026-11-25 the four products already did all of the boot calls but `URL::forceHttps` (only signaculum-core), from the starter kit, without a rule that keeps a new product doing them; and the testing rules forbid the network in tests, yet only externa-core enforced it with `Http::preventStrayRequests()`, and no repo stopped stray processes.

**Checked by.** F25: in products, each call in `app/Providers/`; in every PHP repo, both `prevent*` calls under `tests/`.

**Exceptions.** A product that never runs behind TLS (a local-only tool) writes a divergence for `URL::forceHttps`.

## F27 Public APIs

**Rule.** An HTTP API that clients outside the product call (REST, not the Inertia endpoints):

- is versioned in its path, `/api/v1/…`; a breaking change (a removed or renamed field or route, a new required parameter, a changed type or error code) is a new version, `/api/v2`, never a change to `v1`;
- is described by a hand-written OpenAPI 3.1 file in `resources/openapi/<api>-v<N>.yaml`, served at `/api/v<N>/openapi.json`, with a test that fails when a route in `routes/api.php` is missing from the file (`docs/api.md`);
- answers errors in the fleet shape (F11), with named rate limiters and their `RateLimit-*` / `Retry-After` headers;
- retires a version only after announcing it: every response of the old version carries `Deprecation` (RFC 9745) and `Sunset` (RFC 8594) headers for at least six months, the CHANGELOG and the API docs say so, and the version is removed only in a major release.

**Why.** On 2026-11-25 three products had an OpenAPI file (externa-core, instituta-core, signaculum-core) and excubia-app had API routes without one; nothing said how a version ends. Clients of a self-hosted product upgrade on the operator's schedule, not ours, so an API change they cannot see coming breaks them silently.

**Checked by.** F27 (_app_), when `routes/api.php` exists: a `resources/openapi/*-v<N>.yaml` or `.json` file, a test under `tests/` that reads it, and API routes under a `v<N>` prefix.

**Exceptions.** An OAuth or OIDC endpoint keeps the path its RFC or discovery document gives it (`/oauth/token`, `/.well-known/…`) and its RFC error body (the F11 exception).

## F28 UI quality

**Rule.** A product with a UI (`uiPreset` in `qiick-standards.json`):

- calls the reusable `ui-quality.yml` (theme tokens, contrast, axe smoke) and `drift-check.yml` (registry drift and the standards), with the job ids that give the required check names `ui-quality / ui-quality` and `app-ui-drift / drift`;
- has Pest browser tests (`tests/Browser`) for the flows only a browser proves (navigation, dialogs, keyboard, focus), each run in light and dark (the testing rules);
- copies every screen from a pattern of the UI rules; a new solution is proposed in app-ui, never designed in the product.

**Why.** On 2026-11-25 externa-core, the largest UI in the fleet, did not call `ui-quality.yml`, so its theme-token and contrast regressions were found by hand; the UI rules say what the screens look like, and this convention makes the machines check that they still do.

**Checked by.** F28 (_app_ with `uiPreset`): the workflows call `ui-quality.yml` and `drift-check.yml`, and `tests/Browser/` has a test.

**Exceptions.** A product whose UI is only the starter kit's auth screens may write a divergence for the browser tests until it ships its first own screen.

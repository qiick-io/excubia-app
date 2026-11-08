# Security model of Excubia App

This document describes what Excubia App receives, where it stores it, how long it keeps it, and who can see it. It is the threat model of the security review (#51) and is kept up to date with every change to a data flow. The checks engine, the collectors and their scrubbing live in `qiick/excubia-core`; see its own `docs/SECURITY-MODEL.md`.

## Trust boundaries

| Actor                                      | Trusted for                                                        | Not trusted for                                                                                |
| ------------------------------------------ | ------------------------------------------------------------------ | ---------------------------------------------------------------------------------------------- |
| Operator (whoever runs the container)      | `.env`, `APP_KEY`, the database, the reverse proxy                 | —                                                                                              |
| Super-admin                                | every permission, roles, project settings, integrations, SSO       | —                                                                                              |
| Admin                                      | projects, users, groups, findings, exceptions, admin-only projects | roles, permissions, jobs, System page, project settings (not granted by default); super-admins |
| Reader and custom roles                    | only the permissions their roles grant                             | projects restricted to admins, any write they were not granted                                 |
| Monitored project (push token)             | pushing reports, runtime and exceptions **for its own project**    | every string it sends: package names, messages, URLs, task and route names are untrusted input |
| Identity provider (OIDC)                   | the `sub` and a verified `email` of the users it signs in          | anything else in its claims                                                                    |
| Alert receivers (webhook, Slack, Telegram) | receiving alert payloads                                           | Excubia never follows their redirects nor reads more than the status code                      |
| AI provider (off by default)               | answering questions over the read-only tool results                | tool results contain project text: the agent is told to treat it as data (prompt injection)    |

## Data flows

### 1. Push tokens

- **Created** when a project is created or its token is rotated: `excubia_` + 40 random characters (`Str::random`, CSPRNG, about 238 bits).
- **Stored** only as `sha256` in `projects.push_token_hash` (hidden from serialization and excluded from the activity log). The plain token is flashed once to the creator's session (`pushToken` flash, or the setup wizard's one-request session flash) and never stored or logged.
- **Checked** by `AuthenticateProjectToken`: the bearer token is hashed and looked up by hash. The comparison is an indexed equality on a hash of a high-entropy secret, so it leaks no usable timing. Disabled projects get 403, unknown or revoked tokens 401.
- **Rotated or revoked** by users who may edit the project (`ProjectPolicy::manageToken`); the system project has no token. Both are written to the activity log and raise a `project.token_changed` alert, without the token.
- **Rate limit** (`excubia-push`): `EXCUBIA_PUSH_REQUESTS_PER_MINUTE` per project, or per client IP for a missing or unknown token, so made-up tokens do not each get their own bucket.

### 2. Ingest (`POST /api/reports`, `/api/runtime`, `/api/exceptions`)

- **Received**: JSON envelopes of protocol version 1 (`batchId` UUID v4, `sentAt`, and `report`, `runtime` or `exceptions`). Bodies over `EXCUBIA_PUSH_MAX_KILOBYTES` (1024 KB) get 413; PHP's `post_max_size` caps it first in the Docker image (16 MB). JSON is decoded by Laravel with PHP's default depth limit (512).
- **Validated** per field in `StorePushedReportRequest`, `StorePushedRuntimeRequest` (one rule set per known runtime type: dimensions are enums, patterns or strings of at most 2000 characters, metrics are typed numbers) and `StorePushedExceptionsRequest` (class 255, message 1000, at most 50 frames, 5 previous classes). Unknown runtime types are skipped and counted (`projects.skipped_runtime_aggregates`), never stored.
- **Stored** with explicit column mapping (no mass assignment from the payload): `health_reports.report` (the report JSON as sent, except `evidenceUrl` values that are not `http(s)` and an `environment` that does not match `^[A-Za-z0-9_-]{1,64}$`, which are dropped), `projects.environment` (the last valid `environment` of a report or runtime batch), `findings` and `finding_events` (lifecycle), `scheduled_tasks` and `scheduled_task_runs`, `runtime_aggregates` and `runtime_hourly_rollups`, `exception_groups` and `exception_occurrences`. Exception messages and frames are masked by excubia-core before they leave the project (emails, IPs, tokens, hosts, numbers); the app stores what it receives.
- **Idempotent**: a repeated `batchId` stores nothing (`health_reports.batch_id`, `pushed_batches` unique per project and type), also under a concurrent retry.
- **Rejections** (4xx) are counted per hour in `ingest_rejections` (endpoint, status, reason: `token`, `disabled`, `too_large`, `throttled` or the first invalid field path). No body, token or IP is kept. Token rejections (401, 403) are also counted in the cache for the `push.rejected` alert: per project, or per client under a SHA-256 of the IP that expires after the hour; the alert carries the reason, count and endpoint, never the IP or the token.
- **AI usage** (`ai`, `aiTool`, #129): operation, provider, model, agent and tool class names, counts, durations and token, audio and search-unit usage. excubia-core never sends prompts, instructions, attachments, messages, responses, reasoning, tool arguments or results, conversation ids or users, and the AI tab says so. Costs are computed in the app from the price table (Settings → AI prices, `can-manage-alerts`); monthly budgets per project need `can-manage-alerts` on a visible project.
- **Octane workers** (`octane`, #168): server name or hashed id, Octane server and worker type names, counts, request counts at worker stop and memory in MiB. excubia-core never sends URLs, routes, request, response or task data, worker ids, PIDs or exception messages.
- **Reverb servers** (`reverb`, #175): server name or hashed id, the Reverb app id (or a hash of it), counts of messages, connections and channels by name prefix, and the peak of connections. The `laravel-reverb` check sends only whether the server answered and the connect time. excubia-core never sends message payloads, event or channel names, socket ids, origins, app keys, secrets, hosts or ports.
- **Billing webhooks** (`billingWebhook`, #178): the provider name, the provider's event type, counts and durations. excubia-core never sends anything else of a webhook payload: no amounts, currencies, customer or subscription ids, emails, cards or metadata. The `laravel-cashier` check sends booleans only, never a key, secret or prefix.
- **Authentication** (`auth`, #180): the auth guard name and counts of logins, failed logins, lockouts and Sanctum token authentications. excubia-core never sends usernames, emails, user ids, credentials, tokens or IP addresses. The `laravel-api-tokens` check sends counts, minutes and booleans only, never a token, token name or ability, user id, key, path or domain.
- **Strings are untrusted**: React escapes every string it renders; no page uses `dangerouslySetInnerHTML` on pushed data (the only uses are the 2FA QR SVG from Fortify and the paginator labels Laravel builds). Links built from pushed data are limited to `http(s)` evidence URLs. The weekly digest escapes Markdown in pushed strings (`Markdown::withSecuredEncoding`).

### 3. Projects, reports and telemetry on the pages

- **Who sees it**: users with `can-show-projects`, for projects whose visibility is "everyone"; projects restricted to admins are visible only to the `admin` and `super-admin` roles. Every project page, tab, partial reload and action is authorized against `ProjectPolicy`, and every child record (finding, exception group, alert mute, maintenance window, alert) is checked against the project in the URL. `tests/Feature/Security/RouteAuthorizationTest.php` walks every named route as a reader and as a non-admin user with every permission against an admin-only project.
- **Writes**: dismissing findings needs `can-dismiss-findings`, changing exception status `can-manage-exceptions`, alert mutes and maintenance windows `can-edit-projects`, refreshing the system report `can-run-checks`; all also need to see the project.
- **Fleet, dashboard, digest and assistant** read only `Project::visibleTo($user)`.
- **Install-wide pages** (System, Activity log, Jobs) are behind their own permissions, granted only to super-admins by default. Their per-project rows follow the same visibility (#152): System lists pushes, skipped runtime types and failed alert deliveries only for `Project::visibleTo($viewer)`; the Activity log hides entries about projects the viewer cannot see, and entries about deleted projects from non-admins (their visibility can no longer be checked); the Jobs page shows job classes, queues and the first 500 characters of the exception, never the payload, and alert delivery errors name only the channel. Install-wide counters (queue backlog, failed jobs, push rejections) stay install-wide. `RouteAuthorizationTest` checks that a non-admin holding every permission sees no admin-only project name on these pages.

### 4. Retention

| Data                                                             | Kept                                                                      | Setting                                       |
| ---------------------------------------------------------------- | ------------------------------------------------------------------------- | --------------------------------------------- |
| Health reports                                                   | the latest N per project (default 30)                                     | `EXCUBIA_REPORTS_KEPT_PER_PROJECT`, Settings  |
| Findings and finding events                                      | while the project exists (resolved findings stay as history)              | —                                             |
| Runtime aggregates, hourly rollups, task runs                    | 14 days                                                                   | `EXCUBIA_RUNTIME_RETENTION_DAYS`, Settings    |
| Daily AI usage per provider and model (#129)                     | the longer of the runtime retention and 62 days (budget and digest)       | `EXCUBIA_RUNTIME_RETENTION_DAYS`, Settings    |
| Exception groups and occurrences                                 | 90 days after last seen                                                   | `EXCUBIA_EXCEPTIONS_RETENTION_DAYS`, Settings |
| Pushed batch ids, alert history                                  | the longer of the two retentions above                                    | —                                             |
| Ended alert mutes                                                | deleted daily                                                             | —                                             |
| Ingest rejection counters                                        | 2 days                                                                    | —                                             |
| Activity log (incl. IP and user agent, failed login identifiers) | `activitylog.clean_after_days`                                            | Settings → activity retention                 |
| Assistant conversations                                          | until the user deletes them, or the user is deleted                       | —                                             |
| Sessions, failed jobs, cache                                     | Laravel defaults (`SESSION_LIFETIME`, `queue:prune-failed` not scheduled) | —                                             |

Deleting a project deletes its reports, findings, telemetry, tasks, alerts, mutes and windows (foreign keys cascade).

### 5. Users, sign-in and the first run

- **Passwords** are hashed (bcrypt), with the password policy of Settings → Security; login is throttled per email and IP; 2FA (TOTP, recovery codes encrypted with `APP_KEY`) and passkeys come from Fortify and Laravel Passkeys, and 2FA can be required for everyone. Inactive users are signed out (`EnsureUserIsActive`).
- **Roles: you can only grant what you hold** (#151, `RoleGrantService`). Super-admins grant anything. Anyone else may grant a permission they hold, and a role whose permissions they all hold, with two exceptions: `super-admin` is granted only by super-admins and `admin` only by holders of the `admin` role, because both carry more than their permissions (super-admin passes every check, admin sees projects restricted to admins). This applies to the roles of a user (`can-create-users`, `can-edit-users`), the roles of a group (`can-*-groups`) and the permissions of a custom role (`can-*-roles`; built-in roles stay read-only). Enforced in `StoreUserRequest`, `UpdateUserRequest`, `UserGroupRequest` and `RoleRequest`; the user and group drawers offer only grantable roles.
- **Taking away is granting too**: a user, group or custom role that holds a role or permission the actor could not grant is "above" the actor, and `UserPolicy`, `UserGroupPolicy` and `RolePolicy` refuse to edit, reset the password of, or delete it. So nobody strips a stronger user, and nobody takes over one by setting its email or password. A consequence: a custom role that manages users also needs the permissions of the roles its holders should manage (for example `can-show-projects` to manage readers).
- **Your own access**: only a super-admin may change their own roles, join or leave a group that carries roles, or change the roles of a group they belong to (validation errors on `roles` and `users`; the role picker is locked in the UI). The last active super-admin cannot be removed.
- `tests/Feature/Security/RoleEscalationTest.php` covers custom roles holding `can-edit-users`, `can-edit-groups` and `can-edit-roles` on every user, group and role endpoint.
- **First run (`/setup`)**: the admin step exists only while the `users` table is empty. Creation runs under a cache lock and re-checks the table inside it, so two concurrent submissions create one super-admin; the request is throttled (6 per minute). Until that step is done, anyone who can reach the app can claim it: keep a fresh install off the public network (or behind the proxy's access control) until `/setup` is finished. The project step needs a signed-in user with `can-create-projects` and only works while no project exists.
- **OIDC**: the authorization code flow with `state` and PKCE (`S256`; the verifier stays in the session and is sent with the token exchange) (Socialite); the redirect and callback routes are throttled (10 per minute per IP); the user comes from the userinfo endpoint fetched server-side with the access token. Discovery, token and userinfo URLs must be `http(s)` without credentials and must not resolve to private or metadata addresses (unless `OIDC_ALLOW_PRIVATE_HOSTS`), and redirects are not followed. A sign-in requires `email_verified`; an existing local account is linked by email once and then by `sub`. Just-in-time users get the `reader` role and only when the setting is on. The client secret is encrypted with `APP_KEY`.

### 6. Alerts, digest and outbound requests

- **Settings** (Settings → Integrations, `can-manage-project-settings`): the webhook signing secret, the Slack incoming-webhook URL and the Telegram bot token are encrypted with `APP_KEY`, never sent to the browser (`has_*` flags only) and logged only as "a secret was updated". The webhook URL and the Telegram chat id are not secrets and are shown.
- **Payload**: event id and type, severity, project name and slug, time, a link to the project, and event data (counts, subjects such as a package name or task name). Never tokens, report bodies, exception messages or user data.
- **Webhook signature**: `X-Excubia-Signature: sha256=HMAC_SHA256(secret, timestamp + "." + body)` with `X-Excubia-Timestamp` and `X-Excubia-Event-Id`. Receivers should compare in constant time, reject old timestamps and deduplicate by event id.
- **SSRF**: every delivery must be HTTPS without credentials in the URL; every IPv4 and IPv6 address of the host must be public (loopback, private, link-local, reserved and IPv4-mapped ranges are refused, as are hosts that do not resolve), the request connects to the address that was checked (`CURLOPT_RESOLVE`, no DNS rebinding), redirects are not followed, timeouts are 3 s to connect and 5 s in total. Slack URLs must start with `https://hooks.slack.com/`; Telegram goes to `api.telegram.org` only.
- **Errors**: a failed delivery records only the channel, the HTTP status or the exception class. URLs (which carry the Slack secret or the Telegram token) never reach `failed_jobs`, the logs, the Jobs page or Settings.
- **Alert emails** go to active, verified users who turned them on in their profile, only for projects they can see (checked when the alert is raised), with the same payload as the other channels. Markdown in pushed strings is escaped.
- **Weekly digest** emails go to users who opted in, with only the projects they can see: names, counts, package names, exception classes and their route or job (never the message), task names.

### 7. AI assistant (off by default)

- `EXCUBIA_AI_ENABLED=false` by default: no page, no route that answers, no outbound call. When on, users need `can-use-ai`.
- Tools are read-only and run as the asking user (`Project::visibleTo`): project names and slugs, report summaries, findings (package, version, message, fix hint, advisory id) and upgrade steps. Never push tokens, user data or file paths (covered by `tests/Feature/Assistant/AssistantToolsTest.php`).
- What leaves the server: the user's questions and those tool results, to the provider in `config/ai.php` (Ollama on the same network by default). The system prompt tells the model that tool results are data, not instructions; answers are rendered as plain text, so an injected instruction can at most change the wording of an answer, not run code or tools with more rights.
- Conversations are stored in `agent_conversations` and `agent_conversation_messages`, scoped to their owner (reading, continuing and deleting are all checked).

### 8. Secrets in logs, errors and props

- `APP_DEBUG=false` and `LOG_CHANNEL=stderr` in the Docker image: no stack traces or `.env` values on error pages.
- Shared Inertia props carry the signed-in user (password, 2FA secret and codes, remember token and `oidc_sub` are hidden), the effective permissions and role names, app name, theme and the sidebar modules; never settings secrets.
- The activity log records model changes except password, 2FA, remember-token and push-token columns, plus IP and user agent of the actor.
- Inertia DevTools is enabled only in the `local` environment.

### 9. Deployment

- **Docker image**: FrankenPHP running as `www-data`; the code under `/app` is owned by root and read-only for the app; only `/app/storage`, `bootstrap/cache` and Caddy's data are writable. Without `APP_KEY`, the entrypoint generates one into `storage/app.key` (mode 600) on the volume: back it up with the database.
- **TLS and proxies**: put a TLS reverse proxy in front. `TRUSTED_PROXIES` is empty by default, so `X-Forwarded-*` headers are ignored. Set it to the proxy address; use `*` only when nothing but the proxy can reach the container, or clients can forge their IP (rate limits, activity log) and scheme.
- **Session cookie**: `Secure` by default when `APP_URL` is `https://` (`SESSION_SECURE_COOKIE` overrides it), `HttpOnly`, `SameSite=Lax`.
- **Security headers** (`SecurityHeadersMiddleware`, every web response): `X-Frame-Options: DENY` (no framing, against clickjacking), `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin` and a `Permissions-Policy` that turns off camera, microphone, geolocation, payment and USB (WebAuthn for passkeys is untouched). A header the response already sets wins. `Strict-Transport-Security` is sent only on https requests and only when `EXCUBIA_HSTS_MAX_AGE` is above 0 (off by default: set HSTS on the TLS proxy, or set the variable when the app terminates TLS itself or `TRUSTED_PROXIES` is set). There is no full `Content-Security-Policy` yet: the Vite dev server and the inline appearance script would need nonces. API responses (JSON for the monitored projects) and `/up` are not in the `web` group and unchanged.
- **Uploaded branding files** are stored on the private disk and served with `nosniff` and a sandboxing CSP, so an SVG cannot run script.

## Dependency audit

`composer audit` and `npm audit` reported no known vulnerabilities on 2026-11-07 (develop at the time of #51). Dependabot keeps both lock files current.

## Reporting

See [`SECURITY.md`](../SECURITY.md).

# Changelog

All notable changes to **Excubia App** (`qiick-io/excubia-app`) are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Settings → System** (#117, `can-show-system`, super-admin by default): the health of the install itself, each item with a fix hint in `docs/self-hosting.md`: scheduler heartbeat, queue worker heartbeat with backlog and failed jobs, last report / runtime / exceptions push per project with stale highlighting, push rejections of the last 24 hours per endpoint, status and reason (counted in `ingest_rejections`, never the body, token or project), skipped unknown runtime types, failed alert deliveries, pending runtime rollup backfill, and the app and accepted excubia-core versions.
- Production install (#116): a `Dockerfile` (FrankenPHP, PHP 8.4) and a `docker-compose.yml` with SQLite in a volume (web server, queue worker, scheduler, healthcheck on `/up`), a `Docker` CI workflow that builds and smoke-tests the image without publishing it, `TRUSTED_PROXIES` for a TLS proxy in front, a first-run wizard at `/setup` (first admin, first project, push token with the excubia-core install snippet; locked once done), and self-hosting docs: Docker, Forge / Ploi, backups, upgrades and the app ↔ core compatibility matrix (`docs/`).
- CI on pull requests and pushes to `develop` and `main` (PHP 8.4 and 8.5), Dependabot, and an end-to-end contract test against `qiick/excubia-core` (`scripts/e2e/core-contract.sh`) (#112).
- Axe accessibility smoke test for the main pages (`tests/Browser`, Pest browser plugin with Playwright) and an `Accessibility` CI job that fails on serious or critical violations (#112).
- Every excubia-core check id (37) has a label, an explanation and a docs link, grouped as Infrastructure, Configuration and Dependencies; unknown ids render under their own name. Findings open a drawer with their structured details, the overview lists failing infrastructure first and summarises disk, database size and largest tables, connections, queue backlog, Redis memory, backups and outdated packages by patch / minor / major; the dashboard has an infrastructure column. Database, storage and disk failures alert at once as `infrastructure.failed` (#109).
- License inventory per project (Licenses tab) and the denied, strong copyleft and unknown licenses across the fleet on the dashboard (#108).

### Security

- Security review before the beta (#51): `docs/SECURITY-MODEL.md` (every data flow: what is received, stored, kept and who sees it) and the supported 1.x versions in `SECURITY.md`. Fixed: the push rate limit is keyed by project or IP instead of the presented token; alert deliveries refuse IPv6 and IPv4-mapped private addresses and hosts that do not resolve, connect to the checked address, and never quote the URL in errors; the Slack incoming-webhook URL is stored encrypted and write-only; evidence links must be `http(s)`; the weekly digest escapes Markdown from pushed strings; the session cookie is `Secure` by default when `APP_URL` is https. A test walks every named route as a reader and as a non-admin user with every permission.
- Install-wide pages respect project visibility (#152): Settings → System lists pushes, skipped runtime types and failed alert deliveries only for the projects the viewer may see, and the Activity log hides entries about admin-only (and, for non-admins, deleted) projects, so a non-admin holding `can-show-system` or `can-show-activity-logs` no longer sees admin-only project names. The Jobs page shows no project names.
- Baseline security headers on every web response (#153): `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin` and a minimal `Permissions-Policy`; `Strict-Transport-Security` on https requests when `EXCUBIA_HSTS_MAX_AGE` is set (off by default).
- OIDC sign-in uses PKCE (`S256`) on the authorization request and the token exchange, and the redirect and callback routes are throttled to 10 requests per minute (#154).

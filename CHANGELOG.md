# Changelog

All notable changes to **Excubia App** (`qiick-io/excubia-app`) are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Production install (#116): a `Dockerfile` (FrankenPHP, PHP 8.4) and a `docker-compose.yml` with SQLite in a volume (web server, queue worker, scheduler, healthcheck on `/up`), a `Docker` CI workflow that builds and smoke-tests the image without publishing it, `TRUSTED_PROXIES` for a TLS proxy in front, a first-run wizard at `/setup` (first admin, first project, push token with the excubia-core install snippet; locked once done), and self-hosting docs: Docker, Forge / Ploi, backups, upgrades and the app ↔ core compatibility matrix (`docs/`).
- CI on pull requests and pushes to `develop` and `main` (PHP 8.4 and 8.5), Dependabot, and an end-to-end contract test against `qiick/excubia-core` (`scripts/e2e/core-contract.sh`) (#112).
- Axe accessibility smoke test for the main pages (`tests/Browser`, Pest browser plugin with Playwright) and an `Accessibility` CI job that fails on serious or critical violations (#112).
- Every excubia-core check id (37) has a label, an explanation and a docs link, grouped as Infrastructure, Configuration and Dependencies; unknown ids render under their own name. Findings open a drawer with their structured details, the overview lists failing infrastructure first and summarises disk, database size and largest tables, connections, queue backlog, Redis memory, backups and outdated packages by patch / minor / major; the dashboard has an infrastructure column. Database, storage and disk failures alert at once as `infrastructure.failed` (#109).
- License inventory per project (Licenses tab) and the denied, strong copyleft and unknown licenses across the fleet on the dashboard (#108).

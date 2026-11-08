<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset=".github/assets/header-dark.svg">
    <source media="(prefers-color-scheme: light)" srcset=".github/assets/header-light.svg">
    <img alt="Excubia: Self-hosted health dashboard built on Laravel" src=".github/assets/header-light.svg" width="100%">
  </picture>
</p>

# Excubia App

[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](./LICENSE)

**Excubia App** is the open-source (MIT), self-hosted Laravel dashboard of [Excubia](https://github.com/qiick-io/excubia-core), part of the [qiick](https://qiick.io) suite. It keeps the health of all your Laravel projects in one place: PHP / Laravel / Node end of life, known vulnerabilities, abandoned, outdated and stale packages, licenses, next-major blockers and the suggested upgrade order, plus opt-in runtime monitoring, exceptions, scheduled tasks and alerts.

The checks run inside each project through [`qiick/excubia-core`](https://github.com/qiick-io/excubia-core), the Composer package with the `excubia` CLI and the checks engine. Each project pushes its reports to the app with its own token, so projects can live anywhere. The app stores them and shows them on one dashboard. It does not reimplement any check.

> **Status: beta (`1.0.0-beta`).** Feature-complete for 1.0 and ready to try on real projects. The ingest API and the core contracts are frozen; until 1.0.0 only fixes and additive changes land. Migrations can still change between betas, so upgrade one beta at a time and keep backups ([upgrades](./docs/self-hosting.md#upgrades)). Please [report issues](https://github.com/qiick-io/excubia-app/issues).

## Features

- **Reports:** worst severity per project, findings with fix hints, upgrade plan, report history, stale projects, license inventory.
- **Finding lifecycle:** first and last seen, fixed in which report, came back, per-finding history; dismiss a finding with a reason and an optional end date (the fields of an `excubia.json` ignore rule).
- **Runtime and exceptions (opt-in in each project):** requests, slow and N+1 queries, jobs, queue wait, cache, mail, outgoing HTTP, server metrics, with p50 / p95 / p99; masked exception groups.
- **Scheduled tasks:** late, missed and failed runs, "scheduler down".
- **Alerts** by mail and integrations, with mutes and maintenance windows, and a weekly digest.
- **Access:** users, groups, roles and permissions, two-factor and passkeys, optional OIDC single sign-on.
- **Self-hosted, no telemetry.** Excubia also watches its own install. Optional read-only AI assistant with a local model.

## Quick start (Docker)

```bash
git clone https://github.com/qiick-io/excubia-app.git
cd excubia-app
docker compose up -d --build
```

Open <http://localhost:8080/setup>: create the admin, the first project, and copy the install snippet into that project. The compose file runs the web server, the queue worker and the scheduler, with SQLite in a volume. For production, put a TLS proxy in front and set `APP_URL` and `TRUSTED_PROXIES`.

Laravel Forge, Ploi or a plain server, HTTPS, backups and upgrades: **[docs/self-hosting.md](./docs/self-hosting.md)**. Which excubia-core version each feature needs: **[docs/compatibility.md](./docs/compatibility.md)**.

## Add a project

1. In the app, open **Projects → New project** (or the last step of `/setup`) and give it a name. The app shows the push token **once**: copy it.
2. In the Laravel project to watch, install the core package (1.0.0-beta.1 or later, the first version with `excubia push`):

    ```bash
    composer require qiick/excubia-core:^1.0@beta
    ```

3. Add the two lines from the token dialog to the project's `.env`:

    ```dotenv
    EXCUBIA_APP_URL=https://excubia.example.com
    EXCUBIA_APP_TOKEN=excubia_…
    ```

4. Schedule the push in the project's `routes/console.php`, and keep that project's scheduler running too:

    ```php
    use Illuminate\Support\Facades\Schedule;

    Schedule::command('excubia:push')->daily();
    ```

Run `php artisan excubia:push` once to send the first report. A project that has not reported for 36 hours (setting in **Settings → Project**) shows as stale. Lost the token? Use **New push token** on the project row. **Revoke** stops pushes until you issue a new one. For the project side (CI pushes, `--strict`, exit codes), see [`qiick/excubia-core`](https://github.com/qiick-io/excubia-core#readme).

**Runtime and exceptions (optional).** With `runtime.enabled` or `exceptions.enabled` in the project's `excubia.json`, `excubia:push-runtime` runs every minute and sends per-minute aggregates (requests per route, slow queries, jobs, scheduled tasks, outgoing HTTP per host) to `/api/runtime` and masked exception groups to `/api/exceptions`, with the same URL and token. The core masks everything before it leaves the project; no request bodies, headers, users, IP addresses or query bindings are sent.

**Scheduled tasks (optional).** `excubia:push` sends the project's schedule with each report, and the runtime `scheduledTask` aggregates carry the same `taskId`. The **Schedule** tab of the project lists each task with its last run, next due time and status: late (no run within `lateAfterMinutes`, default 5, after the due time), missed (still no run at the next due time), failed, or skipped. Tasks limited to other environments (the `environment` the project reports, from excubia-core 1.0.0-beta.4; with an older core, read from the project's exceptions) and, in maintenance mode, tasks without `evenInMaintenanceMode()` are not expected to run. When `excubia:heartbeat` stops too, the tasks show "Scheduler down" and one `schedule.scheduler_down` alert is sent instead of one per task. Missed and failed runs send `schedule.missed` and `schedule.failed` alerts, except when runs are sampled (`sampleRate` below 1), where a missing run proves little.

**Live health (optional).** With `runtime.health.enabled` in the project's `excubia.json` (qiick/excubia-core 1.0.0-beta.3 and later), `excubia:push-runtime` sends one `health` sample per check and server each minute. The project overview then shows a **Live health** card (per check, per server, last sample time, refreshed every minute), and three alerts follow it: `health.failed` when a server's last two readings of a check fail (a `skipped` sample is no reading), sent again when the set of failing findings changes; `health.recovered` when no server's latest reading fails any more; and `health.stale` when a server that was sending stops for five minutes. The alerts ignore a stale server, but when every server is stale nothing recovers: silence is never a recovery. An `infrastructure.failed` of a report and a `health.failed` for the same check and finding within 15 minutes send one alert; the other is kept in the history as a duplicate. The dashboard's project list shows each project's live health too. Mutes and maintenance windows apply. Projects without health samples keep the daily report only.

**Alert channels.** Alerts go out through the queue, to the signed webhook, Slack, Telegram and email. Each channel has its own minimum severity, events and projects in **Settings → Integrations**, where admins also change the default severity of each event (the `can-manage-alerts` permission). Users turn alert emails on for themselves in **Settings → Profile**, with their own filters, for the projects they can see. The alerts one report raises go out as one Slack, Telegram and email message per channel (the webhook still gets one request per alert), and the same event, project and subject alerts at most once an hour unless it gets worse (`EXCUBIA_ALERT_THROTTLE_MINUTES`). `eol.approaching` (90, 30 and 7 days before the security support of PHP, Laravel or Node ends, `EXCUBIA_EOL_ALERT_DAYS`) and `eol.reached` alert once per threshold and runtime cycle. See [Alerts](./docs/self-hosting.md#alerts) for the events, the webhook payload and signature.

**Alert controls.** The **Alerts** tab of a project lists every alert: sent, failed delivery, suppressed (with the reason) or skipped (no channel takes it). From a row, mute the rule (the event on this project) or the issue (one finding, task, cache store…) for 1 hour, 24 hours, 7 days or forever. Maintenance windows, one-off or weekly in a timezone, suppress the project's alerts while in force, and so does the core `laravel-maintenance-mode` finding of the latest report (schedule alerts excepted: the Schedule tab already pauses the tasks that do not run in maintenance mode). `project.token_changed` is only silenced by an explicit mute, and never throttled.

## Privacy

- Projects send their reports only to this app, authenticated by their own token.
- The checks run inside each project, through `qiick/excubia-core`. They send only package names and versions to public data sources.
- The app itself calls nothing outside your server except the mail server, the OIDC provider and the AI provider you configure, and the public data sources of `qiick/excubia-core` (Packagist, OSV, endoflife.date, GitHub) for the system project's daily check. Those receive package names and versions only. Disable the system project to stop them.
- No telemetry and no third-party trackers.
- The AI assistant is off by default. When you turn it on, it sends to the AI provider you configure:
    - the questions users ask;
    - the report data its tools read: project names, findings, package names and versions, upgrade steps.

    It never sends push tokens, user data or file paths. With the default local model (Ollama), nothing leaves your network.

## Stack

PHP 8.4, Laravel 13, Inertia, React 19, TypeScript, Tailwind CSS v4, Pest, Pint, Larastan — the same stack as [Externa](https://github.com/qiick-io/externa-core).

## Contributing

Open or pick an issue first; one issue per pull request, branches from `develop`, Conventional Commits. See [`CONTRIBUTING.md`](./CONTRIBUTING.md).

### Checks

CI (`.github/workflows/ci.yml`) runs on every pull request and push to `develop` and `main`:

- **PHP 8.4** and **PHP 8.5**: `npm run build`, Pint, PHPStan, Pest, `npm run lint` and `npm run types`. Run the same locally before a push (see `CLAUDE.md`).
- **Core contract (^1.0@beta)** and **Core contract (dev-develop)**: the end-to-end test between `qiick/excubia-core` and this app.
- **Docker image** (`.github/workflows/docker.yml`): builds the production image and checks that a fresh container answers `/up` and `/setup`. Nothing is pushed; publishing to GHCR stays a manual release step.
- **Accessibility**: the axe smoke test (`tests/Browser/A11ySmokeBrowserTest.php`) on login, the first-run wizard, dashboard, projects, a project overview, runtime, exceptions and settings, in light and dark plus one high-contrast pass. It fails on any serious or critical violation. Pull requests to `develop` run only this smoke test; pushes and pull requests to `main` run every test in `tests/Browser`.

The contract test serves this app with `php artisan serve` on a throwaway SQLite database, registers a project with a push token, and creates a throwaway Laravel app that installs `qiick/excubia-core` with every runtime type and exception capture turned on. It sends some traffic to that app, then runs `excubia:check --format=json`, `excubia:push` and `excubia:push-runtime` against this app. It fails unless every command succeeds (the push commands fail on any non-2xx answer) and the app stored a report, runtime aggregates and an exception group. Runtime entries of a type this app does not know yet are reported as a warning. Run it locally after `composer install`:

```bash
scripts/e2e/core-contract.sh               # latest tag (^1.0@beta)
scripts/e2e/core-contract.sh dev-develop   # excubia-core develop
```

It needs PHP 8.4+ with `pdo_sqlite`, Composer and curl, and uses ports 8765 and 8766 (`E2E_APP_PORT`, `E2E_PROBE_PORT`). Your `.env` and database are not touched; `E2E_KEEP=1` keeps the work directory with the logs.

Browser tests (Pest browser plugin with Playwright) are not part of `php artisan test`. Run them locally after `npm run build`:

```bash
npx playwright install chromium   # once
vendor/bin/pest tests/Browser
```

## License

[MIT](./LICENSE)

# Self-hosting Excubia App

Excubia App is in **beta** (`1.0.0-beta`). The ingest API and the excubia-core contracts are frozen for 1.0, and until 1.0.0 only fixes and additive changes land. Expect rough edges, read the [changelog](../CHANGELOG.md) before each upgrade and keep backups.

- [Requirements](#requirements)
- [Install with Docker](#install-with-docker)
- [Install on a server (Composer, Forge, Ploi or by hand)](#install-on-a-server-composer-forge-ploi-or-by-hand)
- [First run](#first-run)
- [Scheduler and queue](#scheduler-and-queue)
- [Sizing](#sizing)
- [Health probes](#health-probes)
- [Backups](#backups)
- [Upgrades](#upgrades)
- [Excubia watches itself](#excubia-watches-itself)
- [System page](#system-page)
- [AI assistant (optional)](#ai-assistant-optional)

Which excubia-core version each feature needs: [compatibility.md](./compatibility.md).

## Requirements

- **HTTPS.** `excubia push` refuses plain HTTP except to localhost, so the app needs a public HTTPS URL that the monitored projects can reach.
- **A database.** SQLite is the default and enough for most installs. MySQL 8 and PostgreSQL work too and run the full test suite in CI; MariaDB works through the MySQL driver.
- **A scheduler and a queue worker.** Docker runs both for you; on a server, a cron entry and a daemon.

## Install with Docker

The repository ships a production `Dockerfile` (FrankenPHP with PHP 8.4, no Octane) and a `docker-compose.yml` that runs three containers from the same image: `app` (web server on port 8080), `queue` (`queue:work`) and `scheduler` (`schedule:work`). All state lives in one volume mounted at `/app/storage`: the SQLite database, uploaded logos and the generated application key.

```bash
git clone https://github.com/qiick-io/excubia-app.git
cd excubia-app
docker compose up -d --build
```

Open `http://localhost:8080/setup` and follow the [first run](#first-run). The `app` container is healthy once `/health/ready` answers (see [Health probes](#health-probes)); `queue` and `scheduler` start after it.

On start, the `app` container:

1. creates the SQLite database in the volume if it is missing;
2. generates an `APP_KEY` into `storage/app.key` when you did not set one (back it up with the database: it encrypts the OIDC client secret and the two-factor secrets);
3. runs `migrate --force`, then the idempotent `PermissionSeeder` and `SystemProjectSeeder`;
4. caches config, routes, views and events (`php artisan optimize`).

Only the `app` container migrates (`EXCUBIA_RUN_MIGRATIONS=false` on the others), so migrations never run twice at once.

### Configuration

`docker compose` reads a `.env` file next to `docker-compose.yml`. The values it passes to the containers:

| Variable                                                                                       | Default                 | Purpose                                                                                   |
| ---------------------------------------------------------------------------------------------- | ----------------------- | ----------------------------------------------------------------------------------------- |
| `APP_URL`                                                                                      | `http://localhost:8080` | Public URL of the app (links in mails and digests).                                       |
| `APP_KEY`                                                                                      | generated in the volume | Set it to manage the key yourself (`base64:…`, 32 bytes).                                 |
| `TRUSTED_PROXIES`                                                                              | empty                   | Reverse proxies trusted for `X-Forwarded-*`: IPs or CIDR ranges, comma-separated, or `*`. |
| `EXCUBIA_PORT`                                                                                 | `8080`                  | Host port of the web server.                                                              |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` | `log`                   | Mail for password resets, alerts and digests.                                             |

Any other setting of `.env.example` (another database, `EXCUBIA_AI_ENABLED`, retention days…) can be added under `environment:` in `docker-compose.yml`. To use MySQL or PostgreSQL, set `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD`.

### HTTPS

Put a TLS reverse proxy (Caddy, Traefik, nginx) in front of port 8080, set `APP_URL` to the public `https://` URL and `TRUSTED_PROXIES` to the address of the proxy (`*` when only the proxy can reach the container). Without `TRUSTED_PROXIES`, the app ignores the forwarded headers: it would build `http://` links and see the proxy's IP for every visitor, which also breaks the login rate limit. The app sends `X-Frame-Options`, `nosniff`, `Referrer-Policy` and `Permissions-Policy` itself; set HSTS on the proxy, or set `EXCUBIA_HSTS_MAX_AGE` (seconds, for example `31536000`) to have the app send it on https requests.

### Image without compose

```bash
docker run -d -p 8080:8080 -v excubia-storage:/app/storage --name excubia ghcr.io/qiick-io/excubia-app:1.0.0
```

Every release tag publishes `ghcr.io/qiick-io/excubia-app` (amd64 and arm64) with its exact version (`1.0.0`, `1.0.0-beta.2`), `MAJOR.MINOR` for stable releases, and `latest` for the newest stable one. Pin an exact version in production. To build the image yourself instead: `docker build -t excubia-app .` from the tag you want.

The container then serves the web only: run the scheduler and the queue with the same image and volume (`docker run … excubia-app php artisan schedule:work` and `… php artisan queue:work`, both with `-e EXCUBIA_RUN_MIGRATIONS=false`), or set `QUEUE_CONNECTION=sync` and call `php artisan schedule:run` every minute with `docker exec`.

## Install on a server (Composer, Forge, Ploi or by hand)

Requirements: PHP 8.4 with `pdo_sqlite` (or the driver of your database), Composer 2, Node 24 and npm (to build the frontend), and a web server pointing to `public/`.

### With Composer (recommended)

```bash
composer create-project qiick/excubia-app excubia
cd excubia
nvm use   # Node 24
php artisan excubia:install
```

`composer create-project` copies the app once, copies `.env` from `.env.example` and generates `APP_KEY`; it runs no migration. `php artisan excubia:install` then asks for:

1. the app name and the public URL (`APP_NAME`, `APP_URL`);
2. the database: SQLite (default), PostgreSQL, MySQL or MariaDB, with host, port, name, user and password for the last three;
3. whether to seed the permissions and the system project (yes by default).

It writes `.env`, keeps a valid `APP_KEY`, migrates, seeds, links storage and builds the frontend (`--no-npm` skips the build). It refuses to run where `APP_ENV` is `production` unless you pass `--force`, and asks before overwriting an existing `.env`. Pin a release with `qiick/excubia-app:1.0.0`.

**Scripts and CI:** every answer is an option, so `-n` installs without questions:

```bash
php artisan excubia:install -n --force \
  --name=Excubia --url=https://excubia.example.com \
  --db=pgsql --db-host=127.0.0.1 --db-port=5432 --db-database=excubia --db-username=excubia --db-password=secret
```

The installer creates no user: open `APP_URL/setup` to create the admin and the first project ([First run](#first-run)). Then add the cron entry and the queue worker ([Scheduler and queue](#scheduler-and-queue)).

> **Install only, not the update channel.** Composer copies the app once and never updates it. To upgrade, follow [Upgrades](#upgrades).

### Laravel Forge or Ploi

1. Create a site with PHP 8.4 and the web directory `/public`, and enable a Let's Encrypt certificate.
2. Install the repository `qiick-io/excubia-app`, branch `main` (or a release tag).
3. Environment: start from `.env.example` and set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://your-domain`, `APP_KEY` (Forge and Ploi generate it), and `DB_DATABASE` to an absolute path outside the release directory for SQLite (for example `/home/forge/excubia.sqlite`), or the credentials of a database the panel created.
4. Deploy script:

    ```bash
    cd $FORGE_SITE_PATH   # Ploi: cd {SITE_DIRECTORY}
    git pull origin $FORGE_SITE_BRANCH
    composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
    npm ci && npm run build
    php artisan excubia:upgrade --force   # migrate, permissions, system project, caches, queue restart
    php artisan optimize
    ```

5. Enable the scheduler (Forge: **Scheduler** → `php artisan schedule:run` every minute; Ploi: **Cronjobs** or the Laravel scheduler toggle).
6. Add a queue worker / daemon: `php artisan queue:work --tries=3 --max-time=3600`.

### By hand

```bash
git clone https://github.com/qiick-io/excubia-app.git
cd excubia-app
git checkout v1.0.0   # the release you want
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan excubia:install
```

Then set `APP_ENV=production` and `APP_DEBUG=false` in `.env` and run `php artisan optimize`.

Add the cron entry and a queue worker as in [Scheduler and queue](#scheduler-and-queue).

## First run

Open `/setup` (the login page links to it while no account exists):

1. **Admin.** Create the first account. It becomes super-admin and is logged in. There is no public sign-up: add more users later under **Users**. Once an account exists, the step is locked.
2. **First project.** Name the first Laravel app to watch (or skip and add projects later under **Projects**).
3. **Connect it.** The page shows the project's push token **once**, with what the project needs: `composer require qiick/excubia-core:^1.0@beta`, the `EXCUBIA_APP_URL` / `EXCUBIA_APP_TOKEN` lines for its `.env`, an optional `excubia.json` that turns on runtime monitoring and exception capture, and the scheduler lines. Run `php artisan excubia:push` once in the project to send the first report.

On a server without a browser at hand, `php artisan excubia:create-user` creates an account from the CLI instead (the first one becomes super-admin).

## Scheduler and queue

The app runs scheduled tasks:

- `excubia:system-report` checks this install and stores the report on the system project (daily);
- `excubia:system-telemetry` stores this install's own runtime and exceptions on the system project (every minute);
- `excubia:prune-reports` keeps the newest reports of each project (30 by default, in **Settings → Project**);
- `excubia:evaluate-schedules` flags late, missed and failed scheduled tasks of the projects (every minute);
- `excubia:downsample-runtime` folds the runtime minutes older than 48 hours into hourly rows (`EXCUBIA_RUNTIME_MINUTE_HOURS`, hourly; after an upgrade its first run folds the whole backlog);
- `excubia:prune-telemetry` keeps 14 days of runtime aggregates, their hourly rollups and scheduled-task runs, and exception groups seen in the last 90 days (`EXCUBIA_RUNTIME_RETENTION_DAYS`, `EXCUBIA_EXCEPTIONS_RETENTION_DAYS`), the alert history for the longer of the two, and the pushed batch ids for 14 days;
- `excubia:evaluate-runtime-alerts` runs the optional runtime alert rules of **Settings → Integrations** (every 15 minutes);
- `excubia:alert-stale` alerts on projects that stopped reporting (hourly);
- `excubia:send-digests` mails the weekly digest to the users who turned it on in **Settings → Profile** (Mondays at 08:00; needs a mailer);
- `activitylog:clean` trims the activity log;
- `excubia:heartbeat` (scheduled by excubia-core) and a small queued heartbeat job tell the **System** page that the scheduler and the queue worker run (every minute).

Upgrading from a version without the hourly runtime rollups of the dashboard? Run `php artisan excubia:backfill-runtime-rollups` once after `migrate` (Docker: `docker compose exec app php artisan excubia:backfill-runtime-rollups`), so the dashboard totals include the runtime pushed before.

Outside Docker, run the scheduler with a cron entry:

```cron
* * * * * cd /path/to/excubia-app && php artisan schedule:run >> /dev/null 2>&1
```

or keep `php artisan schedule:work` running. **Refresh now** on the system project queues its report, so run a queue worker (`php artisan queue:work`) or set `QUEUE_CONNECTION=sync`. Failed jobs show under **Settings → Jobs**.

## Alerts

Alerts go out through the queue, so the queue worker is required for them (Docker runs it).

- **Settings → Integrations** (the `can-manage-alerts` permission, admin and super-admin by default) configures the signed webhook, Slack and Telegram. Each channel has its own minimum severity, events and, optionally, projects (none checked: every project), and a **Send test** button. The same page sets the default severity of every event that does not take one from a finding.
- **Email:** every user who can see projects turns alert emails on in **Settings → Profile**, with their own minimum severity, events and projects, and can send a test mail to themselves. A mail goes only to active, verified users who can see the project, in their language, through the `MAIL_*` settings.
- Every delivery is recorded in the project's **Alerts** tab; failed ones also show under **Settings → Jobs**. `can-manage-alerts` is also needed to mute alerts and add maintenance windows there.
- **One message per report:** the alerts one report push raises (new and resolved findings, worse or better worst severity, skipped checks, reporting again) go out as one Slack, Telegram and email message per channel, worst first; Slack and Telegram list at most 15 of them and say how many more there are. Each alert keeps its own row in the history, with the outcome of the grouped message. The webhook is not grouped: it still gets one signed request per alert, with the payload below.
- **Throttle:** the same event, project and subject alerts at most once in `EXCUBIA_ALERT_THROTTLE_MINUTES` (60; `0` turns it off). A repeat is kept in the history as suppressed with the reason `throttled`. It still goes out when it is worse than the one sent (a higher severity, or a finding fingerprint the sent one did not have), and `project.token_changed` is never throttled. Only alerts that reached a channel count.

#### Events

| Event                                                    | Default severity             | When                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               |
| -------------------------------------------------------- | ---------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `finding.new`                                            | the finding's                | A finding that warns or fails and was not in the previous report.                                                                                                                                                                                                                                                                                                                                                                                                                                                                  |
| `finding.resolved`                                       | `info`                       | A finding of the previous report is gone because its check ran without it.                                                                                                                                                                                                                                                                                                                                                                                                                                                         |
| `project.worsened`                                       | the new worst                | The worst severity of the open findings is higher than before.                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| `project.recovered`                                      | `info`                       | The worst severity is lower than before.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           |
| `report.checks_skipped`                                  | `low`                        | A check was skipped because its data source failed (`skipReason: data-source-failure`), and it was not in the previous report: the report is incomplete. `data`: `checkIds`, `count`.                                                                                                                                                                                                                                                                                                                                              |
| `infrastructure.failed`                                  | `critical`                   | A database, storage or disk check fails.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           |
| `project.stale`                                          | `medium`                     | No report within the stale window.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 |
| `project.reporting_again`                                | `info`                       | A stale project reports again.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| `eol.approaching`                                        | `medium`, `high` from 7 days | The security support of the project's PHP, Laravel or Node cycle ends within `EXCUBIA_EOL_ALERT_DAYS` days (`90,30,7`), from the support dates of the latest report. Only the tightest threshold reached alerts, once per runtime, cycle and threshold (subject `<runtime>:<cycle>:<days>`); checked after every report and daily (`excubia:alert-eol`). A project that upgrades gets a new cycle and stops alerting for the old one. `data`: `runtime`, `version`, `cycle`, `securitySupportEndsOn`, `daysLeft`, `thresholdDays`. |
| `eol.reached`                                            | `high`                       | The security support of that cycle has ended. Once per runtime and cycle (subject `<runtime>:<cycle>:reached`), same `data` with `thresholdDays: null`. An alert held back by a maintenance window or maintenance mode is tried again the next day; a muted one is not.                                                                                                                                                                                                                                                            |
| `push.rejected`                                          | `high`                       | `EXCUBIA_PUSH_REJECTED_ALERT_AFTER` (10) pushes rejected for their token within an hour: per project for a disabled project, per client for a missing, unknown or revoked token, raised on the system project (it has no project). `data`: `reason` (`disabled` or `token`), `count`, `endpoint`. A leaked or forgotten token?                                                                                                                                                                                                     |
| `project.token_changed`                                  | `info`                       | A push token was rotated or revoked.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               |
| `project.created`, `project.deleted`, `project.disabled` | `info`                       | A project was created, deleted or disabled. `project.deleted` links to the dashboard, and its history goes with the project.                                                                                                                                                                                                                                                                                                                                                                                                       |

The health, schedule and runtime events are described with their features in the README.

### Webhook payload

A JSON `POST` to the HTTPS URL, retried 3 times with backoff (10, 30, 60 seconds). Redirects are not followed, and hosts that resolve to private addresses are refused.

```json
{
    "id": "4f8e…",
    "event": "finding.new",
    "severity": "high",
    "project": { "name": "Shop", "slug": "shop" },
    "occurredAt": "2026-11-07T12:00:00Z",
    "url": "https://excubia.example.com/projects/shop",
    "data": {
        "checkId": "composer-audit",
        "subject": "laravel/framework",
        "severity": "high",
        "message": "…"
    }
}
```

`data` holds the event's details and is an empty object when there are none. Fields may be added to the payload and to `data`, never removed or renamed; the webhook stays one request per alert even when the other channels group a report's alerts. Headers: `X-Excubia-Event` (the event), `X-Excubia-Event-Id` (the `id`), `X-Excubia-Timestamp` (Unix seconds) and `X-Excubia-Signature: sha256=<hex>`, the HMAC-SHA256 of `<timestamp>.<raw body>` with the webhook secret. Check the signature on the raw body and reject old timestamps. The test event is `ping`, with `project: null`.

## Sizing

How many projects one instance takes, measured with the ingest load test (#195). Each test project pushes what excubia-core sends with runtime monitoring on: one runtime batch a minute with about 55 series (a busy app: 10 routes, 4 jobs, cache, logs, server, health, `ai`, `octane`, `reverb`, auth, Pennant, Scout…), an exceptions batch in most minutes (1 to 3 groups) and a full report every hour. An app with less traffic sends fewer series and costs less, in proportion.

All projects push at second 0 of the minute (their schedulers fire together), and excubia-core gives up on a push after 5 seconds without an answer. It keeps the batch and retries on its next run, so nothing is lost, but the next minute then carries twice the load. The limit is therefore the **burst**: how long after second 0 the last push of the minute is answered.

Measured on one machine (Apple M1 Pro, 10 cores, shared with the database and the load generator), 8 PHP workers, real time (one minute every 60 s), 2 minutes per size:

| Database      | Projects | Pushes a minute | p50 / p95 / p99 per push | Last answer after second 0 (p99) | Errors | Queue backlog |
| ------------- | -------- | --------------- | ------------------------ | -------------------------------- | ------ | ------------- |
| SQLite        | 10       | 18              | 140 / 347 / 363 ms       | 0.4 s                            | 0      | 0             |
| SQLite        | 50       | 85              | 412 / 660 / 782 ms       | 0.9 s                            | 0      | 0             |
| SQLite        | 200      | 353             | 451 / 1027 / 1519 ms     | 3.6 s                            | 0      | 0             |
| SQLite        | 500      | 889             | 480 / 1127 / 1876 ms     | 9.8 s (50 % above 5 s)           | 0      | 0             |
| MySQL 8.0     | 10       | 17              | 109 / 183 / 185 ms       | 0.2 s                            | 0      | 0             |
| MySQL 8.0     | 50       | 86              | 218 / 329 / 498 ms       | 0.5 s                            | 0      | 0             |
| MySQL 8.0     | 200      | 349             | 262 / 394 / 437 ms       | 1.7 s                            | 0      | 0             |
| MySQL 8.0     | 500      | 891             | 254 / 414 / 450 ms       | 4.3 s                            | 0      | 0             |
| PostgreSQL 17 | 10       | 19              | 118 / 180 / 188 ms       | 0.2 s                            | 0      | 0             |
| PostgreSQL 17 | 50       | 91              | 258 / 355 / 522 ms       | 0.5 s                            | 0      | 0             |
| PostgreSQL 17 | 200      | 360             | 300 / 531 / 597 ms       | 2.2 s                            | 0      | 0             |
| PostgreSQL 17 | 500      | 877             | 292 / 447 / 495 ms       | 4.6 s                            | 0      | 0             |

The instance answers about 105 pushes a second on SQLite (one writer at a time) and 200 to 225 on MySQL and PostgreSQL (the PHP workers are the limit).

### What to plan for

- **SQLite: up to about 200 projects with runtime.** The app opens SQLite in WAL mode with immediate transactions and a busy timeout (`DB_JOURNAL_MODE=wal`, `DB_TRANSACTION_MODE=IMMEDIATE`, `DB_BUSY_TIMEOUT=10000` ms, `DB_SYNCHRONOUS=normal`), so concurrent pushes queue for the single writer instead of failing. Keep the database file on a local disk (a Docker volume is fine); WAL does not work on NFS or other network file systems.
- **MySQL 8 or PostgreSQL: up to about 500 projects per 8 PHP workers**, more with more workers and cores. Each push costs a worker 20 to 40 ms of CPU (validation and storing). FrankenPHP in the Docker image runs 2 threads per CPU; with PHP-FPM set `pm.max_children` to about twice the cores. Add cores, or a second web container on the same database, before the burst nears 5 seconds. On PostgreSQL 17, run the latest minor release.
- **Reports only, no runtime:** one report push a day per project is negligible; thousands of projects fit on SQLite.
- **The queue stays empty under ingest:** pushes are stored in the request. The queue carries alerts, the system report and the heartbeat.
- The push rate limit (`EXCUBIA_PUSH_REQUESTS_PER_MINUTE`, 30 per project by default) is far above the 2 to 3 pushes a minute a project sends.

### Disk

Runtime minutes take nearly all the space while they are minutes: at the test's 55 series a minute a project writes about 77,000 rows a day. Excubia keeps them per minute for 48 hours only (`EXCUBIA_RUNTIME_MINUTE_HOURS`, at least 24); every hour `excubia:downsample-runtime` folds older minutes into one row per series and hour (#212), with the same dimensions, counts, totals, maxima, histograms and health status, so the 24 h, 7 d and 14 d ranges, percentiles and charts show the same numbers as before the fold. The hourly rows stay for `EXCUBIA_RUNTIME_RETENTION_DAYS` (14 by default, the longest time range; `excubia:prune-telemetry` deletes older ones every day).

Measured with `scripts/load/ingest-load.php --fold` (50 busy projects, 120 minutes; `runtime_aggregates` with its indexes, compacted):

| Per busy project (55 series) | SQLite | PostgreSQL 17 | MySQL 8.0 |
| ---------------------------- | ------ | ------------- | --------- |
| One day in minutes           | 34 MB  | 39 MB         | 43 MB     |
| One day in hours             | 0.6 MB | 0.7 MB        | 1.8 MB    |
| Held with the defaults       | 75 MB  | 88 MB         | 108 MB    |
| Held before #212 (14 days)   | 473 MB | 553 MB        | 605 MB    |

"Held with the defaults" is 2 days of minutes and 12 days of hours, the size once the retention is reached; it then stays flat, as the daily prune frees as much as a day adds and the databases reuse the freed pages (`VACUUM` gives SQLite's free pages back to the disk). Next to it come the batch ids of the last 14 days (about 0.4 MB a day per project, 6 MB in all), exception occurrences (below, 1 to 2 MB) and the latest 30 reports per project (about 1 MB). So plan for:

| Projects (busy, 55 series) | SQLite | PostgreSQL | MySQL  |
| -------------------------- | ------ | ---------- | ------ |
| 10                         | 0.9 GB | 1 GB       | 1.2 GB |
| 50                         | 4.2 GB | 4.8 GB     | 5.9 GB |
| 200                        | 17 GB  | 19 GB      | 24 GB  |

Exception occurrences (the per-minute counts behind the Exceptions tab's "In range" column and each group's chart) follow the runtime minutes (#214): the same hourly run folds those older than `EXCUBIA_RUNTIME_MINUTE_HOURS` into one row per group and hour, and the daily prune deletes them after `EXCUBIA_EXCEPTION_OCCURRENCE_DAYS` (14 by default, at least 14, the longest range; never longer than the exceptions retention). The exception groups themselves stay for `EXCUBIA_EXCEPTIONS_RETENTION_DAYS` (90) with their totals, first and last seen and releases, which the overview, the digest and the 90-day list read. Measured the same way (`exception_occurrences` with its indexes, the test's one to three of 15 groups a minute; MySQL over 480 minutes, as InnoDB grows a small table's file in large steps):

| Per busy project (15 groups) | SQLite  | PostgreSQL 17 | MySQL 8.0 |
| ---------------------------- | ------- | ------------- | --------- |
| One day in minutes           | 0.24 MB | 0.32 MB       | 0.78 MB   |
| One day in hours             | 0.04 MB | 0.06 MB       | 0.05 MB   |
| Held with the defaults       | 1 MB    | 1.4 MB        | 2.2 MB    |
| Held before #214 (90 days)   | 22 MB   | 29 MB         | 70 MB     |

Scale by your series: an app with 10 series a minute needs a fifth of the runtime part. The per-minute window costs the most: 24 hours instead of 48 saves a day of minutes per project, at the price of 1 h and 6 h ranges that cannot look back further than a day. A longer `EXCUBIA_RUNTIME_RETENTION_DAYS` adds one day of hours per day (and lets the Pennant stale flags look back further).

### Run the load test

`scripts/load/ingest-load.php` (PHP and curl only; manual, not part of CI) creates N projects with push tokens in the app's database and pushes from them every simulated minute against a running app. It prints p50 / p95 / p99 per endpoint, the error rate, the burst (when each push got its answer after second 0, and the share above 5 s), the queue backlog and the database growth per simulated day; with `--fold`, on a fresh database, it then folds the minutes into hours and prints the size of the runtime and exception occurrence tables per project and day in minutes and in hours. Run it against a test install, never production: it adds projects and data (`--cleanup` deletes the projects again).

```bash
# In the app directory, with the same .env as the server, and a queue worker running.
EXCUBIA_PUSH_REQUESTS_PER_MINUTE=100000 PHP_CLI_SERVER_WORKERS=8 php artisan serve --no-reload &   # or the Docker stack
php artisan queue:work &

php scripts/load/ingest-load.php --url=http://127.0.0.1:8000 --projects=200 --minutes=3                # real time: a minute every 60 s
php scripts/load/ingest-load.php --url=http://127.0.0.1:8000 --projects=20 --minutes=120 --interval=0  # back to back: ceiling and growth
php scripts/load/ingest-load.php --cleanup
```

Options: `--interval` (real seconds per simulated minute; `0` plays them back to back, which needs the high rate limit above), `--concurrency` (requests open at once, 64 by default, like a proxy in front of the workers), `--report-every` (minutes between a project's reports, 60), `--json`.

## Health probes

Two endpoints answer without signing in, for load balancers, Docker and uptime monitors (as in externa-core):

- `GET /health/live`: `{"status":"ok"}` while PHP answers. No dependency is checked.
- `GET /health/ready`: `{"status":"ok","checks":{"database":"ok"}}` with 200 when the database answers (and Redis, when the queue, cache, session or broadcast driver uses it); `"status":"fail"` with 503 otherwise. The Docker image uses it as its `HEALTHCHECK`.

Laravel's `/up` still answers too. The scheduler, the queue worker and the pushes are on the [System page](#system-page).

## Backups

Back up the database and, with Docker, the application key. Everything else (code, vendor, built assets) comes back from the repository.

### SQLite

Copy the database with SQLite's online backup, which is safe while the app writes:

```bash
# Docker
docker compose exec app php -r '$db = new SQLite3("/app/storage/database.sqlite"); $db->exec("VACUUM INTO \"/app/storage/backup.sqlite\"");'
docker compose cp app:/app/storage/backup.sqlite ./excubia-$(date +%F).sqlite
docker compose cp app:/app/storage/app.key ./excubia-app.key   # only when APP_KEY is generated
docker compose exec app rm /app/storage/backup.sqlite

# Server
sqlite3 /path/to/database.sqlite ".backup '/backups/excubia-$(date +%F).sqlite'"
```

Do not copy the `.sqlite` file with `cp` while the app runs: a write in progress can leave a broken copy, and the latest writes may still sit in `database.sqlite-wal` (the app opens SQLite in WAL mode, see [Sizing](#sizing)). To back up the whole Docker volume instead, stop the containers first (`docker compose stop`) and archive the volume.

### MySQL, MariaDB, PostgreSQL

```bash
mysqldump --single-transaction --routines excubia > excubia-$(date +%F).sql
pg_dump --format=custom excubia > excubia-$(date +%F).dump
```

### Restore

Stop the app (`docker compose stop` or `php artisan down`), put the database file back (or load the dump; for SQLite, delete any `database.sqlite-wal` and `database.sqlite-shm` left next to it first), restore the same `APP_KEY`, start again. The entrypoint and the deploy script run any pending migration.

Keep the `APP_KEY` with the backups: without it, the OIDC client secret and the two-factor secrets cannot be read, and every user is logged out. Push tokens are stored as hashes and do not depend on the key.

## Upgrades

Read the [changelog](../CHANGELOG.md) first: every version has **Upgrade notes** (breaking changes, new settings, steps). Then back up the database, `.env` and `storage/` ([Backups](#backups)).

### How updates reach you

| Fact                          | Meaning                                                                                                                                                                                                                                  |
| ----------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `composer create-project`     | **Install once.** It copies the app into a new directory. It is not how later releases reach you.                                                                                                                                        |
| Your git remote               | Once you push that tree to **your** repository, it tracks your commits. It does not follow `qiick-io/excubia-app` by itself.                                                                                                             |
| Recommended update channel    | **Git:** check out or merge a release tag `vX.Y.Z` (Path A).                                                                                                                                                                             |
| `php artisan excubia:upgrade` | Runs after the code is on the new release: `migrate --force`, the permission and system project sync, `optimize:clear`, `queue:restart`. It runs no `composer` or `npm`. Opt-in: `--sync-upstream=vX.Y.Z` merges the official tag first. |
| Docker                        | Pull a newer GHCR image tag and keep the volume (Path C). The container migrates on start.                                                                                                                                               |

### Database migrations

From `1.0.0-beta.1` on, migrations are **forward-only**: a released migration is never edited, renamed or deleted, and every schema change ships as a new migration. Every release therefore upgrades the database of the one before in place, with your data:

1. **Back up first** ([Backups](#backups)): the database and the `APP_KEY`. A migration cannot be rolled back on a production database without that backup.
2. Run the migrations: `php artisan excubia:upgrade --force` runs `php artisan migrate --force` for you (`--force` because `APP_ENV` is `production`). Docker runs them when the `app` container starts. Never `migrate:fresh` or `migrate:refresh` on an install you keep: they drop every table.
3. Check that nothing is left: `php artisan migrate:status` lists no `Pending` migration.

Upgrading one release at a time, in order, is the safest path. CI fails a pull request that changes a released migration (the `Migrations frozen` check), and the `Install and upgrade` job upgrades the previous release's database to the new code.

### Path A: Git tag (recommended)

**You cloned `qiick-io/excubia-app`:**

```bash
php artisan down
git fetch --tags origin
git checkout v1.0.1   # the release you want
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan excubia:upgrade --force
php artisan optimize
php artisan up
```

**You used create-project and pushed to your own remote:** add Excubia as `upstream` once and merge the release tag, then run the same `composer`, `npm` and `excubia:upgrade` steps:

```bash
git remote add upstream https://github.com/qiick-io/excubia-app.git   # once
git fetch --tags upstream
git merge v1.0.1   # resolve conflicts if you changed app code
```

### Assisted sync (`--sync-upstream`)

Opt-in: plain `excubia:upgrade` never pulls code. With `--sync-upstream=vX.Y.Z` the command:

1. needs a git working tree, clean unless `--force`;
2. adds the remote `upstream` → `https://github.com/qiick-io/excubia-app.git` if missing, fetches the tags and checks the tag exists;
3. lists the files that change (`git diff --name-status HEAD vX.Y.Z`); with `--dry-run` it stops here;
4. asks for confirmation (skip it with `--yes`), merges the tag (`git merge --no-edit`) and stops on a conflict, before migrating;
5. then runs the usual upgrade steps.

```bash
php artisan excubia:upgrade --sync-upstream=v1.0.1 --dry-run   # preview only
php artisan excubia:upgrade --sync-upstream=v1.0.1             # merge, then upgrade
```

It may overwrite files you changed: on a heavily customised tree, merge by hand (Path A). It still runs no `composer` or `npm`: install the dependencies after the merge, before `excubia:upgrade`.

### Path B: replace the tree

If the site is not under git: back up, `composer create-project qiick/excubia-app:1.0.1 /tmp/excubia-new`, copy `.env` (and `storage/` if it is not on a shared path) into it, run `php artisan excubia:upgrade --force` there with the same database, then switch the web root. `composer update` never refreshes the application code.

### Path C: Docker

Keep the volume, pin the new tag and recreate the containers:

```bash
docker pull ghcr.io/qiick-io/excubia-app:1.0.1
docker compose up -d   # with the image set to ghcr.io/qiick-io/excubia-app:1.0.1
```

The `app` container migrates and seeds on start. Building from source instead: `git checkout v1.0.1 && docker compose up -d --build`.

### Smoke checklist

- [ ] `GET /health/ready` answers 200
- [ ] Sign in and open the dashboard and one project
- [ ] Settings → System: the scheduler and the queue worker beat, the last pushes are recent

### Good to know

- **Monitored projects** do not need to upgrade with the app: it accepts reports, runtime and exception pushes from every excubia-core 1.x and stores runtime types it does not know yet (see [compatibility.md](./compatibility.md)). Upgrade excubia-core in the projects when you want the newer checks.
- **Rollback:** restore the backup taken before the upgrade and run the previous version.

## Excubia watches itself

The **Excubia** system project runs the `qiick/excubia-core` checks on this install every day, with no push token: the report is built and stored locally. Only package names and versions leave the server, as for any project. It cannot be deleted or renamed. Disable it in its drawer to stop the checks. Everyone with access to projects sees it; an admin can set its **Visibility** to _Admins only_, since the instance's own findings can be sensitive.

It also records this install's own runtime and exceptions: excubia-core's collectors run on the app, and `excubia:system-telemetry` moves them to the system project, with no push token or HTTP call. Turn it off with `EXCUBIA_SELF_MONITORING=false`.

**Runtime types.** By default the system project records every runtime type the installed `qiick/excubia-core` collects and this app stores (requests, slow queries, jobs, scheduled tasks, outgoing HTTP, memory, queue wait, mail, notifications, log levels, deprecations, duplicate queries, Artisan commands, server, live health and the newer types of the core), with `cache` sampled at one event in ten. Types of packages this install does not use (Octane, Reverb, Cashier, Scout, Pennant) simply stay empty. To choose them, list their `excubia.json` keys in `.env`, for example:

```dotenv
EXCUBIA_SELF_MONITORING_TYPES=requests,slowQueries,jobs,scheduler,outgoingHttp,queueWait,server,health
```

`all` turns on every available type. The setting lives in `.env`, not in the shipped `resources/excubia/self-monitoring.json`, so `git pull`, `excubia:upgrade --sync-upstream` and a new Docker image keep it; run `php artisan config:clear` (or `optimize`) after a change when the config is cached. **Settings → System → [Self-monitoring](#self-monitoring)** shows which types are on.

Every minute `excubia:system-telemetry` also takes this machine's `server` sample (CPU, memory, disk) and, at most once a minute, the `health` samples of the core's local checks (database, cache, storage, disk, queue, scheduler, Redis), as `excubia:push-runtime` does in a monitored project, so the system project has a **Servers** row and the **Live health** card. The server is named by `EXCUBIA_SERVER_NAME`, else a hashed id.

## System page

**Settings → System** shows the health of this install. Super-admins see it; give other roles the `can-show-system` permission. Each item links to its section below.

### Scheduler heartbeat

The scheduler runs `excubia:heartbeat` every minute. No run in the last 5 minutes means the scheduler stopped: check the cron entry or `php artisan schedule:work` (Docker: the `scheduler` service, `docker compose ps`). See [Scheduler and queue](#scheduler-and-queue).

### Queue worker

The scheduler queues a heartbeat job every minute; a worker that runs it is alive. No run in the last 5 minutes while the scheduler runs means no worker: start `php artisan queue:work` (Docker: the `queue` service) or set `QUEUE_CONNECTION=sync`. The status is unknown while the scheduler is down. After a deploy, `php artisan queue:restart`. Pending jobs are the default queue's backlog; failed jobs are retried or deleted under **Settings → Jobs**.

### Pushes per project

The last report, runtime and exceptions push of each enabled project. A report older than **Stale after** (**Settings → Project**) or runtime older than 15 minutes is highlighted. In the project, check that its scheduler runs `excubia:push` (daily) and, for runtime, `excubia:push-runtime` (scheduled by excubia-core), and that `EXCUBIA_APP_URL` and `EXCUBIA_APP_TOKEN` are set. Exceptions are only pushed when there are some.

### Ingest rejections

Pushes the app answered with a 4xx in the last 24 hours, per endpoint and reason. Only the endpoint, the status and the reason are kept, never the body, the token or the project.

- `token` (401): missing, unknown or revoked token. Rotate the token in the project's drawer and update `EXCUBIA_APP_TOKEN` in the project.
- `disabled` (403): the project is disabled in Excubia. Enable it in its drawer.
- `too_large` (413): the body is above `EXCUBIA_PUSH_MAX_KILOBYTES`. Raise it, or push runtime more often.
- `throttled` (429): more than `EXCUBIA_PUSH_REQUESTS_PER_MINUTE` pushes per minute for one token.
- A field name (422): the push does not match the schema, usually a core that is too old or too new. See [compatibility.md](./compatibility.md).

### Unknown runtime types

Runtime entries of types this app does not know yet, sent by a newer excubia-core, are skipped on store (the rest of the push is kept). Upgrade the app to see them, or turn the type off in the project's `excubia.json`.

### Alert delivery failures

Alerts of the last 24 hours that a channel failed to deliver. Check the channel under **Settings → Integrations** with **Send test**, and that a queue worker runs. The project's **Alerts** tab has the full history.

### Runtime rollups

The dashboard totals read hourly rollups. When runtime from before the rollups is stored, run `php artisan excubia:backfill-runtime-rollups` once (Docker: `docker compose exec app php artisan excubia:backfill-runtime-rollups`).

### Self-monitoring

The runtime types the system project records ([Excubia watches itself](#excubia-watches-itself)): the defaults of the installed excubia-core, or the list in `EXCUBIA_SELF_MONITORING_TYPES`. A name in that variable that matches no type is a warning: check its spelling against the `excubia.json` keys (`queueWait`, `notifications`, `logLevels`), or upgrade the app when the type is newer than it.

### Version and compatibility

The app version, the excubia-core installed in the app, and the core constraint and protocol version the app accepts from projects. See [compatibility.md](./compatibility.md) and [Upgrades](#upgrades).

## AI assistant (optional)

Off by default. To turn it on, run a model with [Ollama](https://ollama.com) and add to `.env`:

```dotenv
EXCUBIA_AI_ENABLED=true
OLLAMA_URL=http://localhost:11434
OLLAMA_MODEL=qwen3.5:4b
```

Pick a model that supports tool calling. For an OpenAI-compatible local server such as LM Studio, set `AI_DEFAULT_PROVIDER=local`, `LOCAL_AI_URL` and `LOCAL_AI_MODEL` instead. Users with the `can-use-ai` permission (admin and super-admin by default) see **Assistant** in the sidebar. The assistant is read-only. To use a cloud provider, set `AI_DEFAULT_PROVIDER` and that provider's key (see `config/ai.php`); the questions and the report data its tools read then leave your server (never push tokens, user data or file paths).

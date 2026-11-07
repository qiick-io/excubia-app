# Self-hosting Excubia App

Excubia App is in **beta** (`1.0.0-beta`). The ingest API and the excubia-core contracts are frozen for 1.0, and until 1.0.0 only fixes and additive changes land. Expect rough edges, read the [changelog](../CHANGELOG.md) before each upgrade and keep backups.

- [Requirements](#requirements)
- [Install with Docker](#install-with-docker)
- [Install on a server (Forge, Ploi or by hand)](#install-on-a-server-forge-ploi-or-by-hand)
- [First run](#first-run)
- [Scheduler and queue](#scheduler-and-queue)
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

Open `http://localhost:8080/setup` and follow the [first run](#first-run). The `app` container is healthy once `/up` answers; `queue` and `scheduler` start after it.

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
docker build -t excubia-app .
docker run -d -p 8080:8080 -v excubia-storage:/app/storage --name excubia excubia-app
```

The container then serves the web only: run the scheduler and the queue with the same image and volume (`docker run … excubia-app php artisan schedule:work` and `… php artisan queue:work`, both with `-e EXCUBIA_RUN_MIGRATIONS=false`), or set `QUEUE_CONNECTION=sync` and call `php artisan schedule:run` every minute with `docker exec`.

A published image on GHCR is planned; until then, build it from the tag you want to run.

## Install on a server (Forge, Ploi or by hand)

Requirements: PHP 8.4 with `pdo_sqlite` (or the driver of your database), Composer 2, Node 24 and npm (to build the frontend), and a web server pointing to `public/`.

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
    php artisan migrate --force
    php artisan db:seed --class=PermissionSeeder --force
    php artisan db:seed --class=SystemProjectSeeder --force
    php artisan optimize
    php artisan queue:restart
    ```

5. Enable the scheduler (Forge: **Scheduler** → `php artisan schedule:run` every minute; Ploi: **Cronjobs** or the Laravel scheduler toggle).
6. Add a queue worker / daemon: `php artisan queue:work --tries=3 --max-time=3600`.

### By hand

```bash
git clone https://github.com/qiick-io/excubia-app.git
cd excubia-app
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

Edit `.env` (`APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`), then:

```bash
touch database/database.sqlite   # SQLite only
php artisan migrate --force
php artisan db:seed --class=PermissionSeeder --force
php artisan db:seed --class=SystemProjectSeeder --force
npm ci && npm run build
php artisan optimize
```

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
- `excubia:prune-telemetry` keeps 14 days of runtime aggregates, their hourly rollups and scheduled-task runs, and exception groups seen in the last 90 days (`EXCUBIA_RUNTIME_RETENTION_DAYS`, `EXCUBIA_EXCEPTIONS_RETENTION_DAYS`), and the alert history for the longer of the two;
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

Do not copy the `.sqlite` file with `cp` while the app runs: a write in progress can leave a broken copy. To back up the whole Docker volume instead, stop the containers first (`docker compose stop`) and archive the volume.

### MySQL, MariaDB, PostgreSQL

```bash
mysqldump --single-transaction --routines excubia > excubia-$(date +%F).sql
pg_dump --format=custom excubia > excubia-$(date +%F).dump
```

### Restore

Stop the app (`docker compose stop` or `php artisan down`), put the database file back (or load the dump), restore the same `APP_KEY`, start again. The entrypoint and the deploy script run any pending migration.

Keep the `APP_KEY` with the backups: without it, the OIDC client secret and the two-factor secrets cannot be read, and every user is logged out. Push tokens are stored as hashes and do not depend on the key.

## Upgrades

Read the [changelog](../CHANGELOG.md) first, back up, then:

```bash
# Docker
git fetch --tags && git checkout v1.0.0-beta.2   # the version you want
docker compose up -d --build

# Server (or the Forge / Ploi deploy script above)
php artisan down
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan db:seed --class=PermissionSeeder --force
php artisan db:seed --class=SystemProjectSeeder --force
php artisan optimize
php artisan queue:restart
php artisan up
```

- **During the beta,** migrations can change between betas. Upgrade one beta at a time, in order, and always with a backup. From 1.0.0 on, migrations are forward-only and every release upgrades from the previous one.
- **Monitored projects** do not need to upgrade with the app: the app accepts reports, runtime and exception pushes from every excubia-core 1.x, and stores runtime types it does not know yet without failing (see [compatibility.md](./compatibility.md)). Upgrade excubia-core in the projects when you want the newer checks.
- **Rollback:** restore the backup taken before the upgrade and run the previous version.

## Excubia watches itself

The **Excubia** system project runs the `qiick/excubia-core` checks on this install every day, with no push token: the report is built and stored locally. Only package names and versions leave the server, as for any project. It cannot be deleted or renamed. Disable it in its drawer to stop the checks. Everyone with access to projects sees it; an admin can set its **Visibility** to _Admins only_, since the instance's own findings can be sensitive.

It also records this install's own runtime and exceptions: excubia-core's collectors run on the app, and `excubia:system-telemetry` moves them to the system project, with no push token or HTTP call. Turn it off with `EXCUBIA_SELF_MONITORING=false`.

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

<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset=".github/assets/header-dark.svg">
    <source media="(prefers-color-scheme: light)" srcset=".github/assets/header-light.svg">
    <img alt="Excubia: Self-hosted health dashboard built on Laravel" src=".github/assets/header-light.svg" width="100%">
  </picture>
</p>

# Excubia App

[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](./LICENSE)

**Excubia App** is the optional, open-source (MIT), self-hosted Laravel dashboard of [Excubia](https://github.com/qiick-io/excubia-core), part of the [qiick](https://qiick.io) suite. It keeps the health of all your Laravel projects in one place: PHP / Laravel / Node end of life, known vulnerabilities, abandoned and stale packages, next-major blockers and the suggested upgrade order.

It is built on [`qiick/excubia-core`](https://github.com/qiick-io/excubia-core), the Composer package that ships the `excubia` CLI and the checks engine. The app does not reimplement any check. Each monitored project installs `qiick/excubia-core` and pushes its reports to the app with a per-project token, so projects can live anywhere. The app stores the reports and shows them on a dashboard.

> **Status:** early development. Nothing is released yet and it is not ready for production. See the [v0.1.0 milestone](https://github.com/qiick-io/excubia-app/milestone/1) for the plan.

## Planned for v0.1.0

- Register projects with a push token and receive the reports they send (`excubia push`)
- Dashboard: worst severity per project, findings, upgrade plan, report history, stale projects
- Private login with users, groups, roles and permissions, and optional OIDC single sign-on (same model as Externa)
- Self-hosted, no telemetry

Later: opt-in runtime monitoring and exception tracking (sent by `qiick/excubia-core`), alerts, and a hosted version on qiick Cloud.

## Self-hosting

### Requirements

- PHP 8.4 with the `pdo_sqlite` extension (or the driver of the database you use), Composer 2
- Node 24 and npm, to build the frontend
- A web server pointing to `public/` (nginx, Apache, Caddy or Laravel Herd), served over HTTPS: `excubia push` refuses plain HTTP except to localhost
- Cron (or a process manager) for the Laravel scheduler

SQLite is the default database. MySQL, MariaDB and PostgreSQL work too: set `DB_CONNECTION` and the `DB_*` values in `.env`.

### Install

```bash
git clone https://github.com/qiick-io/excubia-app.git
cd excubia-app
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

Edit `.env`: set `APP_ENV=production`, `APP_DEBUG=false` and `APP_URL` to the public HTTPS URL of the app. Projects push to that URL. Then:

```bash
touch database/database.sqlite   # SQLite only
php artisan migrate --force
php artisan db:seed --class=SystemProjectSeeder --force
npm ci && npm run build
php artisan excubia:create-user
```

`SystemProjectSeeder` adds the **Excubia** system project, so the app watches itself from the start (see below). `excubia:create-user` asks for a name, an email and a password. The first user becomes super-admin. There is no public sign-up, so add more users here or under **Users** in the app.

### Scheduler and queue

The app runs scheduled tasks:

- `excubia:system-report` checks this Excubia install and stores the report on the system project (daily);
- `excubia:prune-reports` keeps the newest reports of each project (30 by default, in **Settings → Project**);
- `activitylog:clean` trims the activity log.

Run the Laravel scheduler with a cron entry:

```cron
* * * * * cd /path/to/excubia-app && php artisan schedule:run >> /dev/null 2>&1
```

or keep `php artisan schedule:work` running. **Refresh now** on the system project queues its report, so run a queue worker (`php artisan queue:work`) or set `QUEUE_CONNECTION=sync`. Failed jobs, if any, show under **Settings → Jobs**.

### Excubia watches itself

The **Excubia** system project runs the `qiick/excubia-core` checks on this install every day, with no push token: the report is built and stored locally. Only package names and versions leave the server, as for any project. It cannot be deleted or renamed. Disable it in its drawer to stop the checks. Everyone with access to projects sees it; an admin can set its **Visibility** to _Admins only_, since the instance's own findings can be sensitive. The same setting works on any project.

### Add a project

1. In the app, open **Projects → New project** and give it a name. The app shows the push token **once**: copy it.
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

### AI assistant (optional)

Off by default. To turn it on, run a model with [Ollama](https://ollama.com) and add to `.env`:

```dotenv
EXCUBIA_AI_ENABLED=true
OLLAMA_URL=http://localhost:11434
OLLAMA_MODEL=qwen3.5:4b
```

Pick a model that supports tool calling. For an OpenAI-compatible local server such as LM Studio, set `AI_DEFAULT_PROVIDER=local`, `LOCAL_AI_URL` and `LOCAL_AI_MODEL` instead. Users with the `ai.use` permission (admin and super-admin by default) see **Assistant** in the sidebar and can ask questions about the stored reports, such as "which projects have critical advisories?" or "what got worse since last week?". The assistant is read-only. To use a cloud provider instead, set `AI_DEFAULT_PROVIDER` and that provider's key (see `config/ai.php`). The data in the Privacy section below then leaves your server.

### Update

```bash
php artisan down
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan db:seed --class=SystemProjectSeeder --force   # once; does nothing if it exists
npm ci && npm run build
php artisan optimize
php artisan up
```

### Privacy

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

Open or pick an issue first; one issue per pull request, branches from `develop`, Conventional Commits. Details will land in `CONTRIBUTING.md`.

## License

[MIT](./LICENSE)

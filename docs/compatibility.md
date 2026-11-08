# Compatibility: Excubia App and excubia-core

Each monitored project installs [`qiick/excubia-core`](https://github.com/qiick-io/excubia-core) and pushes to the app. The app and the core are released separately, so a project does not have to upgrade when the app does.

## Rules

- **Protocol version 1.** The app accepts every report, runtime and exception push of protocol version 1, which every excubia-core 1.x sends (`/api/reports`, `/api/runtime`, `/api/exceptions`; frozen in the core's `docs/CONTRACTS.md`). Breaking changes would need protocol version 2 and a new major of both. Endpoints, status codes, error bodies and limits: [API.md](./API.md).
- **Every runtime type is accepted.** An aggregate of a type the app does not know yet (sent by a newer core) is skipped on store, never rejected, so the push of the known types succeeds. Newer optional fields are ignored the same way. Older cores simply send less: the matching tab or column stays empty.
- **Every check id is shown.** Findings of a check id the app has no label for render under the id itself.
- **Pushing needs excubia-core 1.0.0-beta.1 or later**, the first version with `excubia push`. 0.x has no push.

Install the core with the constraint the first-run wizard shows:

```bash
composer require qiick/excubia-core:^1.0@beta
```

## App versions

| Excubia App            | Accepts excubia-core       | Recommended       | Tested in CI                                             |
| ---------------------- | -------------------------- | ----------------- | -------------------------------------------------------- |
| 1.0.0-beta (`develop`) | 1.0.0-beta.1 and later 1.x | latest 1.0.0-beta | `^1.0@beta` and `dev-develop` (end-to-end contract test) |

## Features and the core version they need

| App feature                                                                                     | Needs excubia-core                                 | Turn on in the project                                                 |
| ----------------------------------------------------------------------------------------------- | -------------------------------------------------- | ---------------------------------------------------------------------- |
| Reports: findings, worst severity, upgrade plan, history, stale projects                        | 1.0.0-beta.1                                       | `Schedule::command('excubia:push')->daily();`                          |
| Project versions (PHP, Laravel, Node) on the overview                                           | 1.0.0-beta.1                                       | —                                                                      |
| Runtime: requests, slow queries, jobs, scheduled tasks, outgoing HTTP                           | 1.0.0-beta.1                                       | `excubia.json` `runtime.enabled`                                       |
| Exceptions (masked groups)                                                                      | 1.0.0-beta.1                                       | `excubia.json` `exceptions.enabled`                                    |
| Scheduler heartbeat ("scheduler down")                                                          | 1.0.0-beta.1                                       | — (scheduled by the core)                                              |
| Schedule tab: late, missed and failed tasks                                                     | 1.0.0-beta.2 (schedule inventory, `taskId`)        | —                                                                      |
| Releases and deploy markers                                                                     | 1.0.0-beta.2                                       | `EXCUBIA_RELEASE` or `release` in `excubia.json`                       |
| Licenses tab and fleet license summary                                                          | 1.0.0-beta.2 (`dependency-licenses`)               | —                                                                      |
| Outdated Composer and npm packages (patch / minor / major)                                      | 1.0.0-beta.2 (`composer-outdated`, `npm-outdated`) | —                                                                      |
| Infrastructure: database, cache, storage, disk, queue backlog and workers                       | 1.0.0-beta.2                                       | —                                                                      |
| Latency percentiles (p50, p95, p99)                                                             | 1.0.0-beta.2 (histograms)                          | —                                                                      |
| Runtime: request and job memory, queue wait, cache, mail, notifications, log levels             | 1.0.0-beta.2                                       | `runtime.<type>.enabled`                                               |
| Runtime: deprecations, N+1 duplicate queries, Artisan commands                                  | 1.0.0-beta.2                                       | `runtime.deprecations`, `runtime.duplicateQueries`, `runtime.commands` |
| AI tab: AI usage, estimated cost, budgets and alerts (laravel/ai)                               | 1.0.0-beta.4 (`ai`, `aiTool`)                      | `runtime.ai`, `runtime.aiTools`                                        |
| Server CPU, memory and disk                                                                     | 1.0.0-beta.2                                       | `runtime.server.enabled`                                               |
| Container limits on server metrics, log channel per log level                                   | next core release (`dev-develop`)                  | —                                                                      |
| Backups, database connections and size, Redis memory, OPcache and production `php.ini` findings | next core release (`dev-develop`)                  | —                                                                      |

"Next core release" features already work with `qiick/excubia-core:dev-develop`; with an older core the app shows nothing for them.

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

## Stack

PHP 8.4, Laravel 13, Inertia, React 19, TypeScript, Tailwind CSS v4, Pest, Pint, Larastan — the same stack as [Externa](https://github.com/qiick-io/externa-core).

## Contributing

Open or pick an issue first; one issue per pull request, branches from `develop`, Conventional Commits. Details will land in `CONTRIBUTING.md`.

## License

[MIT](./LICENSE)

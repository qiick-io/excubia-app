# CLAUDE.md — Excubia App (`qiick-io/excubia-app`)

Guidance for Claude Code in this repository. Read it fully before any change.

## Project

Excubia App is the optional, open-source (MIT), self-hosted Laravel dashboard of Excubia (qiick suite). It registers Laravel projects, runs the checks of the Composer package `qiick/excubia-core` against them, stores the reports, and shows them on a multi-project dashboard. Later (Phase B) it also receives opt-in runtime monitoring from projects that install `qiick/excubia-core`, and it will be offered on qiick Cloud.

- **The checks engine lives in `qiick/excubia-core`.** Never reimplement or copy a check, parser, data-source client or renderer here. If the core lacks an API the app needs, stop and ask Luca; the change belongs in `qiick-io/excubia-core`.
- Scope is defined by the issues of the current milestone. Issues labelled `needs-decision` are not approved: do not build them.
- **Keep it small.** Luca prefers less code and fewer moving parts. No feature, package, service or abstraction that an issue does not ask for.
- Display name in the UI is "Excubia", never "qiick Excubia".

## Stack (aligned with `qiick-io/externa-core`)

- PHP 8.4, Laravel 13, official Laravel React starter kit as generated: Inertia 3, React 19, TypeScript, Tailwind CSS v4, Fortify auth, Wayfinder.
- Node 24 (`.nvmrc`), Vite+ (`vp`). SQLite by default for self-hosting; database driver via `.env`.
- Dev: Pest 5 (+ pest-plugin-laravel), Pint (laravel preset), Larastan, `vp check` (lint and format).

## Commands (after the scaffold)

```bash
composer test          # Pest
vendor/bin/pint --test # code style
vendor/bin/phpstan analyse
npm run lint && npm run types && npm run build
```

Run them before every push.

## UI (qiick app UI)

This app follows the shared qiick app UI: https://github.com/qiick-io/app-ui. **Read the app-ui [`AGENTS.md`](https://github.com/qiick-io/app-ui/blob/develop/AGENTS.md) and [`docs/`](https://github.com/qiick-io/app-ui/tree/develop/docs) before any UI change.**

- Never invent a layout, component, colour or component position. Install it from the `@qiick` registry (`npx shadcn add @qiick/<name>`); if it is not there yet, copy it from externa-core `develop`; if externa-core has no equivalent, stop and ask Luca.
- Intentional differences go in `app-ui.overrides.json` with a reason; anything else fails the drift check in CI.
- shadcn components and `lucide-react` icons only. Theme tokens only: no hex, no raw palette classes, no arbitrary font sizes. AI surfaces use the violet `ai` tokens; status colours carry status only.
- Control scale `sm` 24 / default 32 / `lg` 40 px; default everywhere.
- WCAG 2.2 AA in light, dark and high contrast.
- Brand preset: **Blue**. Do not edit surface tokens.

## Code conventions ("Codice Perfetto")

- Simplest thing that works (YAGNI). Fewest files possible. Mark deliberate shortcuts with a `ponytail:` comment naming the ceiling and the upgrade path.
- `declare(strict_types=1);` in every PHP file you write.
- Only Eloquent models use bare nouns (`Project`, `HealthReport`). Every other class ends with its role suffix: `*Controller`, `*Request`, `*Action` (one public `execute()`, for writes), `*Service` (reads, orchestration, integrations), `*Data` (readonly DTO), `*Enum`, `*Policy`, `*Job`, `*Command`, `*Resource`, `*Exception`.
- Leave Laravel scaffolding and starter-kit / `shadcn/ui` files with upstream names; exclude them in `tests/Arch/NamingArchitectureTest.php` with `->ignoring(...)`.
- Thin controllers: validate with a `*Request`, call an `*Action` / `*Service`, return Inertia. No `DB` facade in controllers. Models hold relations, casts and scopes only. Authorization in Policies.
- Full words only; booleans start with `is`, `has`, `can`, `should` (except framework contract methods and native React/DOM props such as `disabled`, `open`).
- Curly braces always; a blank line around control-flow blocks. Strict DRY.
- English PHPDoc / JSDoc on classes, components, hooks and non-trivial methods: why, array shapes, side effects, `@throws`.
- Frontend files are `kebab-case` on disk; components and types `PascalCase`; hooks and functions `camelCase`.
- No `dd`, `dump`, `var_dump`, `console.log` left behind, no `env()` outside `config/`.
- Migrations are forward-only: never edit or delete a merged migration; add a new one.
- Tests: Pest feature tests for every behavior; no real network (fake HTTP). Never delete or weaken a test.
- Privacy: no telemetry. Only package names and versions leave the server, through `qiick/excubia-core`. The AI assistant (#17) is off by default, and its tools are read-only. A tool never returns push tokens, user data or file paths (covered by a test). Tests fake the provider: no real AI call.

## Git and PR workflow

One issue → one branch → one PR. Never commit directly to `develop` or `main`.

1. Pick the next open issue of the milestone. Read its acceptance criteria.
2. `git checkout develop && git pull --ff-only`
3. `git checkout -b <type>/<issue>-<kebab-slug>` — types: feat, fix, refactor, chore, docs, test, perf, ci, build, security.
4. Conventional Commits in English (`feat(projects): add project registration`).
5. Run the quality gates, then `git push -u origin HEAD`.
6. `gh pr create --base develop --title "<conventional title>" --body "Closes #<issue> …"`
7. `gh pr merge --auto --squash` and move on. If CI goes red, fix it on the same branch.
8. `git checkout develop && git pull --ff-only && git branch -d <branch>`.

`develop` is the integration branch (PR required, no review). `main` is releases only (1 review).

## Identity and security

- Commit and push only as **LucaGiardi20** (`git config user.name` = "Luca Giardi"). Check `gh api user -q .login` returns `LucaGiardi20` before any `gh` write.
- Never use the `lucagiarditailorventures` account, never add a `Co-authored-by` trailer for it, never run `gh auth switch`.
- Never use `--admin`, `--no-verify`, force-push to shared branches, or bypass branch protection.
- Never commit `.env`, secrets, tokens, personal phone numbers or email addresses. Only `.env.example`.

## Ask Luca first

- Releases: version bumps, tags, GitHub Releases, Docker/GHCR publishing, `develop` → `main` merges. Only when Luca says "rilascia".
- Any Composer or npm dependency not named in the issue.
- Changes needed in `qiick/excubia-core`.
- Anything ambiguous, outside the issue's scope, or needing secrets, accounts, paid services or repo settings (branch protection, required checks, Actions secrets).

When blocked on one issue, comment on it with what is needed and move to the next.

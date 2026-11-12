<!-- qiick-standards:begin. Generated from qiick-io/app-ui standards: do not edit inside this block, run the sync (see qiick-standards.json). -->

# qiick standards

These rules bind every agent and contributor in this repo from the first task. They are the same in every qiick repo (source: https://github.com/qiick-io/app-ui/tree/develop/standards), and CI fails when this block differs from the source. Rules for this repo only live outside this block; where they are more specific, they win. Starting a new qiick product: https://github.com/qiick-io/app-ui/blob/develop/docs/new-product.md.

## Stack

Every qiick app runs the same stack, so code, rules and registry items move between apps unchanged. A new app starts here; an existing app that differs converges on it, one PR per change. Steps from an empty repo to a green CI: https://github.com/qiick-io/app-ui/blob/develop/docs/new-product.md

### Runtime defaults

- **Database:** SQLite by default (install, tests, CI); MySQL 8 and PostgreSQL 17 supported and tested in CI. No vendor-specific SQL outside a driver check.
- **Queue:** the `database` driver by default; **Horizon** (`laravel/horizon`) when Redis is configured. Slow work (mail, AI, imports, webhooks) is a queued `*Job`.
- **Realtime:** **Reverb** (`laravel/reverb`) with `laravel-echo` + `pusher-js`; broadcasting is optional and the UI works without it (realtime badge grey).
- **Cache / session:** `database` by default, Redis when configured.
- **Locales:** `en`, `it`, `de` from the first commit: every UI string through `t('…')`, every key in all three locale files.
- **Self-hosting:** an app is installed with `composer create-project` and `php artisan <app>:install`, updated with `<app>:upgrade` (see the distribution rules).

### Frontend toolchain

**Vite+** (`vite-plus`: `vp dev`, `vp build`, `vp check` with Oxlint and Oxfmt), as the Laravel React starter kit ships it. excubia-app is the reference and sets the lint rules: an app that differs converges on it, never the other way round, and no app moves from Vite+ back to plain Vite, ESLint or Prettier. externa-core still runs ESLint + Prettier and moves to Vite+ in 1.5.0 (externa-core#609).

### Local development

**Laravel Herd** serves the app at `https://<app>.test` (`herd link`, `herd secure`); `composer run dev` starts Vite, the queue worker and Pail. No Sail or Docker for day-to-day work: Docker is the distribution image, not the dev environment.

- **One database per app**, named after it (`externa_core`, `excubia_app`). Local data is disposable: for a clean state, empty and re-seed it (`php artisan migrate:fresh --seed`); never create per-version or per-branch copies. Before dropping a database, check which `.env` files use it. Production databases only when the maintainer says so.
- **Clean up after a task.** Worktrees, clones, Herd sites or workspaces and extra databases are fine while a task needs them; when its PR is merged or the work is dropped, remove them (`git worktree remove`, `herd unlink`, drop the database) after checking for unpushed work.

**Details:** `.qiick/standards/stack.md`. Read it before bootstrapping an app, or adding, removing or upgrading a dependency, the lint / format setup or the quality-gate scripts.

## Code standard

Every file follows classic craftsmanship, including files nobody reads line by line: self-explanatory names, English documentation of _why_ and of domain invariants, clean structure with breathing room, and no duplication.

### Simplest thing that works

Stop at the first rung that holds: YAGNI, standard library, platform, installed dependency, one line, then the minimum code.

- No unrequested abstractions, dependencies or boilerplate. A new Composer or npm dependency needs the maintainer's OK.
- Deletion over addition. Boring over clever. Fewest files possible.
- Mark an intentional shortcut with a `ponytail:` comment that names the ceiling and the upgrade path.
- Never lazy about trust-boundary validation, data-loss prevention, security, accessibility, or anything explicitly requested.
- Non-trivial logic leaves one runnable check behind (a test).

### Current sources, not memory

Before writing framework or library code, or copying a component from an upstream registry (shadcn, AI Elements), read the current official page for the installed version. Training data and old copies go stale: until 2026-11-09 the registry's AI Elements were a superseded release, and nobody had compared them with the current pages. A file copied from upstream names its source page in its header comment, and the registry README records the source and date of every upstream item.

### Names: full words

- No single letters (`x`, `e`, `q`) and no abbreviations (`cfg`, `res`, `req`, `val`, `arr`, `tmp`, `idx`, `usr`, `ctx`, `def`, `cb`). Full words even at 30+ characters (`$collectionFieldDefinition`, `handleFormSubmissionError`).
- `i`, `j`, `k` only as indices of tight numeric loops; prefer `foreach ($collectionItems as $collectionItem)`, `for...of`, `.map()`.
- **Booleans** (variables, columns, custom props, boolean-returning methods) start with `is`, `has`, `can` or `should` (`$isEmailVerified`, `canManageCollection()`, `shouldBroadcastImmediately`). Exempt: names fixed by a framework or library contract (see [Contracts](#contracts-never-break-them)).
- **Collections** use specific plurals (`$publishedArticles`) or explicit maps (`$usersIndexedById`), never `$data`, `$list`, `$arr`.
- Every class of our own ends with its role suffix (`*Controller`, `*Action`, `*Service`, `*Data`, `*Enum`, `*Job`, …); only Eloquent models use bare nouns. The full table is in the details.
- **Never rename framework or package scaffolding:** files native to Laravel or published by a package keep their upstream names: Fortify actions (`app/Actions/Fortify/*`), starter-kit files (`HandleInertiaRequests`, `HandleAppearance`, `app/Concerns/*`), default service providers, vendor-published files (Spatie, Horizon, Telescope, Pennant, Passport), and frontend primitives (`shadcn/ui` files in `components/ui/`). They are listed in the repo's `tests/Arch/naming-architecture.php`, never renamed.

### Architecture: five layers

1. **Thin controllers.** At most the 5 resource methods or one `__invoke`, about 10 to 15 lines each, doing three things: receive a typed `*Request`, call an `*Action` / `*Service` with a `*Data` DTO, return a `*Resource`, JSON or an Inertia page. No business logic, no `DB` facade.
2. **Domain layer.** Writes in `app/Actions/*Action.php`, orchestration and reads in `app/Services/*Service.php`, typed `readonly` DTOs in `app/Data/*Data.php` instead of `array<string, mixed>` between controllers, actions, services and jobs.
3. **Clean models.** Relationships, `casts()`, accessors / mutators, scopes (or an `app/Builders/*Builder.php`), table constants. Never HTTP calls, disk or S3 work, or multi-step orchestration in a model.
4. **Authorization in Policies**, called through `Gate::authorize()` or a FormRequest `authorize()`, not scattered checks.
5. **One domain for every surface.** The Inertia UI, the REST / GraphQL API, MCP / AI tools and the CLI call the same `*Action` and `*Service` classes; domain logic is never duplicated per surface.

The Pest architecture test (`tests/Arch/NamingArchitectureTest.php`) enforces the suffixes, readonly DTOs, `declare(strict_types=1)`, no debugging helpers, no `env()` outside `config/`, no `DB` facade in controllers, and at most five public methods per controller (existing debt ratcheted in `naming-architecture.php`).

### Formatting

- Curly braces on every `if`, `else`, `for`, `foreach`, `while`, `do`. No single-line braceless control statements.
- A blank line before and after control-flow blocks (`if`, `return`, `for`, `foreach`, `while`, `switch`, `try`, `throw`); ESLint `@stylistic/padding-line-between-statements` and Pint enforce it where configured.
- `declare(strict_types=1);` in every PHP file you write or touch.
- No `dd`, `dump`, `ray`, `var_dump`, `console.log` left behind. No `env()` outside `config/`.
- Strict DRY: extract shared validation rules, scopes, DTO shapes and UI primitives instead of copying blocks.

### Documentation (English PHPDoc / JSDoc)

This replaces the "comment only exceptionally complex logic" default of Laravel Boost and similar guidelines.

- **Class, component and hook docblock** (mandatory): the unit's responsibility and why it exists.
- **Method and function docblock** for what the signature cannot say:
    1. Array shapes and generics for PHPStan / Larastan (`@param array{title: string, slug: string} $validatedPayload`, `@return Collection<int, CollectionItem>`).
    2. Side effects: queued jobs, dispatched events, filesystem / S3 writes, external HTTP calls.
    3. Business invariants, why a guard exists, `@throws` conditions.
- No noise that restates the code (`// set loading to true`).

### Contracts: never break them

1. **Migrations are forward-only.** Never edit, rename or delete a migration in `database/migrations/` once it is merged into a released version: existing self-hosted installs would no longer upgrade. A schema or stored-data change is a new migration with a safe backfill. The reusable `migrations-frozen.yml` fails CI when a migration on `main`, on the latest tag or in `database/migrations/.frozen` changes. A repo that has not released yet may state a pre-release exception in its own section.
2. **Framework method contracts keep their names** (no `is` / `has` prefix forced on them): `FormRequest::authorize()`, Policy methods (`viewAny`, `view`, `create`, `update`, `delete`, `restore`, `forceDelete`), `Rule::passes()` / `ValidationRule::validate()`, `broadcastWhen()`, `shouldQueue()`.
3. **Native and library props keep their names:** `disabled`, `required`, `checked`, `open`, `defaultOpen`, `readOnly`, `asChild`, `modal`, `preserveScroll`, `preserveState`, `replace` (DOM, React, Radix / shadcn, Inertia). The prefix rule applies only to our own props and state.
4. **Wire formats stay stable** when a PHP class is renamed: REST JSON keys, GraphQL type and field names, webhook payloads, `broadcastAs()` names, Inertia page paths, named routes, morph maps (`Relation::enforceMorphMap`), queued job class names. Enum backing values stay identical.
5. **Convention-driven directories** (`app/GraphQL/`, `app/Ai/`, MCP tools) keep the names their resolver expects unless the resolver mapping changes in the same PR.
6. **Mechanical renames and behaviour changes never share a PR.** Land the `git mv` + import updates first with green tests, then extract or change logic in a separate PR.

**Details:** `.qiick/standards/code.md`. Read it before creating or renaming a file, class, enum, job, command or other PHP / TypeScript unit.

## Backend conventions

How the five layers look in a qiick app. Each topic has a page with the externa-core (or excubia-app) files to copy; read it before touching that area.

| Topic                                                  | Page                                                                          |
| ------------------------------------------------------ | ----------------------------------------------------------------------------- |
| Auth pages, 2FA, passkeys, SSO through Signaculum      | https://github.com/qiick-io/app-ui/blob/develop/docs/auth.md                  |
| Roles, permissions, policies, `<app>:sync-permissions` | https://github.com/qiick-io/app-ui/blob/develop/docs/roles-and-permissions.md |
| Error pages 403 / 404 / 500 / 503                      | https://github.com/qiick-io/app-ui/blob/develop/docs/error-pages.md           |
| REST API, OpenAPI, API keys and scopes                 | https://github.com/qiick-io/app-ui/blob/develop/docs/api.md                   |
| Realtime: Reverb, channels, presence                   | https://github.com/qiick-io/app-ui/blob/develop/docs/realtime.md              |
| Security headers                                       | https://github.com/qiick-io/app-ui/blob/develop/docs/security-headers.md      |
| Logging and activity (audit) log                       | https://github.com/qiick-io/app-ui/blob/develop/docs/logging-and-audit.md     |
| AI backend: providers, tools, approvals                | https://github.com/qiick-io/app-ui/blob/develop/docs/ai-backend.md            |

### Requests and controllers

- Every write takes a `FormRequest` in `app/Http/Requests/<Area>/`: `Store<Model>Request`, `Update<Model>Request`, `Bulk<Model>ActionRequest`; shared rules live in `app/Http/Requests/Concerns/*Trait.php` (`ValidatesListPerPageTrait`, `ValidatesSearchQueryTrait`). `authorize()` checks the permission (or the policy); `rules()` validates the whole payload at the trust boundary.
- A controller method receives the request, builds a `*Data` DTO from `validated()`, calls one `*Action` (write) or `*Service` (read), and returns an Inertia page, a redirect with a flash message, or a `*Resource`. Index pages paginate on the server (`?page`, `?per_page`, `?sort`, `?search`).
- Wayfinder generates the frontend route helpers (`@/routes/...`, `@/actions/App/Http/Controllers/...`): never hard-code a URL in TypeScript.

### Permissions and policies

- Permissions are a string-backed `app/Enums/PermissionEnum.php` (`CanShowUsers = 'can-show-users'`, `can-<verb>-<resource>`), roles a `RoleEnum` (`super-admin`, `admin`, …), stored with `spatie/laravel-permission`.
- `php artisan <app>:sync-permissions` creates the missing permissions from the enum, grants new ones to `admin`, clears the permission cache and writes `resources/js/enums/permission-enum.ts`. `<app>:install` and `<app>:upgrade` run it; never seed permissions by hand.
- Route groups are gated with the `permission:<value>` middleware; record-level decisions (ownership, state) live in `app/Policies/*Policy.php`, called through `Gate::authorize()` or `authorize()`. `Gate::before` grants `super-admin` everything.
- The UI receives `auth.permissions` as a shared Inertia prop and hides what the user cannot do (`useCan()`); the server check is the one that counts.

**Details:** `.qiick/standards/backend.md`. Read it before adding or changing an API, security headers or rate limits, activity logging, broadcasting, AI tools, health endpoints or Artisan commands.

## Testing

- Pest for PHP (`*Test.php`), Vitest or `node:test` for TypeScript / scripts. Test behaviour, not private structure.
- Every behaviour change adds or updates a test that fails without the change. Pure copy, styling or layout changes need none.
- Never delete, skip or weaken an existing test to get green; ask the maintainer first.
- No real network, AI provider or external process in tests: `Http::fake()`, `Process::fake()`, recorded fixtures, fake providers.
- Use model factories (and their states) for test data.
- UI flows that only a browser proves (navigation, dialogs, keyboard, focus) get a Pest browser test (`tests/Browser`, `pestphp/pest-plugin-browser`) where the repo has the plugin; check light and dark.
- `tests/Arch/NamingArchitectureTest.php` stays green. A new class satisfies its role suffix; scaffolding is listed in `tests/Arch/naming-architecture.php`, never renamed.
- Run the narrowest tests while iterating (`--filter`, a file path), and the repo's full quality gate (listed in its own section) before every push.

## Git, commits and pull requests

- **English everywhere in the repo:** code, comments, docs, commit messages, issues, pull requests.
- **Conventional Commits:** `type(scope): summary` in the imperative, lower case, no trailing period. Types: `feat`, `fix`, `refactor`, `perf`, `docs`, `test`, `chore`, `ci`, `build`, `security`. `!` or a `BREAKING CHANGE:` footer for breaking changes. commitlint checks PR titles in CI through the reusable `qiick-io/app-ui/.github/workflows/pr-title.yml` (check `pr-title / commitlint`), which every repo calls on `pull_request` with the `edited` type.
- **One issue, one branch, one pull request.** Branch from `develop` as `<type>/<issue>-<kebab-slug>` (`feat/42-project-registration`). Never commit directly to `develop` or `main`.
- Pull requests target `develop` and are **squash-merged**, so the PR title becomes the commit: make it a valid Conventional Commit. The body says what changed, why, and `Closes #<issue>`.
- **Every issue and pull request gets its metadata when it is created:** assignee (the maintainer), labels from the repo's set, the milestone of the release it belongs to, and the repo's GitHub project when it has one.
- **Branch protection** (every public repo): `develop` and `main` change only through a pull request with green required checks; admins are not exempt. When a PR is behind its base, update the branch (`gh pr update-branch`) and wait for the checks again. `main` takes **merge commits** only (a ruleset): release PRs, and the merge of `main` back into `develop` after a tag, which is a pull request too.
- Never force-push a shared branch, never `--no-verify`, never `--admin` or any branch-protection bypass.
- No attribution or `Co-authored-by` trailers for tools or agents.
- Never commit `.env`, secrets, tokens, personal phone numbers or email addresses; only `.env.example`.
- Nothing personal in tracked files: no absolute local paths (`/Users/…`), no one's own editor or agent configuration (keep it in `.gitignore`), and "the maintainer" instead of people's names in code, comments and docs. Describe what code does rather than which product it imitates; naming a product is fine where it is an integration (an importer, a compatible endpoint).
- Ask the maintainer before: a new dependency, anything outside the issue's scope, repo settings (branch protection, required checks, secrets), paid services or accounts.

## Releases: never without the maintainer

`develop` is the integration branch. `main` holds releases only.

Agents may, when asked for a release:

- open a `develop` → `main` release pull request with a full body (changelog, included PRs), then **stop**.

Agents must not, unless the maintainer explicitly says so in this conversation:

- merge a release pull request or anything into `main` / `release/*`;
- bump versions, create tags (`vX.Y.Z` or any other), run `gh release create` or publish a GitHub Release;
- push to `main` / `master` / `release/*`;
- publish packages (Packagist, npm) or images (Docker Hub, GHCR);
- change repository visibility.

How a release goes, for reference: release PR → the maintainer merges it with a **merge commit** (never squash: `main` must stay an ancestor of `develop`) → tag `vX.Y.Z` on the merge commit → GitHub Release titled exactly with the tag, listing the included PRs → `main` is merged back into `develop` through a pull request, also with a merge commit.

**Details:** `.qiick/standards/release.md`. Read it before preparing a release PR or the CHANGELOG, bumping a version, or asking for required checks.

## Automated checks

Machines enforce these in CI; keep them green, and never silence one to get green. Fix the new finding; a baseline only shrinks.

**Details:** `.qiick/standards/checks.md`. Read it before fixing a failing check, or touching a baseline, `knip.json`, a ratchet or the strict-model setup.

## Self-hosted app distribution

For apps a customer installs (not for Composer packages, which ship with plain `composer require` / `update`). externa-core is the reference implementation. In short: install with `composer create-project qiick/<app>` and `php artisan <app>:install`, update with `php artisan <app>:upgrade` (never `create-project`).

**Details:** `.qiick/standards/distribution.md`. Read it before changing install, upgrade, the Docker image, the release workflow, the docs pages or Packagist metadata.

## UI (qiick app UI)

No agent invents a layout, a component, a colour or a component position in a qiick app. The only exceptions are explicit overrides (rule 2).

### Before any UI change

Patterns come in two tiers. **Core** patterns bind every qiick app. **Optional** patterns are accepted solutions that bind only the apps that adopt them, by listing them in `qiick-standards.json` `patterns`. Optional patterns adopted in this repo: [command-palette](https://github.com/qiick-io/app-ui/blob/develop/docs/optional/command-palette.md), [grouped-tabs (sub-menu)](https://github.com/qiick-io/app-ui/blob/develop/docs/optional/grouped-tabs.md), [inbox-list (side-panel)](https://github.com/qiick-io/app-ui/blob/develop/docs/optional/inbox-list.md), [keyboard-shortcuts](https://github.com/qiick-io/app-ui/blob/develop/docs/optional/keyboard-shortcuts.md), [sidebar-entities](https://github.com/qiick-io/app-ui/blob/develop/docs/optional/sidebar-entities.md), [table-cell-details](https://github.com/qiick-io/app-ui/blob/develop/docs/optional/table-cell-details.md), [table-density](https://github.com/qiick-io/app-ui/blob/develop/docs/optional/table-density.md).

1. Find the pattern in the tables in the details and read its page: each one names the registry items, and the reference app files where they are not in the registry yet.
2. Install it from the `@qiick` registry with `npx shadcn add @qiick/<name>`; do not rewrite it. The registry list: https://github.com/qiick-io/app-ui/blob/develop/registry/README.md
3. If the registry does not have it yet, copy it from the reference app the page names, file by file.
4. When a screen needs what an optional pattern solves and this app has not adopted it, ask the maintainer to adopt it (add it to `patterns`). Do not build a variant of your own.
5. If no core or optional pattern fits, **stop and ask the maintainer**. A new solution is proposed in app-ui as an optional pattern, never designed inside one app.

### Hard rules

1. **Source of truth.** The `@qiick` registry; until an item ships there, the reference app its pattern page names. Copy, never redesign.
2. **Overrides.** An intentional difference from the registry is listed in the app's `app-ui.overrides.json` with a reason per file. Any other difference fails the drift check in CI.
3. **Primitives.** shadcn components only (the registry `ui` items). Icons: `lucide-react` only. No Headless UI or other component libraries.
4. **Colours.** Theme tokens only (`bg-primary`, `text-muted-foreground`, `border-sidebar-border`, …). No hex and no raw palette classes (`bg-teal-600`, `text-neutral-500`) in `className`; no arbitrary font sizes (`text-[11px]`).
    - AI surfaces use the violet `ai` token set (`bg-ai`, `text-ai-text`, `border-ai-border`, …), never the brand primary.
    - Status colours (`success`, `warning`, `destructive`, `info`) carry status only; never use one as a brand or primary colour.
5. **Control scale.** `sm` 24px, default 32px, `lg` 40px. The default (32px) everywhere: headers, toolbars, forms, settings. `sm` only for compact inline actions (table row icons, chips); `lg` for rare large CTAs.
6. **Accessibility.** WCAG 2.2 AA in light, dark and high contrast: visible focus, keyboard reachability, labels / `aria-label` on icon-only controls, contrast. Check every UI change in light and dark.
7. **Positions are part of the pattern.** Where a control sits (primary action far right, filters left, pagination counter left / pages centre / rows per page right, …) is copied like the code.
8. **Presence.** shadcn `AvatarBadge` inside the `Avatar`, bottom-right. Users: green online, solid grey offline (never transparent). The realtime badge on the signed-in user: green connected, amber connecting, grey disabled, red only when the connection is lost. Member lists show avatars without presence, then `AvatarGroupCount` "+N".

### Theme

Tokens come from the `@qiick/theme` registry item: one shared base (surfaces, the two-tone shell, text, borders) plus brand-only presets (Teal = Externa, Blue = Excubia). An app picks its preset and never edits surface tokens. This repo's preset: **Blue**.

**Details:** `.qiick/standards/ui.md`. Read it before making any UI change (it has the table of pattern pages).

<!-- qiick-standards:end -->

# Repository rules: Excubia App (`qiick-io/excubia-app`)

Rules for this repo only, on top of the qiick standards above. Where they are more specific, they win.

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

## Quality gate

```bash
composer test          # Pest
vendor/bin/pint --test # code style
vendor/bin/phpstan analyse
npm run lint && npm run types && npm run build
npm run knip && npm run lint:i18n   # dead code, hard-coded UI strings (ratchet)
npm run lint:standards   # qiick ratcheted rules in Oxlint: restricted imports, navigator.clipboard, id-length (#258)
node scripts/qiick-ratchet.mjs phpstan   # Larastan baseline only shrinks
```

Run them before every push.

## Excubia App rules

- Never reimplement or copy a check, parser, data-source client or renderer: they live in `qiick/excubia-core`.
- Migrations are frozen from `1.0.0-beta.1` on (#193): every schema change is a **new forward-only migration**; never edit, rename or delete an existing one, not even before the tag is pushed. The pre-release rule of editing the `create_*` migrations in place has ended. A new migration must apply with `php artisan migrate --force` on a customer database with data (backfill what it needs, no `migrate:fresh`), on SQLite, MySQL and PostgreSQL. The `Migrations frozen` CI check fails when a PR touches a migration that exists on `main`, on the latest `v*` tag or in the freeze baseline `database/migrations/.frozen` (that list only grows; `scripts/migrations-frozen.sh`).
- Tests: no real network and no real CLI run: fake HTTP and `Process`.
- Privacy: no telemetry. Only package names and versions leave the server, through `qiick/excubia-core`. The AI assistant (#17) is off by default, and its tools are read-only. A tool never returns push tokens, user data or file paths (covered by a test). Tests fake the provider: no real AI call.
- Extra role suffixes (`App\Ai\Agents` → `Agent`, `App\Ai\Tools` → `Tool`) and the scaffolding the architecture test skips: `tests/Arch/naming-architecture.php`.

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

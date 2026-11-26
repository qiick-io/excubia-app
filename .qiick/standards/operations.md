<!-- Generated from qiick-io/app-ui standards: do not edit, run the sync. The agent files hold the core of this page and point here. -->

# Security, data and operations

What every qiick repo does so a vulnerability can be reported, a leaked secret replaced, a schema changed without downtime, the data restored, a release and its dependencies trusted, a running product watched, personal data accounted for, and an incident handled from a page instead of from memory (fleet conventions F19–F24, F26, F29 and F30, maintainer request of 2026-11-25; F25, F27 and F28 are code conventions, in the conventions rules).

- **F19 Security policy:** every repo has a `SECURITY.md` (supported versions, how to report, response time) and GitHub private vulnerability reporting switched on, because the policy sends reporters there.
- **F20 Secrets runbook:** every product has `docs/runbooks/secrets.md`: each secret of `.env.example` and each key file, where it lives, who rotates it, how, what the rotation breaks, and what to do after a leak.
- **F21 Expand / contract:** after the first stable release a schema change never breaks the code of the release before it. Add first (expand), move the data, switch the code, and drop or rename only in a later release (contract), with a `contract:` comment naming the release that stopped using the column.
- **F22 Backup and restore drill:** every product documents what is backed up and how to restore it in `docs/runbooks/backup.md`, and records a restore drill there before its first stable release and at least every quarter after it.
- **F23 Signed release tags:** the maintainer creates release tags locally with `git tag -s`; the release workflow runs `signed-tag / verified`, which fails unless GitHub shows the tag as Verified, and every publishing job needs it.
- **F24 Supply chain:** lock files committed in products; `composer audit` and `npm audit --omit=dev --audit-level=high` on every PR and every night; Dependabot for Composer, npm and GitHub Actions; every accepted advisory written down with a reason and a date; release artefacts (Docker images, archives) carry a build provenance attestation.
- **F26 Observability with Excubia:** every product requires `qiick/excubia-core` (not `--dev`), keeps an `excubia.json`, schedules `excubia:check` daily, and serves `/health/live` and `/health/ready`; pushing to an `excubia-app` is the operator's choice, configured only through `EXCUBIA_APP_URL` and `EXCUBIA_APP_TOKEN`.
- **F29 Privacy:** every product keeps `docs/privacy.md`: the personal data it stores (table and column), why, for how long, which processors and outbound destinations receive any of it, and how a person's data is exported and erased. No telemetry, ever, unless the operator turns it on.
- **F30 Runbooks:** every product keeps `docs/runbooks/deploy.md` (deploy, upgrade and rollback) and `docs/runbooks/incident.md` (first steps, who to tell, what to write down afterwards), next to the secrets and backup runbooks.

With a `conventions` object in `qiick-standards.json`, the standards drift check runs the static checks of F19, F20, F22–F24, F26, F29 and F30; `migrations-frozen / migrations-frozen` checks F21; `scripts/check-repo-settings.mjs` in app-ui checks that private vulnerability reporting is on. An exception is a `conventions.divergences` entry like any other convention.

## F19 Security policy and private reporting

**Rule.** Every repo has `SECURITY.md` at its root (or in `.github/`) with three sections: **Supported versions** (which versions get security fixes), **Reporting a vulnerability** (GitHub private vulnerability reporting on the repo's Security tab, never a public issue; acknowledgement within 72 hours) and what the repo treats as critical. "Private vulnerability reporting" is on in the repo's settings. A fix ships as a patch release with a `Security` entry in the CHANGELOG and a GitHub security advisory; a finding that spans packages is coordinated in each repo's advisory. A repo without one starts from `standards/templates/SECURITY.md`.

**Why.** On 2026-11-25, 16 of the 19 repos had a `SECURITY.md` and almost all of them told reporters to use private vulnerability reporting, but it was switched on in three (excubia-app, externa-core, excubia-core): a reporter who followed the policy found no button and had only a public issue left. app-ui, externa-bruno and two starters had no policy at all.

**Checked by.** F19 in the conventions check: `SECURITY.md` exists and has the supported versions and reporting sections. `scripts/check-repo-settings.mjs`: private vulnerability reporting is enabled (`gh api repos/<repo>/private-vulnerability-reporting`).

**Exceptions.** A private repo (foruli-core) cannot receive outside reports; its policy names the maintainer as the only channel, and the setting check skips it.

## F20 Secrets runbook

**Rule.** Every product keeps `docs/runbooks/secrets.md` with one row per secret: every variable of `.env.example` whose name ends in `KEY`, `SECRET`, `TOKEN`, `PASSWORD` or `DSN` (optionally followed by `_ID`), and every key file (`storage/oauth-*.key`, signing keys in the database). Each row says where the value lives in production (never in git), what it protects, how to rotate it (the command, as `signaculum:rotate-signing-key`), what the rotation breaks (sessions, issued tokens, encrypted columns) and how often it is rotated. A last section, **After a leak**, gives the order: revoke or rotate, invalidate what the secret signed or encrypted, read the audit log for its use, write the incident down. `APP_KEY` is never rotated by replacing it: Laravel's `APP_PREVIOUS_KEYS` keeps old ciphertext readable while the new key takes over.

**Why.** On 2026-11-25 only signaculum-core (`docs/key-rotation-and-backup.md`) and laravel-http-basics (one secret, in its `SECURITY.md`) wrote down how to rotate a secret. The other products had rotation code (`RotateApiKeyAction`, `RotateProjectTokenAction`) but no page that lists their secrets, so after a leak the operator has to find them in the code under pressure, and a forgotten one stays valid.

**Checked by.** F20 (_app_): `docs/runbooks/secrets.md` exists, names every secret-like variable of `.env.example` and has an **After a leak** section.

**Exceptions.** A variable that only looks like a secret (`PUSHER_APP_KEY`, a public client id) is still listed, with "public, not a secret" as what it protects; the list stays complete and the check stays simple.

## F21 Expand / contract schema changes

**Rule.** Until the first stable release, schema changes go into the `create_` migrations (the migration contract in the code rules). After it, a release runs its migrations while the previous release's code may still serve requests (rolling deploys, queue workers that finish their jobs, a rollback), so no migration may break that code:

1. **Expand** (release N): add the new column or table, nullable or with a default; write both the old and the new place; backfill in chunks (a queued job or a `<app>:` command, not one `UPDATE` in the migration).
2. **Switch** (release N or N+1): read only the new place; stop writing the old one.
3. **Contract** (release N+1 or later, never the release that switched): drop or rename the old column or table. The migration's `up()` carries a comment `// contract: <column or table> unused since vX.Y.Z`, naming the released tag whose code no longer reads or writes it.

A type change (`->change()`) that only widens (a longer string, a bigger integer, nullable) breaks nothing and carries `// expand-safe: <why>` instead. A new `NOT NULL` column without a default is an expand step done wrong: add it nullable, backfill, then make it `NOT NULL` in a later release. Packages follow the same rule, because a product upgrades the package before it deploys its own code.

**Why.** The migration contract (decision of 2026-11-20) freezes released migrations, so a mistake can no longer be edited away after 1.0; and every qiick product is self-hosted with an upgrade command, so the window in which old code meets the new schema is the operator's whole deploy, not a second. A `dropColumn` in the same release as the code change turns that window into errors, and a rollback to the previous release into a broken app.

**Checked by.** `migrations-frozen / migrations-frozen` (`standards/scripts/migrations-frozen.sh`), once the repo has a stable tag: every migration added since the latest stable tag whose `up()` drops, renames or changes a column or table needs the `contract:` comment with a tag that exists and is not newer than the latest stable tag, or the `expand-safe:` comment (`standards/scripts/check-expand-contract.mjs`). Packages call the same workflow.

**Exceptions.** A table no released code ever used (created and dropped between two releases) needs no contract step; its migration carries `// contract: never released`. A deliberate breaking schema change in a new major version is a divergence with its upgrade notes.

## F22 Backup and restore drill

**Rule.** Every product keeps `docs/runbooks/backup.md` (written from `standards/templates/runbook-backup.md` when the repo adopts the conventions) with:

- **What is backed up:** the database, the storage disks with user files, the `.env` (with `APP_KEY`) and every key file, each with where the copy goes (off the application host), how often, how long it is kept, and how it is encrypted. A backup without `APP_KEY` cannot decrypt encrypted columns; it is kept apart from the database copy, never next to it.
- **Restore:** the steps in order, ending with the app's health check (`GET /health/ready`) and the commands that must not run after a restore (a key rotation that would retire the restored key).
- **Restore drills:** a table with one row per drill (`| Date | Version | Result | Time to restore |`): a real restore of a real backup into an empty environment, following the page, with what went wrong fixed on the page in the same PR.

The first drill is recorded before the first stable release; after it, at least one drill every 92 days.

**Why.** On 2026-11-25 signaculum-core and instituta-core documented their backups, and no product had ever recorded a restore. A backup that has never been restored is a hope: missing `APP_KEY`, a disk nobody listed, or a dump from the wrong database are found during the first restore, and the first restore should not be the incident.

**Checked by.** F22 (_app_): `docs/runbooks/backup.md` exists with the three sections; once the CHANGELOG has a stable release, the newest drill is at most 92 days old.

**Exceptions.** None. A product without a production installation still records the drill against its demo or test data before 1.0.

## F23 Signed release tags

**Rule.** The maintainer creates every release tag on their own machine, signed: `git tag -s vX.Y.Z -m vX.Y.Z <merge commit>` with a GPG or SSH key registered on GitHub as a signing key (`git config tag.gpgSign true` makes every annotated tag signed), then pushes it. A tag created in the GitHub UI, by `gh release create` on a missing tag, or by an agent is not signed and fails. Every repo that tags releases has a workflow on `push: tags: ['v*']` that calls the reusable `signed-tag.yml` with the job id `signed-tag`; the check `signed-tag / verified` fails unless the tag is an annotated tag that GitHub shows as **Verified**. Every job that publishes from the tag (GitHub Release, Docker image, package) `needs: signed-tag`.

```yaml
on:
    push:
        tags: ['v*']

jobs:
    signed-tag:
        uses: qiick-io/app-ui/.github/workflows/signed-tag.yml@<sha> # vX.Y.Z
    docker-image:
        needs: signed-tag
        # …
```

**Why.** On 2026-11-25 every release tag of the fleet was a lightweight tag: it carries no signature, so nothing proves that the maintainer, and not a leaked token or a compromised account, chose that commit for the release. The `release tags: immutable` ruleset stops a tag from moving after it is pushed; the signature proves who pushed it. Composer and Packagist install by tag, so the tag is the release.

**Checked by.** `signed-tag / verified` on every tag push (the reusable workflow reads `gh api repos/<repo>/git/tags/<sha>` and its `verification`). F23 in the conventions check: the repo's workflows call `signed-tag.yml`.

**Exceptions.** Tags pushed before 2026-11-25 stay as they are (immutable); the rule starts with the next release of each repo.

## F24 Supply chain

**Rule.**

- **Lock files:** a product commits `composer.lock` and its npm lock file and installs from them in CI (`composer install`, `npm ci`). A package commits neither, because the product's lock decides; its CI tests both ends (`lowest` and `stable`, the package matrix).
- **Audits:** every PHP repo runs `composer audit` and every repo with a `package.json` runs `npm audit --omit=dev --audit-level=high` on every pull request and on the nightly run, failing on an advisory. An advisory that does not apply (the vulnerable function is never called, no fix exists yet) is accepted in `composer.json` `config.audit.ignore` with its reason, or for npm in `docs/dependencies.md`, with the date to look again; an acceptance without a reason is a finding.
- **Dependabot:** `.github/dependabot.yml` covers `composer`, `npm` (where there is a `package.json`) and `github-actions`, weekly, with minor and patch updates grouped in one PR per ecosystem. Security updates are on in the repo settings.
- **New dependencies** need the maintainer's OK and a row in the dependency register (the dependency rules); actions are pinned by SHA (F15).
- **Provenance:** a workflow that publishes a Docker image or a release archive attests it with `actions/attest-build-provenance`, so an operator can check with `gh attestation verify` that the artefact was built by the repo's workflow from the signed tag (F23).

**Why.** On 2026-11-25 `composer audit` ran in six of the eight packages and in one product (externa-core); `npm audit` only in externa-core, although every product ships a frontend; Dependabot was configured in eight of the nineteen repos; no workflow attested what it built. The products are what operators install, so the products are where an unaudited dependency reaches a server.

**Checked by.** F24 in the conventions check: a PHP repo's workflows run `composer audit`, a repo with `package.json` runs `npm audit` (or `pnpm audit`), `.github/dependabot.yml` lists `github-actions` and the repo's package ecosystems, a product has `composer.lock`, and a workflow that pushes an image (`docker/build-push-action` with `push: true`) also runs `attest-build-provenance`.

**Exceptions.** A repo without a release artefact (packages: Packagist builds from the tag) needs no attestation.

## F26 Observability with Excubia

**Rule.** Every product watches itself with Excubia, the fleet's own health check:

- `qiick/excubia-core` in `require` (not `require-dev`), with an `excubia.json` at the repo root that says which checks apply and leaves `app`, `runtime` and `exceptions` off: the operator turns them on.
- `excubia:check` is scheduled daily with `onOneServer()`, so the stored result is fresh for the health endpoint and the upgrade command.
- `GET /health/live` and `GET /health/ready` (the backend rules) answer outside auth; the Excubia health endpoint (`excubia/health`, Oh Dear format) is optional and needs `EXCUBIA_HEALTH_SECRET`.
- An operator who runs an `excubia-app` sets `EXCUBIA_APP_URL` and `EXCUBIA_APP_TOKEN` in the environment (never in `excubia.json`: the token is a secret, F20) and turns on `runtime` and `exceptions`; exceptions are masked by Excubia before they leave.
- Logs follow F10 (structured, same keys, no personal data), so a pushed exception carries `request_id` and nothing personal.

**Why.** On 2026-11-25 only excubia-app required `qiick/excubia-core`; the other products had health routes but nothing that checks their own posture (debug mode, caches, failed jobs, end-of-life PHP, vulnerable lock files) after install. The fleet already has the tool; each product finding out on its own that its scheduler stopped is the gap.

**Checked by.** F26 (_app_): `composer.json` requires `qiick/excubia-core`, `excubia.json` exists and has no `app.token`, the schedule runs `excubia:check` (or `excubia:push`), and `routes/health.php` (or the routes) serve `health/live` and `health/ready`.

**Exceptions.** excubia-app is Excubia's own dashboard: it requires the package like any product, and its `app.url` may point at itself.

## F29 Privacy

**Rule.** Every product keeps `docs/privacy.md` (from `standards/templates/privacy.md`) with:

- **Personal data:** a table of every table and column that holds personal data (name, email, IP address, user agent, free text a person typed, files they uploaded), with the purpose and the retention (F9: the prune command, or "until the account is erased").
- **Processors and outbound destinations:** every service that receives any of it (mail provider, storage, an AI provider, an OIDC provider, a webhook target the operator configures), and what it receives.
- **Export and erasure:** how a person's data is exported (format, command or screen) and erased, and what is kept after erasure and why (the activity log, anonymised).
- **Telemetry:** none by default. A feature that sends anything off the server is off until the operator turns it on, and this page names it.

A PR that adds a column or an outbound call with personal data updates the page in the same PR.

**Why.** On 2026-11-25 no product had a privacy page, although every one stores emails, IP addresses (the activity log adds `ip` and `user_agent` to every entry, the backend rules) and user content, and several send data to AI providers and mail services that the operator chooses. An operator who must answer a data protection request, or write their own record of processing, has to read the code today.

**Checked by.** F29 (_app_): `docs/privacy.md` exists with the four sections. Whether it is complete is a review question; the logging check (F10) keeps personal data out of logs.

**Exceptions.** None.

## F30 Runbooks

**Rule.** Every product keeps its runbooks in `docs/runbooks/`, written for the operator at 3 a.m.: commands to copy, in order, and what each one's success looks like.

- `deploy.md`: the deploy and upgrade steps (`<app>:upgrade`, migrations, cache rebuild, queue restart), what to check after (`/health/ready`, `excubia:check`), and a **Rollback** section: the previous release's code against the current schema (expand / contract makes that safe, F21), and the restore (`backup.md`) when it is not.
- `incident.md`: the **First steps** (maintenance mode or not, where the logs are, how to stop a runaway queue), who to tell and how (a security incident follows `SECURITY.md`), and **After the incident**: a dated entry in the page's log with cause, impact and the PR that fixes the cause.
- `secrets.md` (F20) and `backup.md` (F22).

A runbook step that turned out wrong during a real deploy or incident is corrected in the next PR.

**Why.** On 2026-11-25 no repo had a runbook directory: deploy and upgrade steps were spread over READMEs and `docs/upgrade.md` pages, and no product said how to roll back or what to do first in an incident. Every qiick product is self-hosted, so the operator, not the maintainer, is the one who needs the page.

**Checked by.** F30 (_app_): `docs/runbooks/deploy.md` with a **Rollback** section and `docs/runbooks/incident.md` with **First steps** and **After the incident**. Adopting the conventions writes all four runbooks from `standards/templates/`.

**Exceptions.** None.

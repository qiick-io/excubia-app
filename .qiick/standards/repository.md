<!-- Generated from qiick-io/app-ui standards: do not edit, run the sync. The agent files hold the core of this page and point here. -->

# Repository settings and agent rules

Every qiick repo is configured the same way, so a check name, a label or a merge button means the same thing everywhere (fleet conventions F15–F17, decision of 2026-11-24).

- **F15 Settings:** default branch `develop`; `develop` and `main` protected the same way (pull request, required checks with the same names on both, "up to date" on, admins included, no force push or deletion); squash merge with the PR title as the commit, merge commits for `main`, no rebase merge; head branches deleted after merge; every action and reusable workflow pinned by full commit SHA with the version in a comment.
- **F16 Labels and project:** the fleet label set (`priority:P0`–`priority:P3`, `security`, `schema`, `documentation`, `bug`, `enhancement`, …) exists in every repo, and every repo is linked to its GitHub project.
- **F17 Agents** working in a repo:
    - watch CI at most once every 3 minutes, never in a tight loop;
    - never `pkill` / `killall`: stop only processes you started, by PID;
    - read the `.env` in use before every `migrate` (which connection, which database), and only ever migrate the database of your own worktree;
    - run `gh` as the maintainer's account (`gh auth status` shows it active) and never as a second account logged in on the machine;
    - no `Co-authored-by` or other attribution trailers (see the git rules).

`scripts/check-repo-settings.mjs` in app-ui checks F15 and F16 through `gh api`; F15's action pins are also checked in the repo itself by the conventions check.

## F15 Repository settings

**Rule.**

| Setting                                   | Value                                                                                                                                                                                                                                                         |
| ----------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Default branch                            | `develop`                                                                                                                                                                                                                                                     |
| Merge methods                             | squash on (commit title: the PR title, message: the PR body), merge commit on (release PRs into `main` and the merge back), rebase off                                                                                                                        |
| After merge                               | delete the head branch                                                                                                                                                                                                                                        |
| Branch protection on `develop` and `main` | pull request required (0 approvals), required status checks with **the same list on both**, "require branches to be up to date" on, admins included (`enforce_admins`), force push and deletion off                                                           |
| Rulesets                                  | `main: merge commits only`, `release tags: created by admins only`, `release tags: immutable`                                                                                                                                                                 |
| Actions                                   | every `uses:` pinned by a 40-character commit SHA with the version in a comment (`uses: actions/checkout@<sha> # v4.2.2`), the org's own reusable workflows too; "require actions to be pinned to a full-length commit SHA" on once the repo's workflows pass |
| Required check names                      | the shared workflows are called with the job ids the release rules list (`pr-title`, `app-ui-drift`, `php-quality`, …), so a check is named the same in every repo                                                                                            |

**Why.** On 2026-11-24 (`check-repo-settings.mjs` over the 19 public repos) the settings differed almost everywhere: 8 repos defaulted to `main` (app-ui, externa-core, excubia-core, the starters); 10 did not require `develop` to be up to date and 9 exempted admins on `develop`; 8 required different checks on `develop` and `main` (every package requires none on `main`); laravel-audit's `main` was unprotected; rebase merge was on in 11 repos; laravel-oidc's drift check reports as `standards / standards`, not `app-ui-drift / standards`; and three repos still called workflows by tag (instituta-core, externa-core, laravel-api-keys). A squash commit takes the PR title only when the setting says so; with the default it takes a single commit's message, which commitlint never checked. A tag can be moved; a SHA cannot.

**Checked by.** `node scripts/check-repo-settings.mjs [<repo> …]` (all non-archived public repos of `qiick-io` when no repo is named) reads the settings, protections and rulesets through `gh api` and lists every difference from `standards/repo-settings.json`. The action pins are also F15 of the conventions check, on the repo's own workflows. Changing a setting is the maintainer's: an agent runs the script and reports.

**Exceptions.** A private repo cannot be protected on the free plan (decision 2026-11-10): the rules apply by convention, and the script reports protection as unavailable, not as a violation. A repo's own extra required checks are its own; only the list being identical on both branches is required.

## F16 Labels and project

**Rule.** Every repo has the labels in `standards/repo-settings.json` (`labels`), with the same names, colours and descriptions; a repo may add its own (`area:*`, `effort:*`). Every issue gets a priority label when it is triaged. Every repo is linked to one GitHub project of the `qiick-io` organisation (one per product or package), where its issues and PRs are added on creation (the git rules).

**Why.** Priorities were `priority:P1`–`P2` in some repos and `P0`–`P3` in others, `schema` existed in three repos and `security` was missing in twelve, so a fleet-wide query for open P0 or security issues missed repos. Twelve repos (app-ui, excubia-app, the starters and five packages) had no project linked, although the packages have an organisation project each.

**Checked by.** `scripts/check-repo-settings.mjs` (missing labels, and `--fix-labels` creates or updates them; projects through the GraphQL API).

**Exceptions.** None.

## F17 Agent rules

**Rule.** The list in the core above, for every agent and contributor, in every repo.

**Why.**

- Every session on the machine shares one GitHub API rate limit (5,000 requests an hour for the account); a watcher polling every few seconds spends it for all of them, and a check that takes ten minutes needs a few polls, not hundreds.
- Several agents work on the same machine at once: `pkill php` or `pkill node` stops every other session's server, queue worker and test run too.
- signaculum-core#176: a test rewrote the shared `.env`, so later commands of other worktrees ran against the wrong database; and on 2026-11-09 the disk filled up with about 150 worktrees and copied databases. Reading the `.env` before `migrate` and touching only your own worktree's database prevents both.
- More than one GitHub account can be logged in on the maintainer's machine; a PR or comment from the wrong one is attributed to someone else and may lack access.

**Checked by.** The sync writes these rules into every repo's agent files; the drift check fails when the block differs. Nothing checks behaviour, so reviewers and the coordinator do.

**Exceptions.** None.

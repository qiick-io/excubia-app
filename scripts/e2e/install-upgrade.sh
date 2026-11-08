#!/usr/bin/env bash
#
# Install and upgrade smoke test, as an operator would do it (qiick standards: install and
# upgrade like externa-core).
#
# 1. `composer create-project` from this checkout (a path repository standing in for
#    Packagist): only `.env` and `APP_KEY`, no database.
# 2. `php artisan excubia:install -n` (SQLite, frontend build): migrations done, permissions and
#    the system project seeded, no user created.
# 3. The app answers /health/ready, /health/live and /setup.
# 4. `php artisan excubia:upgrade --force` runs on the installed tree.
# 5. When a release tag exists, the previous release is installed from GitHub, the new tree
#    takes over its .env and database (Path B of docs/self-hosting.md) and is upgraded.
#
# Usage: scripts/e2e/install-upgrade.sh   (from the repository root; needs PHP, Composer, Node)

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WORK="$(mktemp -d)"
PORT="${SMOKE_PORT:-8765}"
SERVER_PID=""

cleanup() {
    local status=$?
    if [ -n "$SERVER_PID" ]; then
        kill "$SERVER_PID" 2>/dev/null || true
    fi
    # `php artisan serve` workers may still be writing logs: the clean-up never fails the run.
    rm -rf "$WORK" 2>/dev/null || true
    exit "$status"
}
trap cleanup EXIT

step() { printf '\n==> %s\n' "$1"; }
fail() { printf 'FAIL: %s\n' "$1" >&2; exit 1; }

# A copy of this checkout as the package source, without local build output.
create_project() {
    local target="$1"
    composer create-project qiick/excubia-app "$target" dev-smoke \
        --repository="{\"type\":\"path\",\"url\":\"$ROOT\",\"options\":{\"symlink\":false,\"versions\":{\"qiick/excubia-app\":\"dev-smoke\"}}}" \
        --stability=dev --no-interaction --prefer-dist --no-progress
}

# `php artisan serve` forks PHP workers: stop the whole group, not only the parent.
stop_server() {
    pkill -P "$SERVER_PID" 2>/dev/null || true
    kill "$SERVER_PID" 2>/dev/null || true
    wait "$SERVER_PID" 2>/dev/null || true
    SERVER_PID=""
}

serve_and_probe() {
    local dir="$1"
    (cd "$dir" && exec php artisan serve --host=127.0.0.1 --port="$PORT" >/dev/null 2>&1) &
    SERVER_PID=$!

    for _ in $(seq 1 30); do
        if curl -fsS "http://127.0.0.1:$PORT/health/ready" >/dev/null 2>&1; then
            curl -fsS "http://127.0.0.1:$PORT/health/live" >/dev/null || fail "/health/live"
            curl -fsS -o /dev/null "http://127.0.0.1:$PORT/setup" || fail "/setup"
            stop_server
            return 0
        fi
        sleep 1
    done

    fail "the app did not answer /health/ready"
}

assert_installed() {
    local dir="$1"
    (cd "$dir" && php artisan migrate:status --no-ansi | grep -q Pending) && fail "pending migrations in $dir"
    local counts
    counts="$(cd "$dir" && php artisan tinker --execute 'echo json_encode(["permissions" => DB::table("permissions")->count(), "system" => DB::table("projects")->where("is_system", true)->count()]);')"
    echo "$counts" | grep -q '"system":1' || fail "no system project ($counts)"
    echo "$counts" | grep -q '"permissions":0' && fail "no permissions ($counts)"
    return 0
}

step "composer create-project (from this checkout)"
create_project "$WORK/app"
[ -f "$WORK/app/.env" ] || fail "create-project did not copy .env"
grep -q '^APP_KEY=base64:' "$WORK/app/.env" || fail "create-project did not set APP_KEY"
[ ! -f "$WORK/app/database/database.sqlite" ] || fail "create-project must not create the database"

step "php artisan excubia:install -n"
(cd "$WORK/app" && php artisan excubia:install -n --force --url="http://127.0.0.1:$PORT")
assert_installed "$WORK/app"
serve_and_probe "$WORK/app"

step "php artisan excubia:upgrade --force (same release)"
(cd "$WORK/app" && php artisan excubia:upgrade --force)
assert_installed "$WORK/app"

PREVIOUS="$(git -C "$ROOT" tag --list 'v*' --sort=-v:refname | head -n 1)"

if [ -z "$PREVIOUS" ]; then
    step "No release tag yet: skipping the upgrade from the previous release"
    exit 0
fi

step "Install the previous release $PREVIOUS from GitHub"
composer create-project "qiick/excubia-app:${PREVIOUS#v}" "$WORK/previous" \
    --repository='{"type":"vcs","url":"https://github.com/qiick-io/excubia-app"}' \
    --stability=dev --no-interaction --prefer-dist --no-progress
(cd "$WORK/previous" && php artisan excubia:install -n --force --no-npm --url="http://127.0.0.1:$PORT")

step "Upgrade $PREVIOUS to this checkout (Path B: new tree, same .env and database)"
create_project "$WORK/next"
cp "$WORK/previous/.env" "$WORK/next/.env"
cp "$WORK/previous/database/database.sqlite" "$WORK/next/database/database.sqlite"
sed -i.bak "s#^DB_DATABASE=.*#DB_DATABASE=$WORK/next/database/database.sqlite#" "$WORK/next/.env"
(cd "$WORK/next" && npm ci --no-audit --no-fund && npm run build && php artisan excubia:upgrade --force)
assert_installed "$WORK/next"
serve_and_probe "$WORK/next"

step "Install and upgrade smoke passed"

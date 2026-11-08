#!/usr/bin/env bash
#
# End-to-end contract test between qiick/excubia-core and this app (#112).
#
# Serves this app with `php artisan serve` on a throwaway SQLite database, registers a project
# with a push token, creates a throwaway Laravel app that installs qiick/excubia-core with every
# runtime type and exception capture turned on, generates some traffic there, then runs
# `excubia:check --format=json`, `excubia:push` and `excubia:push-runtime` against this app.
# It fails unless every command exits 0 (the push commands exit 3 on any non-2xx answer) and the
# app stored a report, runtime aggregates and exception groups for the project, `health`
# aggregates when the core has them (#115), and `ai` aggregates when the core has that type (#169):
# the throwaway app then installs the Laravel AI SDK (`laravel/ai`, a fixture of this test, not a
# dependency of this app) and runs one agent prompt, and a classification when the core sends that
# operation, on the SDK's fake gateways, so no provider is called. Skipped runtime types only warn.
#
# Usage: scripts/e2e/core-contract.sh [core constraint]   (default: ^1.0@beta; e.g. dev-develop)
# Environment: E2E_WORK_DIR (default: a new temp dir), E2E_APP_PORT (8765), E2E_PROBE_PORT (8766),
# E2E_KEEP=1 keeps the work dir. Needs PHP 8.4+ with pdo_sqlite, Composer 2 and curl; this app's
# `composer install` must have run. The local .env and database are not used: the database, logs and
# throwaway app live in the work dir.

set -euo pipefail

CORE_VERSION="${1:-^1.0@beta}"
APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WORK_DIR="${E2E_WORK_DIR:-$(mktemp -d)}"
APP_PORT="${E2E_APP_PORT:-8765}"
PROBE_PORT="${E2E_PROBE_PORT:-8766}"
APP_URL="http://127.0.0.1:${APP_PORT}"
PROBE_URL="http://127.0.0.1:${PROBE_PORT}"
PROBE_DIR="${WORK_DIR}/probe"
SERVER_PIDS=()
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"

mkdir -p "${WORK_DIR}"

step() {
    printf '\n==> %s\n' "$*"
}

fail() {
    printf '\nE2E FAILED: %s\n' "$*" >&2

    for log in "${WORK_DIR}"/*.log; do
        if [ -f "${log}" ]; then
            printf '\n--- %s (last 40 lines) ---\n' "${log}" >&2
            tail -n 40 "${log}" >&2
        fi
    done

    exit 1
}

cleanup() {
    for pid in "${SERVER_PIDS[@]:-}"; do
        if [ -n "${pid}" ]; then
            kill "${pid}" 2>/dev/null || true
            wait "${pid}" 2>/dev/null || true
        fi
    done

    # `artisan serve` runs `php -S` as a child process, which outlives its parent.
    pkill -f -- "-S 127.0.0.1:(${APP_PORT}|${PROBE_PORT}) " 2>/dev/null || true

    if [ "${E2E_KEEP:-0}" != "1" ]; then
        sleep 1
        rm -rf "${WORK_DIR}" 2>/dev/null || true
    else
        printf 'Kept %s\n' "${WORK_DIR}"
    fi
}

trap cleanup EXIT

# This app runs on its own environment: real environment variables win over any local .env.
app_artisan() {
    (
        cd "${APP_ROOT}"
        env \
            APP_ENV=local \
            APP_DEBUG=true \
            APP_KEY="${APP_KEY}" \
            APP_URL="${APP_URL}" \
            APP_CONFIG_CACHE="${WORK_DIR}/app-config.php" \
            APP_ROUTES_CACHE="${WORK_DIR}/app-routes.php" \
            DB_CONNECTION=sqlite \
            DB_URL= \
            DB_DATABASE="${WORK_DIR}/app.sqlite" \
            CACHE_STORE=array \
            SESSION_DRIVER=array \
            QUEUE_CONNECTION=sync \
            MAIL_MAILER=array \
            LOG_CHANNEL=stderr \
            EXCUBIA_SELF_MONITORING=false \
            EXCUBIA_AI_ENABLED=false \
            php artisan "$@"
    )
}

probe_artisan() {
    (cd "${PROBE_DIR}" && php artisan "$@")
}

ensure_port_free() {
    if curl -s -o /dev/null "http://127.0.0.1:$1"; then
        fail "port $1 is already in use (set E2E_APP_PORT / E2E_PROBE_PORT)"
    fi
}

wait_for() {
    local url="$1"

    for _ in $(seq 1 60); do
        if curl -s -o /dev/null "${url}"; then
            return 0
        fi

        sleep 0.5
    done

    fail "nothing answers on ${url}"
}

step "Serve excubia-app on ${APP_URL} (database ${WORK_DIR}/app.sqlite)"
touch "${WORK_DIR}/app.sqlite"
app_artisan migrate:fresh --force --no-interaction > "${WORK_DIR}/app-migrate.log" 2>&1 || fail "migrate failed"
TOKEN="$(app_artisan tinker --execute '
    $project = app(App\Actions\CreateProjectAction::class)->execute("E2E probe", "e2e-probe", true);
    echo "TOKEN=".app(App\Actions\RotateProjectTokenAction::class)->execute($project).PHP_EOL;
' 2>> "${WORK_DIR}/app-migrate.log" | sed -n 's/^TOKEN=//p')"
[ -n "${TOKEN}" ] || fail "could not create the project token"
ensure_port_free "${APP_PORT}"
app_artisan serve --host=127.0.0.1 --port="${APP_PORT}" --no-reload > "${WORK_DIR}/app-server.log" 2>&1 &
SERVER_PIDS+=($!)
wait_for "${APP_URL}/up"

step "Create a throwaway Laravel app with qiick/excubia-core:${CORE_VERSION}"
composer create-project laravel/laravel "${PROBE_DIR}" --no-interaction --prefer-dist --quiet > "${WORK_DIR}/probe-composer.log" 2>&1 || fail "composer create-project failed"
(cd "${PROBE_DIR}" && composer require --dev "qiick/excubia-core:${CORE_VERSION}" --no-interaction --with-all-dependencies) >> "${WORK_DIR}/probe-composer.log" 2>&1 || fail "composer require qiick/excubia-core:${CORE_VERSION} failed"
(cd "${PROBE_DIR}" && composer show qiick/excubia-core | sed -n 's/^versions *: /installed: /p')

cat >> "${PROBE_DIR}/.env" <<ENV

EXCUBIA_APP_URL=${APP_URL}
EXCUBIA_APP_TOKEN=${TOKEN}
EXCUBIA_SERVER_NAME=e2e-probe
ENV

# Every runtime type the installed core knows, read from its own schema, so a type added to the
# core (health on develop) is turned on here without editing this script.
(cd "${PROBE_DIR}" && php -r '
    $schema = json_decode(file_get_contents("vendor/qiick/excubia-core/excubia.schema.json"), true, flags: JSON_THROW_ON_ERROR);
    $runtime = ["enabled" => true];

    foreach (array_keys($schema["properties"]["runtime"]["properties"]) as $key) {
        if (! in_array($key, ["enabled", "bufferMaxBytes"], true)) {
            $runtime[$key] = ["enabled" => true];
        }
    }

    $runtime["slowQueries"]["thresholdMs"] = 0;

    if (isset($runtime["duplicateQueries"])) {
        $runtime["duplicateQueries"]["threshold"] = 2;
    }

    $configuration = ["failOn" => "none", "runtime" => $runtime, "exceptions" => ["enabled" => true]];
    file_put_contents("excubia.json", json_encode($configuration, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    echo "runtime types enabled: ".implode(", ", array_slice(array_keys($runtime), 1)).PHP_EOL;
')

# A core with minute-level health samples (#115) must have them stored, not skipped.
EXPECT_HEALTH=0

if php -r 'exit(isset(json_decode(file_get_contents($argv[1]), true)["runtime"]["health"]) ? 0 : 1);' "${PROBE_DIR}/excubia.json"; then
    EXPECT_HEALTH=1
fi

# A core with the `ai` type (#198) records the Laravel AI SDK: install it in the throwaway app and
# drive it without network through its fake gateways (#169).
EXPECT_AI=0
EXPECT_CLASSIFICATION=0

if php -r 'exit(isset(json_decode(file_get_contents($argv[1]), true)["runtime"]["ai"]) ? 0 : 1);' "${PROBE_DIR}/excubia.json"; then
    EXPECT_AI=1
    (cd "${PROBE_DIR}" && composer require laravel/ai --no-interaction --with-all-dependencies) >> "${WORK_DIR}/probe-composer.log" 2>&1 || fail "composer require laravel/ai failed"
    (cd "${PROBE_DIR}" && composer show laravel/ai | sed -n 's/^versions *: /laravel\/ai installed: /p')

    if grep -q '"classification"' "${PROBE_DIR}/vendor/qiick/excubia-core/docs/runtime-schema.json"; then
        EXPECT_CLASSIFICATION=1
    fi

    mkdir -p "${PROBE_DIR}/app/Ai"
    cat > "${PROBE_DIR}/app/Ai/E2eAgent.php" <<'PHP'
<?php

namespace App\Ai;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

// An agent for the Excubia end-to-end contract test, answered by the SDK's fake gateway.
class E2eAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'You answer the Excubia end-to-end test.';
    }
}
PHP

    cat >> "${PROBE_DIR}/routes/web.php" <<PHP

// AI traffic for the Excubia end-to-end contract test, on the SDK's fake gateways (no network).
Route::get('/e2e/ai', function () {
    App\Ai\E2eAgent::fake(['e2e answer']);
    (new App\Ai\E2eAgent)->prompt('e2e', provider: 'openai', model: 'gpt-e2e');

    if (${EXPECT_CLASSIFICATION} === 1) {
        Laravel\Ai\Classification::fake();
        Laravel\Ai\Classification::of('e2e')
            ->question('mood', new Laravel\Ai\Classification\Choice('Mood?', ['happy' => 'Happy', 'sad' => 'Sad']))
            ->classify(['openai' => 'gpt-e2e']);
    }

    return 'ok';
});
PHP
fi

cat >> "${PROBE_DIR}/routes/web.php" <<'PHP'

// Traffic for the Excubia end-to-end contract test (scripts/e2e/core-contract.sh in excubia-app).
Route::get('/e2e/traffic', function () {
    foreach (range(1, 3) as $value) {
        Illuminate\Support\Facades\DB::select('select ? as value', [$value]);
    }

    Illuminate\Support\Facades\Cache::put('e2e:key', 'value', 60);
    Illuminate\Support\Facades\Cache::get('e2e:key');
    Illuminate\Support\Facades\Cache::get('e2e:missing');
    Illuminate\Support\Facades\Cache::forget('e2e:key');
    Illuminate\Support\Facades\Log::warning('e2e warning');
    trigger_error('e2e deprecation', E_USER_DEPRECATED);
    Illuminate\Support\Facades\Http::get(env('EXCUBIA_APP_URL').'/up');
    dispatch(function () {
        Illuminate\Support\Facades\DB::select('select 1');
    });
    Illuminate\Support\Facades\Mail::raw('e2e', fn ($message) => $message->to('e2e@example.com')->subject('e2e'));
    Illuminate\Support\Facades\Notification::route('mail', 'e2e@example.com')->notify(new class extends Illuminate\Notifications\Notification
    {
        public function via(object $notifiable): array
        {
            return ['mail'];
        }

        public function toMail(object $notifiable): Illuminate\Notifications\Messages\MailMessage
        {
            return (new Illuminate\Notifications\Messages\MailMessage)->line('e2e');
        }
    });

    return 'ok';
});

Route::get('/e2e/fail', fn () => throw new RuntimeException('e2e exception'));
PHP

cat >> "${PROBE_DIR}/routes/console.php" <<'PHP'

Illuminate\Support\Facades\Schedule::command('inspire')->everyMinute();
PHP

step "Generate runtime traffic and an exception in the throwaway app"
ensure_port_free "${PROBE_PORT}"
probe_artisan serve --host=127.0.0.1 --port="${PROBE_PORT}" --no-reload > "${WORK_DIR}/probe-server.log" 2>&1 &
SERVER_PIDS+=($!)
wait_for "${PROBE_URL}/up"

for _ in 1 2 3; do
    status="$(curl -s -o /dev/null -w '%{http_code}' "${PROBE_URL}/e2e/traffic")"
    [ "${status}" = "200" ] || fail "GET /e2e/traffic answered ${status}"
done

if [ "${EXPECT_AI}" = "1" ]; then
    status="$(curl -s -o /dev/null -w '%{http_code}' "${PROBE_URL}/e2e/ai")"
    [ "${status}" = "200" ] || fail "GET /e2e/ai answered ${status}"
fi

status="$(curl -s -o /dev/null -w '%{http_code}' "${PROBE_URL}/e2e/fail")"
[ "${status}" = "500" ] || fail "GET /e2e/fail answered ${status}, expected 500"

probe_artisan queue:work --stop-when-empty > "${WORK_DIR}/probe-queue.log" 2>&1 || fail "queue:work failed"
probe_artisan inspire > /dev/null
probe_artisan schedule:run > "${WORK_DIR}/probe-schedule.log" 2>&1 || fail "schedule:run failed"

step "excubia:check --format=json --no-banner"
probe_artisan excubia:check --format=json --no-banner > "${WORK_DIR}/check.json" 2> "${WORK_DIR}/check.log" || fail "excubia:check exited $?"
php -r 'json_decode(file_get_contents($argv[1]), flags: JSON_THROW_ON_ERROR); echo "valid JSON report".PHP_EOL;' "${WORK_DIR}/check.json" || fail "excubia:check did not print JSON"

step "excubia:push"
probe_artisan excubia:push || fail "excubia:push exited $?"

step "excubia:push-runtime"
probe_artisan excubia:push-runtime || fail "excubia:push-runtime exited $?"

step "Rows stored by excubia-app"
RESULT="$(app_artisan tinker --execute '
    $project = App\Models\Project::query()->where("slug", "e2e-probe")->firstOrFail();
    echo "RESULT=".json_encode([
        "reports" => $project->healthReports()->count(),
        "runtimeAggregates" => $project->runtimeAggregates()->count(),
        "runtimeTypes" => $project->runtimeAggregates()->toBase()->selectRaw("type, count(*) as rows")->groupBy("type")->pluck("rows", "type"),
        "aiOperations" => $project->runtimeAggregates()->where("type", "ai")->get()->map(fn ($aggregate) => $aggregate->dimensions["operation"] ?? null)->unique()->values(),
        "exceptionGroups" => $project->exceptionGroups()->count(),
        "skippedRuntimeAggregates" => $project->skipped_runtime_aggregates,
    ]).PHP_EOL;
' 2>> "${WORK_DIR}/app-migrate.log" | sed -n 's/^RESULT=//p')"
[ -n "${RESULT}" ] || fail "could not read the stored rows"
printf '%s\n' "${RESULT}"

php -r '
    $result = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
    $errors = [];

    foreach (["reports", "runtimeAggregates", "exceptionGroups"] as $key) {
        if ($result[$key] < 1) {
            $errors[] = "no ".$key." stored";
        }
    }

    if ($argv[2] === "1" && ($result["runtimeTypes"]["health"] ?? 0) < 1) {
        $errors[] = "no health aggregates stored";
    }

    if ($argv[3] === "1" && ! in_array("agent", $result["aiOperations"], true)) {
        $errors[] = "no ai agent aggregates stored";
    }

    if ($argv[4] === "1" && ! in_array("classification", $result["aiOperations"], true)) {
        $errors[] = "no ai classification aggregates stored";
    }

    if ($result["skippedRuntimeAggregates"] > 0) {
        // A type this app does not store yet: the push still succeeds (#111), so warn only.
        $message = $result["skippedRuntimeAggregates"]." runtime aggregates of a type this app does not know were skipped";
        echo getenv("GITHUB_ACTIONS") === "true" ? "::warning title=excubia-core contract::".$message.PHP_EOL : "WARNING: ".$message.PHP_EOL;
    }

    if ($errors !== []) {
        fwrite(STDERR, implode(PHP_EOL, $errors).PHP_EOL);
        exit(1);
    }
' "${RESULT}" "${EXPECT_HEALTH}" "${EXPECT_AI}" "${EXPECT_CLASSIFICATION}" || fail "excubia-app did not store the pushed data"

step "E2E passed against qiick/excubia-core:${CORE_VERSION}"

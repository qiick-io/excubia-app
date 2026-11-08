<?php

declare(strict_types=1);

use App\Actions\StorePushedReportAction;
use App\Enums\CheckCategoryEnum;
use App\Enums\RoleEnum;
use App\Models\AlertLog;
use App\Models\HealthReport;
use App\Models\Project;
use App\Models\User;
use App\Services\ReportHealthService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The checks of excubia-core 1.0.0-beta.2 and beta.3 in the app (#108, #109): every check id with
 * its structured `details`, grouped, ordered problems-first, summarised on the overview and the
 * dashboard, the license inventory, and the immediate infrastructure alerts.
 *
 * `tests/Fixtures/core/report-schema.json` is a copy of excubia-core `develop` at
 * 9ad5e3805f689b296a72bf382fe472c298668368 (2026-11-08, core #218: `laravel-cashier`). `report-every-check.json` holds one
 * check per contract id, and one finding per `details` shape of the schema. Refresh both when
 * core adds a check id.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    Date::setTestNow('2026-11-07 09:00:00');
});

/** The check ids of excubia-core `docs/CONTRACTS.md`, in run order. */
const CONTRACT_CHECK_IDS = [
    'php-eol', 'laravel-eol', 'node-eol', 'composer-advisories', 'npm-advisories', 'composer-abandoned',
    'composer-stale', 'laravel-next-blocker', 'laravel-debug-mode', 'laravel-production-caches',
    'laravel-pending-migrations', 'laravel-failed-jobs', 'laravel-scheduler-heartbeat', 'laravel-maintenance-mode',
    'laravel-app-key', 'laravel-session-security', 'laravel-cors-wildcard', 'laravel-production-drivers',
    'laravel-log-level', 'laravel-dev-packages', 'dependency-licenses', 'laravel-database', 'laravel-cache',
    'laravel-storage', 'laravel-disk-space', 'laravel-queue-backlog', 'php-platform-requirements', 'env-example-sync',
    'composer-outdated', 'laravel-queue-workers', 'npm-outdated', 'laravel-backups', 'laravel-database-connections',
    'laravel-database-size', 'laravel-redis-memory', 'php-opcache', 'php-production-ini',
    'laravel-octane-config', 'laravel-cashier',
];

/**
 * @return array<string, mixed>
 */
function everyCheckReport(): array
{
    return json_decode((string) file_get_contents(base_path('tests/Fixtures/core/report-every-check.json')), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * @param  array<string, mixed>  $report
 */
function pushEveryCheckReport(Project $project, array $report): HealthReport
{
    return app(StorePushedReportAction::class)->execute($project, (string) Str::uuid(), Date::now(), $report);
}

/**
 * @param  array<string, mixed>  $report
 * @param  array<string, mixed>  $check
 * @return array<string, mixed>
 */
function withCheck(array $report, array $check): array
{
    $report['checks'] = [...array_values(array_filter($report['checks'], fn (array $existing): bool => $existing['checkId'] !== $check['checkId'])), $check];

    return $report;
}

it('covers every contract check id and every details shape of the core schema', function () {
    $schema = json_decode((string) file_get_contents(base_path('tests/Fixtures/core/report-schema.json')), true, flags: JSON_THROW_ON_ERROR);
    $report = everyCheckReport();

    // The details definitions per check id, from the schema's allOf rules.
    $definitions = [];

    foreach ($schema['$defs']['finding']['allOf'] as $rule) {
        $checkId = $rule['if']['properties']['checkId'];
        $details = $rule['then']['properties']['details'];

        foreach ((array) ($checkId['const'] ?? $checkId['enum']) as $id) {
            $definitions[$id] = array_map(fn (array $reference): string => substr($reference['$ref'], strlen('#/$defs/')), $details['oneOf'] ?? [$details]);
        }
    }

    $usedDefinitions = [];

    foreach ($report['checks'] as $check) {
        // The validator skips allOf: each finding's details are matched to their definition below.
        expect(coreSchemaErrors($check, $schema['$defs']['check'], $schema))->toBe([]);

        foreach ($check['findings'] as $finding) {
            if (! isset($finding['details'])) {
                continue;
            }

            $matches = array_values(array_filter($definitions[$finding['checkId']] ?? [], fn (string $definition): bool => coreSchemaErrors($finding['details'], ['$ref' => "#/\$defs/{$definition}"], $schema) === []));

            expect($matches)->not->toBe([], "{$finding['checkId']} {$finding['subject']}");
            $usedDefinitions[] = $matches[0];
        }
    }

    expect(array_column($report['checks'], 'checkId'))->toBe(CONTRACT_CHECK_IDS)
        ->and(array_diff(CONTRACT_CHECK_IDS, CheckCategoryEnum::knownCheckIds()))->toBe([])
        ->and(array_values(array_diff(array_unique(array_merge(...array_values($definitions))), $usedDefinitions)))->toBe([])
        ->and(coreSchemaErrors($report['licenses'], $schema['properties']['licenses'], $schema))->toBe([]);
});

it('lists the issues problems-first with their group, and summarises the details on the overview', function () {
    $project = Project::factory()->create(['slug' => 'shop']);
    pushEveryCheckReport($project, everyCheckReport());

    $this->actingAs(User::factory()->create()->assignRole(RoleEnum::Reader->value))
        ->get(route('projects.show', 'shop'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/show')
            // Failing infrastructure comes first, the critical database before the critical advisory.
            ->where('issues.0.checkId', 'laravel-database')
            ->where('issues.0.category', 'infrastructure')
            ->where('issues.0.details', ['connection' => 'mysql'])
            ->where('issues.1.checkId', 'laravel-cache')
            ->where('issues.7.checkId', 'composer-advisories')
            ->where('issues.7.category', 'dependencies')
            ->where('health.groups.0.category', 'infrastructure')
            ->where('health.groups.0.status', 'fail')
            ->where('health.groups.1.category', 'configuration')
            ->where('health.groups.2.category', 'dependencies')
            ->has('health.groups', 3)
            ->has('health.groups.0.checks', 14)
            ->where('health.infrastructure.status', 'fail')
            ->where('health.infrastructure.diskUsedPercent', 93)
            ->where('health.infrastructure.database', ['connection' => 'mysql', 'sizeMb' => 2048.5, 'largestTables' => [['table' => 'activity_log', 'sizeMb' => 1024.25], ['table' => 'jobs', 'sizeMb' => 300]]])
            ->where('health.infrastructure.databaseConnections', 72)
            ->where('health.infrastructure.queueBacklog', ['queues' => 2, 'pendingCount' => 1540, 'oldestAgeMinutes' => 35])
            ->where('health.infrastructure.redis.0.usedMb', 870.4)
            ->where('health.infrastructure.backups.0.ageHours', 50)
            ->where('health.outdated.composer', ['patch' => 1, 'minor' => 1, 'major' => 1])
            ->where('health.outdated.npm', ['patch' => 1, 'minor' => 0, 'major' => 0])
            ->where('health.licenses.packageCount', 120)
            ->where('health.licenses.byLicense.0', ['license' => 'MIT', 'composer' => 70, 'npm' => 37, 'total' => 107])
            ->where('health.licenses.flagged.0.kind', 'denied')
            ->where('health.licenses.flagged.1.kind', 'copyleft')
            ->where('health.licenses.flagged.2', ['package' => 'left-pad', 'lockedVersion' => '1.3.0', 'licenses' => [], 'ecosystem' => 'npm', 'kind' => 'unknown', 'severity' => 'low'])
            ->where('project.latest_report.infrastructure', 'fail'));

    $this->get(route('projects.view', ['shop', 'licenses']))
        ->assertInertia(fn (Assert $page) => $page->where('view', 'licenses')->has('health.licenses.flagged', 3));
});

it('reads the license kind from the details (core #204) and infers it from status and severity for older cores', function () {
    $project = Project::factory()->create(['slug' => 'shop']);
    $report = everyCheckReport();
    $licenses = collect($report['checks'])->firstWhere('checkId', 'dependency-licenses');
    $kinds = fn (array $check): array => app(ReportHealthService::class)->flaggedLicenses(withCheck($report, $check));

    // The details win over status and severity: a low warning core marks copyleft is copyleft.
    $licenses['findings'][2]['details']['kind'] = 'copyleft';
    expect(array_column($kinds($licenses), 'kind', 'package'))->toBe(['mongodb/mongodb' => 'denied', 'left-pad' => 'copyleft', 'mpdf/mpdf' => 'copyleft']);

    // Cores before 1.0.0-beta.4 send no kind, and a value outside the enum counts as none.
    $licenses['findings'] = array_map(fn (array $finding): array => [...$finding, 'details' => array_diff_key($finding['details'], ['kind' => true])], $licenses['findings']);
    $licenses['findings'][0]['details']['kind'] = 'forbidden';
    expect(array_column($kinds($licenses), 'kind', 'package'))->toBe(['mongodb/mongodb' => 'denied', 'mpdf/mpdf' => 'copyleft', 'left-pad' => 'unknown']);

    pushEveryCheckReport($project, withCheck($report, $licenses));

    $this->actingAs(User::factory()->create()->assignRole(RoleEnum::Reader->value))
        ->get(route('projects.view', ['shop', 'licenses']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('health.licenses.flagged.0.kind', 'denied')
            ->where('health.licenses.flagged.1.kind', 'copyleft')
            ->where('health.licenses.flagged.2.kind', 'unknown'));
});

it('renders a check id this app does not know under its own name, in the other group', function () {
    $project = Project::factory()->create(['slug' => 'shop']);
    $report = withCheck(everyCheckReport(), [
        'checkId' => 'laravel-octane-workers',
        'name' => 'Octane workers',
        'status' => 'warn',
        'findings' => [['checkId' => 'laravel-octane-workers', 'severity' => 'critical', 'status' => 'warn', 'subject' => 'octane', 'message' => 'Octane is down.', 'fixHint' => 'php artisan octane:start', 'evidenceUrl' => null, 'details' => ['workerCount' => 0]]],
    ]);
    pushEveryCheckReport($project, $report);

    $this->actingAs(User::factory()->create()->assignRole(RoleEnum::Reader->value))
        ->get(route('projects.show', 'shop'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('issues.8.checkId', 'laravel-octane-workers')
            ->where('issues.8.checkName', 'Octane workers')
            ->where('issues.8.category', 'other')
            ->where('issues.8.details', ['workerCount' => 0])
            ->where('health.groups.3.category', 'other')
            ->where('health.groups.3.checks.0.issues', 1));
});

it('shows infrastructure health and the flagged licenses across the fleet', function () {
    $shop = Project::factory()->create(['name' => 'Shop', 'slug' => 'shop']);
    pushEveryCheckReport($shop, everyCheckReport());
    $blog = Project::factory()->create(['name' => 'Blog', 'slug' => 'blog']);
    $healthy = everyCheckReport();
    $healthy['checks'] = array_values(array_filter($healthy['checks'], fn (array $check): bool => in_array($check['checkId'], ['laravel-cache', 'dependency-licenses'], true)));
    $healthy['checks'][0] = [...$healthy['checks'][0], 'status' => 'fail', 'findings' => [$healthy['checks'][0]['findings'][0]]];
    $healthy['checks'][1] = ['checkId' => 'laravel-cache', 'name' => 'Cache', 'status' => 'pass', 'findings' => []];
    pushEveryCheckReport($blog, $healthy);
    Project::factory()->create(['name' => 'New', 'slug' => 'new']);

    $this->actingAs(User::factory()->create()->assignRole(RoleEnum::Reader->value))
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('projects.0.name', 'Blog')
            ->where('projects.0.latest_report.infrastructure', 'pass')
            ->where('projects.2.name', 'Shop')
            ->where('projects.2.latest_report.infrastructure', 'fail')
            ->where('overview.licenses.0', ['license' => 'SSPL-1.0', 'kind' => 'denied', 'packages' => ['mongodb/mongodb'], 'projects' => [['slug' => 'blog', 'name' => 'Blog'], ['slug' => 'shop', 'name' => 'Shop']]])
            ->where('overview.licenses.1.license', 'GPL-2.0-only')
            ->where('overview.licenses.2', ['license' => 'NOASSERTION', 'kind' => 'unknown', 'packages' => ['left-pad'], 'projects' => [['slug' => 'shop', 'name' => 'Shop']]])
            // Infrastructure checks sit with the configuration checks, not in the dependency top issues.
            ->where('overview.configuration.0.checkId', 'laravel-database')
            ->where('overview.topIssues.0.checkId', 'composer-advisories'));
});

it('alerts database, storage and disk failures at once, other infrastructure checks as new findings', function () {
    $project = Project::factory()->create(['slug' => 'shop']);
    $report = everyCheckReport();
    $isCache = fn (array $check): bool => $check['checkId'] === 'laravel-cache';
    $cachePassing = array_map(fn (array $check): array => $isCache($check) ? [...$check, 'status' => 'pass', 'findings' => []] : $check, $report['checks']);

    pushEveryCheckReport($project, [...$report, 'checks' => $cachePassing]);

    $immediate = AlertLog::query()->where('event', 'infrastructure.failed')->orderBy('id')->get();
    expect($immediate->pluck('subject')->all())->toBe(['laravel-database|mysql|', 'laravel-storage|bootstrap/cache|', 'laravel-disk-space|disk|'])
        ->and($immediate->pluck('severity')->unique()->all())->toBe(['critical'])
        ->and(AlertLog::query()->where('event', 'finding.new')->count())->toBe(0);

    // The next report: the cache fails too; the database still fails (not new, no second alert).
    pushEveryCheckReport($project, $report);

    expect(AlertLog::query()->where('event', 'infrastructure.failed')->count())->toBe(3)
        ->and(AlertLog::query()->where('event', 'finding.new')->pluck('subject')->all())->toBe(['laravel-cache|redis|']);
});

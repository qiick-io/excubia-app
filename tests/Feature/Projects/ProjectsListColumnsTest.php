<?php

declare(strict_types=1);

use App\Actions\StorePushedReportAction;
use App\Enums\RoleEnum;
use App\Models\HealthReport;
use App\Models\Project;
use App\Models\User;
use App\Services\ReportHealthService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The projects list columns (#293): health (worst check status of the latest report), failed
 * checks, open findings per severity, all sortable on the server and read in one query per page.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    Date::setTestNow('2026-11-12 09:00:00');
});

function listAdmin(): User
{
    return User::factory()->create()->assignRole(RoleEnum::Admin->value);
}

/**
 * A report whose checks have the given statuses, stored like a push.
 *
 * @param  list<string>  $checkStatuses
 */
function listReport(Project $project, array $checkStatuses, string $receivedAt = '2026-11-12 08:00:00'): HealthReport
{
    $checks = array_map(
        fn (string $status, int $index): array => ['checkId' => "check-{$index}", 'name' => "Check {$index}", 'status' => $status, 'findings' => []],
        $checkStatuses,
        array_keys($checkStatuses),
    );

    return $project->healthReports()->create([
        'batch_id' => (string) Str::uuid(),
        'received_at' => $receivedAt,
        'generated_at' => $receivedAt,
        'report' => ['schemaVersion' => 1, 'checks' => $checks],
        ...app(ReportHealthService::class)->checkSummary(['checks' => $checks]),
    ]);
}

function listFinding(Project $project, string $severity, string $status = 'open'): void
{
    $project->findings()->create([
        'fingerprint' => hash('sha256', $project->slug.$severity.Str::random()), 'check_id' => 'composer-audit', 'severity' => $severity, 'status' => $status,
        'finding' => ['checkId' => 'composer-audit', 'checkName' => 'Composer audit', 'severity' => $severity, 'status' => 'fail', 'subject' => 'vendor/package', 'message' => 'Known vulnerability', 'fixHint' => '', 'evidenceUrl' => null],
        'first_seen_at' => Date::now(), 'last_seen_at' => Date::now(),
    ]);
}

/**
 * @return list<string>
 */
function listedSlugs(User $user, string $sort, string $direction): array
{
    $slugs = [];

    test()->actingAs($user)->get(route('projects.index', ['sort' => $sort, 'direction' => $direction]))
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$slugs): void {
            $slugs = array_column($page->toArray()['props']['projects']['data'], 'slug');
        });

    return $slugs;
}

it('stores the check summary of a pushed report', function () {
    $project = Project::factory()->create();
    $checks = [
        ['checkId' => 'disk', 'name' => 'Disk', 'status' => 'fail', 'findings' => []],
        ['checkId' => 'queue', 'name' => 'Queue', 'status' => 'warn', 'findings' => []],
        ['checkId' => 'cache', 'name' => 'Cache', 'status' => 'pass', 'findings' => []],
        ['checkId' => 'redis', 'name' => 'Redis', 'status' => 'skipped', 'findings' => []],
    ];

    $report = app(StorePushedReportAction::class)->execute($project, (string) Str::uuid(), Date::now(), ['schemaVersion' => 1, 'checks' => $checks]);

    expect($report->worst_check_status)->toBe('fail')
        ->and($report->checks_count)->toBe(3)
        ->and($report->failed_checks_count)->toBe(1);
});

it('shows health, failed checks and open findings of each project', function () {
    $billing = Project::factory()->create(['name' => 'Billing', 'slug' => 'billing', 'environment' => 'production']);
    listReport($billing, ['pass', 'fail'], '2026-11-11 08:00:00');
    // Only the latest report counts.
    listReport($billing, ['pass', 'warn', 'pass']);
    listFinding($billing, 'critical');
    listFinding($billing, 'low');
    listFinding($billing, 'low');
    listFinding($billing, 'high', 'resolved');
    Project::factory()->create(['name' => 'Silent', 'slug' => 'silent']);

    $this->actingAs(listAdmin())->get(route('projects.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('projects.data.0.slug', 'billing')
            ->where('projects.data.0.environment', 'production')
            ->where('projects.data.0.health', 'warn')
            ->where('projects.data.0.checks', ['failed' => 0, 'total' => 3])
            ->where('projects.data.0.open_findings', ['critical' => 1, 'high' => 0, 'medium' => 0, 'low' => 2, 'info' => 0])
            ->where('projects.data.1.slug', 'silent')
            ->where('projects.data.1.health', null)
            ->where('projects.data.1.checks', null)
            ->where('projects.data.1.open_findings', ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'info' => 0]));
});

it('sorts by health, worst first, with projects without a report last', function () {
    $admin = listAdmin();
    listReport(Project::factory()->create(['name' => 'A passing', 'slug' => 'passing']), ['pass']);
    listReport(Project::factory()->create(['name' => 'B failing', 'slug' => 'failing']), ['pass', 'fail']);
    Project::factory()->create(['name' => 'C silent', 'slug' => 'silent']);
    listReport(Project::factory()->create(['name' => 'D warning', 'slug' => 'warning']), ['warn']);

    expect(listedSlugs($admin, 'health', 'asc'))->toBe(['failing', 'warning', 'passing', 'silent'])
        ->and(listedSlugs($admin, 'health', 'desc'))->toBe(['silent', 'passing', 'warning', 'failing']);
});

it('sorts by open findings, the most severe first', function () {
    $admin = listAdmin();
    $manyLow = Project::factory()->create(['name' => 'A many low', 'slug' => 'many-low']);
    $oneCritical = Project::factory()->create(['name' => 'B one critical', 'slug' => 'one-critical']);
    $twoHigh = Project::factory()->create(['name' => 'C two high', 'slug' => 'two-high']);
    Project::factory()->create(['name' => 'D clean', 'slug' => 'clean']);

    foreach (range(1, 5) as $ignored) {
        listFinding($manyLow, 'low');
    }

    listFinding($oneCritical, 'critical');
    listFinding($twoHigh, 'high');
    listFinding($twoHigh, 'high');

    expect(listedSlugs($admin, 'open_findings', 'desc'))->toBe(['one-critical', 'two-high', 'many-low', 'clean'])
        ->and(listedSlugs($admin, 'open_findings', 'asc'))->toBe(['clean', 'many-low', 'two-high', 'one-critical']);
});

it('reads the list in the same number of queries however many projects it shows', function () {
    $admin = listAdmin();

    $queryCount = function () use ($admin): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin)->get(route('projects.index'))->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $seedProject = function (): void {
        $project = Project::factory()->create();
        listReport($project, ['pass', 'fail']);
        listFinding($project, 'medium');
    };

    $seedProject();
    $queryCount();
    $withOneProject = $queryCount();

    foreach (range(1, 5) as $ignored) {
        $seedProject();
    }

    expect($queryCount())->toBe($withOneProject);
});

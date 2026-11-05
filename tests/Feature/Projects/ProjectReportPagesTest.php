<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use App\Enums\SeverityEnum;
use App\Models\HealthReport;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The projects overview on the dashboard and the project report page (#8).
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    Date::setTestNow('2026-11-05 12:00:00');
});

function reportReader(): User
{
    return User::factory()->create()->assignRole(RoleEnum::Reader->value);
}

function pushedReport(Project $project, string $receivedAt, ?SeverityEnum $worst = null, array $counts = []): HealthReport
{
    $report = $project->healthReports()->create([
        'batch_id' => (string) Str::uuid(),
        'received_at' => $receivedAt,
        'generated_at' => $receivedAt,
        'worst_severity' => $worst,
        ...$counts,
        'report' => [
            'schemaVersion' => 1,
            'checks' => [[
                'checkId' => 'composer-audit',
                'name' => 'Composer audit',
                'status' => 'fail',
                'findings' => [['checkId' => 'composer-audit', 'severity' => 'high', 'status' => 'fail', 'subject' => 'guzzlehttp/guzzle', 'message' => 'Known vulnerability', 'fixHint' => 'composer update guzzlehttp/guzzle', 'evidenceUrl' => 'https://example.test/a']],
            ]],
            'upgradePlan' => [['stepNumber' => 1, 'message' => 'composer update guzzlehttp/guzzle']],
        ],
    ]);
    $project->forceFill(['last_report_at' => $receivedAt])->save();

    return $report;
}

it('lists every project with the summary of its latest report on the dashboard', function () {
    $shop = Project::factory()->create(['name' => 'Shop', 'slug' => 'shop']);
    pushedReport($shop, '2026-11-04 08:00:00', SeverityEnum::Low, ['low_count' => 2]);
    pushedReport($shop, '2026-11-05 08:00:00', SeverityEnum::High, ['high_count' => 1, 'low_count' => 3]);
    $blog = Project::factory()->create(['name' => 'Blog', 'slug' => 'blog']);
    pushedReport($blog, '2026-11-01 08:00:00');
    Project::factory()->create(['name' => 'New', 'slug' => 'new']);

    $this->actingAs(reportReader())->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('canViewProjects', true)
            ->has('projects', 3)
            ->where('projects.0.name', 'Blog')
            ->where('projects.0.is_stale', true)
            ->where('projects.0.latest_report.worst_severity', null)
            ->where('projects.1.name', 'New')
            ->where('projects.1.is_stale', true)
            ->where('projects.1.latest_report', null)
            ->where('projects.2.name', 'Shop')
            ->where('projects.2.is_stale', false)
            ->where('projects.2.latest_report.worst_severity', 'high')
            ->where('projects.2.latest_report.counts', ['critical' => 0, 'high' => 1, 'medium' => 0, 'low' => 3, 'info' => 0])
            ->missing('projects.2.latest_report.report'));
});

it('shows the dashboard without projects to users without projects.view', function () {
    Project::factory()->create();

    $this->actingAs(User::factory()->create())->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('canViewProjects', false)
            ->has('projects', 0));
});

it('shows the latest report, its upgrade plan and the previous reports on the project page', function () {
    $project = Project::factory()->create(['name' => 'Shop', 'slug' => 'shop']);
    $older = pushedReport($project, '2026-11-04 08:00:00', SeverityEnum::Low, ['low_count' => 1]);
    $latest = pushedReport($project, '2026-11-05 08:00:00', SeverityEnum::High, ['high_count' => 1]);

    $this->actingAs(reportReader())->get(route('projects.show', 'shop'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/show')
            ->where('project.slug', 'shop')
            ->where('project.latest_report.id', $latest->id)
            ->where('report.checks.0.name', 'Composer audit')
            ->where('report.checks.0.findings.0.subject', 'guzzlehttp/guzzle')
            ->where('report.checks.0.findings.0.fixHint', 'composer update guzzlehttp/guzzle')
            ->where('report.checks.0.findings.0.evidenceUrl', 'https://example.test/a')
            ->where('report.upgradePlan.0.message', 'composer update guzzlehttp/guzzle')
            ->has('reports', 2)
            ->where('reports.0.id', $latest->id)
            ->where('reports.1.id', $older->id)
            ->where('reports.1.worst_severity', 'low'));
});

it('shows an empty project page before the first report', function () {
    Project::factory()->create(['slug' => 'new']);

    $this->actingAs(reportReader())->get(route('projects.show', 'new'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('report', null)
            ->where('project.latest_report', null)
            ->has('reports', 0));
});

it('keeps the project page behind projects.view', function () {
    Project::factory()->create(['slug' => 'shop']);

    $this->actingAs(User::factory()->create())->get(route('projects.show', 'shop'))->assertForbidden();
    $this->actingAs(reportReader())->get(route('projects.show', 'unknown'))->assertNotFound();
});

it('summarises the core 1.0 fields of the latest report for the dashboard and the project page', function () {
    $shop = Project::factory()->create(['name' => 'Shop', 'slug' => 'shop']);
    $report = pushedReport($shop, '2026-11-05 08:00:00', SeverityEnum::High, ['high_count' => 1]);
    $report->update(['report' => [
        ...$report->report,
        'excubiaVersion' => '1.0.0-beta.1',
        'project' => [
            'php' => ['version' => '8.4.12', 'cycle' => '8.4', 'securitySupportEndsOn' => '2028-12-31', 'activeSupportEndsOn' => '2026-12-31'],
            'laravel' => ['version' => 'v13.2.0', 'cycle' => '13', 'securitySupportEndsOn' => '2028-03-17', 'activeSupportEndsOn' => '2027-09-17'],
            'node' => null,
        ],
        'checks' => [
            ...$report->report['checks'],
            ['checkId' => 'php-eol', 'name' => 'PHP end of life', 'status' => 'pass', 'findings' => []],
            ['checkId' => 'node-eol', 'name' => 'Node end of life', 'status' => 'skipped', 'findings' => [['checkId' => 'node-eol', 'severity' => 'info', 'status' => 'skipped', 'subject' => 'node', 'message' => 'No Node', 'fixHint' => '', 'evidenceUrl' => null]]],
        ],
    ]]);

    $this->actingAs(reportReader())->get(route('projects.show', 'shop'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('project.latest_report.excubia_version', '1.0.0-beta.1')
            ->where('project.latest_report.runtime.php.cycle', '8.4')
            ->where('project.latest_report.runtime.laravel.version', '13.2.0')
            ->where('project.latest_report.runtime.php.security_support_ends_on', '2028-12-31')
            ->where('project.latest_report.runtime.node', null)
            ->where('project.latest_report.checks', ['pass' => 1, 'warn' => 0, 'fail' => 1, 'skipped' => 1])
            ->where('project.latest_report.top_finding.subject', 'guzzlehttp/guzzle')
            ->where('project.latest_report.top_finding.severity', 'high')
            ->where('project.latest_report.top_finding.check_name', 'Composer audit'));

    $this->actingAs(reportReader())->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('projects.0.latest_report.runtime.laravel.cycle', '13'));
});

it('keeps the summary working for reports from cores before 1.0', function () {
    $blog = Project::factory()->create(['slug' => 'blog']);
    pushedReport($blog, '2026-11-05 08:00:00', SeverityEnum::High, ['high_count' => 1]);

    $this->actingAs(reportReader())->get(route('projects.show', 'blog'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('project.latest_report.excubia_version', null)
            ->where('project.latest_report.runtime', null)
            ->where('project.latest_report.checks.fail', 1)
            ->where('project.latest_report.top_finding.severity', 'high'));
});

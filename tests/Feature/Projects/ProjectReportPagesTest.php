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

it('shows the dashboard without projects to users without can-show-projects', function () {
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

it('keeps the project page behind can-show-projects', function () {
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

it('sums the latest report of each project per day for the dashboard trend', function () {
    $shop = Project::factory()->create(['slug' => 'shop']);
    $blog = Project::factory()->create(['slug' => 'blog']);
    pushedReport($shop, '2026-10-01 08:00:00', SeverityEnum::High, ['high_count' => 4]);
    pushedReport($shop, '2026-11-04 09:00:00', SeverityEnum::High, ['high_count' => 1, 'low_count' => 2]);
    pushedReport($blog, '2026-11-05 07:00:00', SeverityEnum::Critical, ['critical_count' => 1]);

    $this->actingAs(reportReader())->get(route('dashboard', ['period' => 30]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('scope.period', 30)
            ->has('trend', 30)
            ->where('trend.0.date', '2026-10-07')
            ->where('trend.0.high', 4)
            ->where('trend.28.date', '2026-11-04')
            ->where('trend.28.high', 1)
            ->where('trend.28.low', 2)
            ->where('trend.28.critical', 0)
            ->where('trend.29.date', '2026-11-05')
            ->where('trend.29.critical', 1)
            ->where('trend.29.high', 1));
});

it('leaves projects restricted to admins out of the trend', function () {
    $hidden = Project::factory()->create(['slug' => 'hidden', 'visibility' => 'admins']);
    pushedReport($hidden, '2026-11-05 07:00:00', SeverityEnum::Critical, ['critical_count' => 3]);

    $this->actingAs(reportReader())->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->has('trend', 14)->where('trend.13.critical', 0)->where('overview.kpis.findings.total', 0));
});

it('builds the issue-first overview for all projects or one, with the previous period', function () {
    $shop = Project::factory()->create(['name' => 'Shop', 'slug' => 'shop']);
    $blog = Project::factory()->create(['name' => 'Blog', 'slug' => 'blog']);
    $finding = fn (string $package, string $locked) => ['checkId' => 'composer-advisories', 'severity' => 'high', 'status' => 'fail', 'subject' => "{$package} {$locked}", 'message' => 'Known vulnerability', 'fixHint' => "Update {$package}", 'evidenceUrl' => null, 'advisoryId' => 'GHSA-1', 'details' => ['package' => $package, 'lockedVersion' => $locked, 'fixedVersions' => ['7.9.0']]];
    $body = fn (array $findings, ?string $phpEnd = null) => [
        'schemaVersion' => 1,
        'project' => ['php' => ['version' => '8.2.10', 'cycle' => '8.2', 'securitySupportEndsOn' => $phpEnd, 'activeSupportEndsOn' => null], 'laravel' => null, 'node' => null],
        'checks' => [
            ['checkId' => 'composer-advisories', 'name' => 'Known vulnerabilities (Composer)', 'status' => 'fail', 'findings' => $findings],
            ['checkId' => 'laravel-debug-mode', 'name' => 'Debug mode', 'status' => 'fail', 'findings' => [['checkId' => 'laravel-debug-mode', 'severity' => 'high', 'status' => 'fail', 'subject' => 'app.debug', 'message' => 'Debug mode is on', 'fixHint' => 'Set APP_DEBUG=false', 'evidenceUrl' => null]]],
        ],
        'upgradePlan' => [],
    ];

    // Previous period (14 days ago): one high finding in shop only.
    pushedReport($shop, '2026-10-20 08:00:00', SeverityEnum::High, ['high_count' => 1])->update(['report' => $body([])]);
    pushedReport($shop, '2026-11-05 08:00:00', SeverityEnum::High, ['high_count' => 2])->update(['report' => $body([$finding('guzzlehttp/guzzle', '7.4.0')], '2026-12-31')]);
    pushedReport($blog, '2026-11-05 09:00:00', SeverityEnum::High, ['high_count' => 2])->update(['report' => $body([$finding('guzzlehttp/guzzle', '7.5.0')], '2028-12-31')]);

    $this->actingAs(reportReader())->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('scope', ['project' => null, 'group' => null, 'period' => 14])
            ->where('overview.kpis.findings.total', 4)
            ->where('overview.kpis.findings.previous', 1)
            ->where('overview.kpis.atRisk.value', 2)
            ->where('overview.kpis.atRisk.previous', 1)
            ->where('overview.kpis.endOfSupport.value', 1)
            ->where('overview.kpis.stale.total', 2)
            ->where('overview.topIssuesTotal', 1)
            ->where('overview.topIssues.0.package', 'guzzlehttp/guzzle')
            ->where('overview.topIssues.0.installed', ['7.5.0', '7.4.0'])
            ->where('overview.topIssues.0.fixedVersions', ['7.9.0'])
            ->has('overview.topIssues.0.projects', 2)
            ->has('overview.endOfSupport', 1)
            ->where('overview.endOfSupport.0.project.slug', 'shop')
            ->where('overview.endOfSupport.0.securitySupportEndsOn', '2026-12-31')
            ->has('overview.configuration', 2)
            ->where('overview.configuration.0.checkId', 'laravel-debug-mode'));

    $this->actingAs(reportReader())->get(route('dashboard', ['project' => 'blog', 'group' => null, 'period' => 7]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('scope', ['project' => 'blog', 'group' => null, 'period' => 7])
            ->has('projects', 2)
            ->where('overview.kpis.findings.total', 2)
            ->where('overview.kpis.findings.previous', null)
            ->where('overview.topIssues.0.projects.0.slug', 'blog')
            ->has('overview.configuration', 1)
            ->has('trend', 7));

    $this->actingAs(reportReader())->get(route('dashboard', ['project' => 'nope', 'period' => 5]))
        ->assertInertia(fn (Assert $page) => $page->where('scope', ['project' => null, 'group' => null, 'period' => 14]));
});

it('sends a ready fix per finding to the project page and the dashboard', function () {
    $shop = Project::factory()->create(['slug' => 'shop']);
    pushedReport($shop, '2026-11-05 08:00:00', SeverityEnum::High, ['high_count' => 1]);

    $this->actingAs(reportReader())->get(route('projects.show', 'shop'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('findingFixes.composer-audit|guzzlehttp/guzzle|')
            ->where('findingFixes.composer-audit|guzzlehttp/guzzle|.command', null)
            ->where('findingFixes.composer-audit|guzzlehttp/guzzle|.prompt', fn (string $prompt): bool => str_contains($prompt, 'composer update guzzlehttp/guzzle')));

    $this->actingAs(reportReader())->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->has('overview.topIssues.0.fix.prompt'));
});

it('gives every project tab its own URL and redirects the old ?view= links', function () {
    Project::factory()->create(['slug' => 'shop']);
    $reader = reportReader();

    $this->actingAs($reader)->get(route('projects.show', 'shop'))
        ->assertInertia(fn (Assert $page) => $page->component('projects/show')->where('view', 'overview'));

    foreach (['findings', 'runtime', 'exceptions', 'plan', 'history'] as $view) {
        $this->actingAs($reader)->get(route('projects.view', ['project' => 'shop', 'view' => $view]))
            ->assertInertia(fn (Assert $page) => $page->where('view', $view));
    }

    $this->actingAs($reader)->get('/projects/shop/nope')->assertNotFound();
    $this->actingAs($reader)->get('/projects/shop?view=findings')->assertRedirect(route('projects.view', ['project' => 'shop', 'view' => 'findings']));
});

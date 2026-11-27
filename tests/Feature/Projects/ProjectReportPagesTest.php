<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use App\Enums\SeverityEnum;
use App\Http\Controllers\ProjectReportController;
use App\Models\HealthReport;
use App\Models\Project;
use App\Models\User;
use App\Services\SettingsRepositoryService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
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

it('shows the latest report and its upgrade plan on the overview', function () {
    $project = Project::factory()->create(['name' => 'Shop', 'slug' => 'shop']);
    pushedReport($project, '2026-11-04 08:00:00', SeverityEnum::Low, ['low_count' => 1]);
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
            ->missing('reports')
            ->missing('historyChart'));
});

it('paginates history with a stable order and a chart independent of the table page', function () {
    $project = Project::factory()->create();
    $deploy = pushedReport($project, '2026-11-03 08:00:00');
    $deploy->update(['release' => 'v1.0.0', 'deployed_at' => '2026-11-03 08:00:00']);
    $reports = collect(range(1, 110))->map(
        fn (int $count): HealthReport => pushedReport($project, '2026-11-05 08:00:00', SeverityEnum::Low, ['low_count' => $count]),
    );
    $this->actingAs(reportReader());

    $this->get(route('projects.view', [$project->slug, 'history', 'page' => 2, 'per_page' => 50]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('reports.current_page', 2)
            ->where('reports.total', 111)
            ->has('reports.data', 50)
            ->where('reports.data.0.id', $reports[59]->id)
            ->missing('reports.data.0.top_finding')
            ->has('historyChart', 100)
            ->where('historyChart.0.counts.low', 110)
            ->where('historyChart.99.counts.low', 11)
            ->missing('overviewDeploys'));

    $this->get(route('projects.view', [$project->slug, 'history', 'per_page' => 200]))
        ->assertInertia(fn (Assert $page) => $page->has('reports.data', 20));

    $this->get(route('projects.view', [$project->slug, 'ai', 'page' => 'unused', 'direction' => 'unused']))
        ->assertOk();

    foreach (ProjectReportController::VIEWS as $view) {
        if ($view === 'history') {
            continue;
        }

        $this->get(route('projects.view', [$project->slug, $view]))
            ->assertInertia(fn (Assert $page) => $page->missing('reports')->missing('historyChart')->missing('overviewDeploys'));
    }

    $this->get(route('projects.show', $project->slug))
        ->assertInertia(fn (Assert $page) => $page->where('overviewDeploys.count', 1)->where('overviewDeploys.latest.release', 'v1.0.0'))
        ->assertInertia(fn (Assert $page) => $page->reloadOnly('liveHealth', fn (Assert $reload) => $reload
            ->has('liveHealth')->missing('overviewDeploys')->missing('reports')->missing('historyChart')));
});

it('shows an empty project page before the first report', function () {
    Project::factory()->create(['slug' => 'new']);

    $this->actingAs(reportReader())->get(route('projects.show', 'new'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('report', null)
            ->where('project.latest_report', null)
            ->missing('reports'));

    $this->get(route('projects.view', ['new', 'history']))
        ->assertInertia(fn (Assert $page) => $page->where('reports.total', 0)->has('historyChart', 0));
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
        ->assertInertia(fn (Assert $page) => $page->has('trend', 0)->where('overview.kpis.findings.total', 0));
});

it('starts the dashboard trend at the first report inside the period', function () {
    $shop = Project::factory()->create(['slug' => 'shop']);
    pushedReport($shop, '2026-11-03 08:00:00', SeverityEnum::High, ['high_count' => 2]);
    pushedReport($shop, '2026-11-05 08:00:00', SeverityEnum::Critical, ['critical_count' => 1]);

    $this->actingAs(reportReader())->get(route('dashboard', ['period' => 30]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('trend', 3)
            ->where('trend.0.date', '2026-11-03')
            ->where('trend.0.high', 2)
            ->where('trend.1.high', 2)
            ->where('trend.2.date', '2026-11-05')
            ->where('trend.2.critical', 1));
});

it('keeps dashboard queries flat for 200 visible projects', function () {
    $project = Project::factory()->create();
    // The newest row wins when multiple reports have the cutoff timestamp.
    pushedReport($project, '2026-10-22 12:00:00', SeverityEnum::High, ['high_count' => 9]);
    pushedReport($project, '2026-10-22 12:00:00', SeverityEnum::Low, ['low_count' => 3]);
    pushedReport($project, '2026-10-22 12:00:01', SeverityEnum::Critical, ['critical_count' => 1]);
    $this->actingAs(reportReader());

    $queriesForDashboard = function (): int {
        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
                ->where('overview.kpis.findings.previous', 3)
                ->where('overview.kpis.atRisk.previous', 0));

            return count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    };

    $singleProjectQueries = $queriesForDashboard();
    Project::factory()->count(199)->create();

    expect($queriesForDashboard())->toBeLessThanOrEqual($singleProjectQueries + 2);
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
            ->where('scope', ['project' => null, 'group' => null, 'period' => 14, 'isAssignedToMe' => false])
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
            ->where('scope', ['project' => 'blog', 'group' => null, 'period' => 7, 'isAssignedToMe' => false])
            ->has('projects', 2)
            ->where('overview.kpis.findings.total', 2)
            ->where('overview.kpis.findings.previous', null)
            ->where('overview.topIssues.0.projects.0.slug', 'blog')
            ->has('overview.configuration', 1)
            // Blog's only report is today's, so its trend has a single day (#295).
            ->has('trend', 1));

    $this->actingAs(reportReader())->get(route('dashboard', ['project' => 'nope', 'period' => 5]))
        ->assertInertia(fn (Assert $page) => $page->where('scope', ['project' => null, 'group' => null, 'period' => 14, 'isAssignedToMe' => false]));
});

it('narrows the dashboard top issues to the findings assigned to me with ?assigned=me', function () {
    $shop = Project::factory()->create(['name' => 'Shop', 'slug' => 'shop']);
    $me = reportReader();
    $other = reportReader();
    $finding = fn (string $package) => ['checkId' => 'composer-advisories', 'fingerprint' => hash('sha256', $package), 'severity' => 'high', 'status' => 'fail', 'subject' => $package, 'message' => "Known vulnerability in {$package}", 'fixHint' => '', 'evidenceUrl' => null, 'details' => ['package' => $package]];
    pushedReport($shop, '2026-11-05 08:00:00', SeverityEnum::High, ['high_count' => 2])->update(['report' => [
        'schemaVersion' => 1,
        'checks' => [['checkId' => 'composer-advisories', 'name' => 'Known vulnerabilities', 'status' => 'fail', 'findings' => [$finding('guzzlehttp/guzzle'), $finding('monolog/monolog')]]],
        'upgradePlan' => [],
    ]]);

    foreach (['guzzlehttp/guzzle' => $me, 'monolog/monolog' => $other] as $package => $assignee) {
        $shop->findings()->create([
            'fingerprint' => hash('sha256', $package), 'check_id' => 'composer-advisories', 'severity' => 'high', 'status' => 'open',
            'finding' => $finding($package), 'first_seen_at' => Date::now(), 'last_seen_at' => Date::now(),
        ])->forceFill(['assigned_user_id' => $assignee->id])->save();
    }

    $this->actingAs($me)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('overview.topIssuesTotal', 2));

    $this->actingAs($me)->get(route('dashboard', ['assigned' => 'me']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('scope.isAssignedToMe', true)
            ->where('overview.topIssuesTotal', 1)
            ->where('overview.topIssues.0.package', 'guzzlehttp/guzzle'));

    $this->actingAs(reportReader())->get(route('dashboard', ['assigned' => 'me']))
        ->assertInertia(fn (Assert $page) => $page->where('overview.topIssuesTotal', 0));
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

it('groups the top issues that share a check id and a message into one row with a count (#291)', function () {
    $blocker = fn (string $package, string $severity = 'medium'): array => [
        'checkId' => 'laravel-next-blocker', 'severity' => $severity, 'status' => 'fail', 'subject' => $package,
        'message' => 'Latest release does not allow Laravel 14.', 'fixHint' => 'Watch, replace or fork.',
        'details' => ['package' => $package, 'lockedVersion' => '1.0.0', 'targetMajor' => 14],
    ];

    foreach (['shop' => ['spatie/a', 'spatie/b'], 'blog' => ['spatie/b', 'spatie/c']] as $slug => $packages) {
        $report = pushedReport(Project::factory()->create(['slug' => $slug, 'name' => ucfirst($slug)]), '2026-11-05 08:00:00', SeverityEnum::High, ['high_count' => 1]);
        $body = $report->report;
        $body['checks'][] = ['checkId' => 'laravel-next-blocker', 'name' => 'Next-major blockers', 'status' => 'fail', 'findings' => array_map($blocker, $packages)];
        $report->update(['report' => $body]);
    }

    $this->actingAs(reportReader())->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            // The advisory and one row for the three blocking packages.
            ->where('overview.topIssuesTotal', 2)
            ->where('overview.topIssues.0.package', 'guzzlehttp/guzzle')
            ->where('overview.topIssues.0.packages', [])
            ->where('overview.topIssues.1.checkId', 'laravel-next-blocker')
            ->where('overview.topIssues.1.targetMajor', 14)
            ->where('overview.topIssues.1.fix', null)
            ->has('overview.topIssues.1.packages', 3)
            ->where('overview.topIssues.1.packages.0.package', 'spatie/b')
            ->has('overview.topIssues.1.packages.0.projects', 2)
            ->has('overview.topIssues.1.projects', 2));
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

it('names the MCP upgrade plan tool on the plan tab only while an AI client can call it', function () {
    $project = Project::factory()->create(['slug' => 'shop']);
    pushedReport($project, '2026-11-05 08:00:00');
    $this->actingAs(reportReader());
    $isAvailable = fn (): bool => $this->get(route('projects.view', ['shop', 'plan']))->inertiaProps('isUpgradePlanMcpToolAvailable');

    // MCP is off by default (#469 review: the hint promised a tool that answered 404).
    expect($isAvailable())->toBeFalse();

    config(['excubia.mcp_enabled' => true]);
    expect($isAvailable())->toBeTrue();

    app(SettingsRepositoryService::class)->save(['ai_disabled_tools' => ['get-upgrade-plan']]);
    expect($isAvailable())->toBeFalse();
});

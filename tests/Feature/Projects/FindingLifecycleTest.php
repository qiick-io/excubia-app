<?php

declare(strict_types=1);

use App\Actions\RotateProjectTokenAction;
use App\Enums\RoleEnum;
use App\Models\AlertLog;
use App\Models\Finding;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The finding lifecycle (#18) end to end: pushed reports move it, alerts follow it, the
 * Findings tab shows it, and findings are dismissed with a reason and an optional last day.
 */
beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    $this->seed(PermissionSeeder::class);
    Date::setTestNow('2026-11-05 12:00:00');
});

function lifecycleUser(RoleEnum $role = RoleEnum::Admin): User
{
    return User::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace'])->assignRole($role->value);
}

/**
 * Pushes a report through `POST /api/reports`: `[checkId => [[subject, severity], …]]`.
 *
 * @param  array<string, list<array{0: string, 1: string}>>  $checks
 */
function pushLifecycleReport(string $token, array $checks): void
{
    test()->postJson(route('api.reports.store'), [
        'protocolVersion' => 1,
        'batchId' => (string) Str::uuid(),
        'sentAt' => Date::now()->toIso8601ZuluString(),
        'report' => [
            'schemaVersion' => 1,
            'checks' => array_map(fn (string $checkId, array $findings): array => [
                'checkId' => $checkId,
                'name' => Str::headline($checkId),
                'status' => $findings === [] ? 'pass' : 'fail',
                'findings' => array_map(fn (array $finding): array => [
                    'checkId' => $checkId, 'severity' => $finding[1], 'status' => 'fail', 'subject' => $finding[0],
                    'message' => 'Known vulnerability', 'fixHint' => 'composer update '.$finding[0], 'evidenceUrl' => null,
                    'fingerprint' => hash('sha256', $checkId."\0".$finding[0]."\0"),
                ], $findings),
            ], array_keys($checks), $checks),
            'upgradePlan' => [],
        ],
    ], ['Authorization' => 'Bearer '.$token])->assertCreated();
}

/**
 * @return array{0: Project, 1: string}
 */
function lifecycleProject(): array
{
    $project = Project::factory()->create(['slug' => 'shop']);

    return [$project, app(RotateProjectTokenAction::class)->execute($project)];
}

/**
 * The finding and severity alerts so far.
 *
 * @return list<string>
 */
function alertEvents(): array
{
    return AlertLog::query()->whereIn('event', ['finding.new', 'finding.resolved', 'project.worsened', 'project.recovered'])->orderBy('id')->pluck('event')->map(fn (mixed $event): string => (string) $event)->all();
}

it('raises no resolved or recovered alerts for a push with fewer checks, and alerts only on a real fix', function () {
    [$project, $token] = lifecycleProject();

    pushLifecycleReport($token, ['composer-audit' => [['a/a', 'high']], 'npm-audit' => [['left-pad', 'critical']]]);
    // The standalone CLI runs composer-audit only: left-pad is unchecked, not fixed.
    pushLifecycleReport($token, ['composer-audit' => [['a/a', 'high']]]);

    expect(alertEvents())->toBe([])
        ->and($project->findings()->where('status', 'open')->count())->toBe(2);

    pushLifecycleReport($token, ['composer-audit' => [['a/a', 'high']], 'npm-audit' => []]);

    expect(alertEvents())->toBe(['finding.resolved', 'project.recovered']);

    pushLifecycleReport($token, ['composer-audit' => [['a/a', 'high']], 'npm-audit' => [['left-pad', 'critical']]]);

    expect(alertEvents())->toBe(['finding.resolved', 'project.recovered', 'finding.new', 'project.worsened']);
});

it('lists open findings from unchecked checks and resolved ones on the Findings tab, with their lifecycle', function () {
    [$project, $token] = lifecycleProject();

    Date::setTestNow('2026-11-01 08:00:00');
    pushLifecycleReport($token, ['composer-audit' => [['a/a', 'high'], ['b/b', 'low']], 'npm-audit' => [['left-pad', 'medium']]]);
    Date::setTestNow('2026-11-04 08:00:00');
    pushLifecycleReport($token, ['composer-audit' => [['a/a', 'high']]]);
    Date::setTestNow('2026-11-05 12:00:00');

    $this->actingAs(lifecycleUser(RoleEnum::Reader))
        ->get(route('projects.view', ['project' => 'shop', 'view' => 'findings']))
        ->assertInertia(fn (Assert $page) => $page->component('projects/show')
            ->has('findings', 3)
            ->where('findings.0.subject', 'a/a')
            ->has('findings.0.category')
            ->where('findings.0.lifecycle.status', 'open')
            ->where('findings.0.lifecycle.firstSeenAt', '2026-11-01T08:00:00+00:00')
            ->where('findings.0.lifecycle.lastSeenAt', '2026-11-04T08:00:00+00:00')
            ->where('findings.1.subject', 'left-pad')
            ->where('findings.1.lifecycle.status', 'open')
            ->where('findings.2.subject', 'b/b')
            ->where('findings.2.lifecycle.status', 'resolved')
            ->where('findings.2.lifecycle.resolvedAt', '2026-11-04T08:00:00+00:00')
            ->where('findings.2.lifecycle.history.1.event', 'resolved')
            ->has('findingFixes', fn (Assert $fixes) => $fixes->has('npm-audit|left-pad|')->etc())
            ->where('canDismissFindings', false));

    // Other tabs do not pay for the lifecycle.
    $this->actingAs(lifecycleUser(RoleEnum::Reader))
        ->get(route('projects.show', 'shop'))
        ->assertInertia(fn (Assert $page) => $page->where('findings', []));
});

it('dismisses a finding with a reason and a last day, mutes its alerts and removes the dismissal', function () {
    [$project, $token] = lifecycleProject();
    pushLifecycleReport($token, ['composer-audit' => [['a/a', 'critical'], ['b/b', 'low']]]);
    $finding = Finding::query()->where('fingerprint', hash('sha256', "composer-audit\0a/a\0"))->sole();
    $admin = lifecycleUser();

    $this->actingAs($admin)
        ->patch(route('projects.findings.update', [$project, $finding]), ['is_dismissed' => true, 'reason' => 'Not reachable from user input', 'until' => '2026-12-31'])
        ->assertRedirect()
        ->assertInertiaFlash('toast.type', 'success');

    expect($finding->fresh())->dismissed_reason->toBe('Not reachable from user input')
        ->dismissed_until->toDateString()->toBe('2026-12-31')
        ->dismissed_by_user_id->toBe($admin->id);

    // Gone and back while dismissed: no finding alerts, no severity change.
    pushLifecycleReport($token, ['composer-audit' => [['b/b', 'low']]]);
    pushLifecycleReport($token, ['composer-audit' => [['a/a', 'critical'], ['b/b', 'low']]]);

    expect(alertEvents())->toBe([]);

    $this->actingAs($admin)
        ->get(route('projects.view', ['project' => 'shop', 'view' => 'findings']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('canDismissFindings', true)
            ->where('findings.0.subject', 'a/a')
            ->where('findings.0.lifecycle.status', 'dismissed')
            ->where('findings.0.lifecycle.reopenedCount', 1)
            ->where('findings.0.lifecycle.dismissal.reason', 'Not reachable from user input')
            ->where('findings.0.lifecycle.dismissal.until', '2026-12-31')
            ->where('findings.0.lifecycle.dismissal.by', 'Ada Lovelace')
            ->where('findings.0.lifecycle.history.1.event', 'dismissed')
            ->where('findings.0.lifecycle.history.1.by', 'Ada Lovelace'));

    $this->actingAs($admin)
        ->patch(route('projects.findings.update', [$project, $finding]), ['is_dismissed' => false])
        ->assertRedirect();

    expect($finding->fresh())->dismissed_at->toBeNull()->dismissed_reason->toBeNull()
        ->and($finding->events()->pluck('event')->map->value->all())->toBe(['opened', 'dismissed', 'resolved', 'reopened', 'undismissed']);
});

it('needs can-dismiss-findings, a reason, a last day from today on and a finding of the project', function () {
    [$project, $token] = lifecycleProject();
    pushLifecycleReport($token, ['composer-audit' => [['a/a', 'high']]]);
    $finding = Finding::query()->sole();
    $other = Project::factory()->create();

    $this->actingAs(lifecycleUser(RoleEnum::Reader))
        ->patch(route('projects.findings.update', [$project, $finding]), ['is_dismissed' => true, 'reason' => 'x'])
        ->assertForbidden();

    $this->actingAs(lifecycleUser())
        ->patch(route('projects.findings.update', [$other, $finding]), ['is_dismissed' => true, 'reason' => 'x'])
        ->assertForbidden();

    $this->actingAs(lifecycleUser())
        ->patch(route('projects.findings.update', [$project, $finding]), ['is_dismissed' => true, 'reason' => '', 'until' => '2026-11-04'])
        ->assertSessionHasErrors(['reason', 'until']);

    expect($finding->fresh()->dismissed_at)->toBeNull();
});

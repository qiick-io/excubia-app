<?php

declare(strict_types=1);

use App\Actions\SyncFindingLifecycleAction;
use App\Actions\UpdateFindingDismissalAction;
use App\Data\FindingChangesData;
use App\Enums\FindingStatusEnum;
use App\Enums\SeverityEnum;
use App\Models\Finding;
use App\Models\Project;
use App\Models\User;
use App\Services\ReportFindingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Qiick\Excubia\Data\FindingData;
use Qiick\Excubia\Enums\FindingStatusEnum as CoreFindingStatusEnum;
use Qiick\Excubia\Enums\SeverityEnum as CoreSeverityEnum;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class);

/**
 * Lifecycle transitions of findings across reports (#18): open, seen again, resolved only when
 * the check ran, reopened, dismissed.
 */

/**
 * A report with one entry per check: `[checkId => [[subject, severity], …]]`, or
 * `[checkId => ['status' => 'skipped', 'findings' => [...]]]` for a check row as is.
 *
 * @param  array<string, array<mixed>>  $checks
 * @return array<string, mixed>
 */
function lifecycleReport(array $checks): array
{
    $rows = [];

    foreach ($checks as $checkId => $check) {
        if (isset($check['status'])) {
            $rows[] = ['checkId' => $checkId, 'name' => $checkId, 'findings' => [], ...$check];

            continue;
        }

        $rows[] = [
            'checkId' => $checkId,
            'name' => Str::headline($checkId),
            'status' => $check === [] ? 'pass' : 'fail',
            'findings' => array_map(fn (array $finding): array => [
                'checkId' => $checkId, 'severity' => $finding[1], 'status' => 'fail', 'subject' => $finding[0],
                'message' => 'Problem with '.$finding[0], 'fixHint' => '', 'evidenceUrl' => null,
            ], $check),
        ];
    }

    return ['schemaVersion' => 1, 'checks' => $rows, 'upgradePlan' => []];
}

/**
 * Stores the report row (without the push side effects) and moves the lifecycle with it.
 *
 * @param  array<string, array<mixed>>  $checks
 */
function pushLifecycle(Project $project, array $checks): FindingChangesData
{
    $report = $project->healthReports()->create([
        'batch_id' => (string) Str::uuid(),
        'received_at' => Date::now(),
        'generated_at' => Date::now(),
        'report' => is_array($checks['checks'] ?? null) ? $checks : lifecycleReport($checks),
    ]);

    return app(SyncFindingLifecycleAction::class)->execute($project, $report);
}

/**
 * @return list<string>
 */
function subjects(array $rows): array
{
    return array_map(fn (array $row): string => $row['subject'], $rows);
}

function findingFor(Project $project, string $subject): Finding
{
    return $project->findings()->get()->first(fn (Finding $finding): bool => $finding->finding['subject'] === $subject);
}

it('opens a finding once, keeps it open while reports have it and records first and last seen', function () {
    $project = Project::factory()->create();
    Date::setTestNow('2026-11-01 10:00:00');

    $first = pushLifecycle($project, ['composer-audit' => [['a/a', 'high']]]);
    Date::setTestNow('2026-11-02 10:00:00');
    $second = pushLifecycle($project, ['composer-audit' => [['a/a', 'high']]]);

    $finding = findingFor($project, 'a/a');

    expect(subjects($first->new))->toBe(['a/a'])
        ->and($second->new)->toBe([])
        ->and($second->resolved)->toBe([])
        ->and($finding->status)->toBe(FindingStatusEnum::Open)
        ->and($finding->first_seen_at->toDateString())->toBe('2026-11-01')
        ->and($finding->last_seen_at->toDateString())->toBe('2026-11-02')
        ->and($finding->events->pluck('event')->map->value->all())->toBe(['opened']);
});

it('resolves a finding gone from a report whose check ran, and reopens it when it comes back', function () {
    $project = Project::factory()->create();

    pushLifecycle($project, ['composer-audit' => [['a/a', 'high'], ['b/b', 'low']]]);
    $resolving = pushLifecycle($project, ['composer-audit' => [['b/b', 'low']]]);
    $reopening = pushLifecycle($project, ['composer-audit' => [['a/a', 'high'], ['b/b', 'low']]]);

    $finding = findingFor($project, 'a/a');

    expect(subjects($resolving->resolved))->toBe(['a/a'])
        ->and($resolving->worstBefore)->toBe(SeverityEnum::High)
        ->and($resolving->worstAfter)->toBe(SeverityEnum::Low)
        ->and(subjects($reopening->new))->toBe(['a/a'])
        ->and($reopening->worstAfter)->toBe(SeverityEnum::High)
        ->and($finding->status)->toBe(FindingStatusEnum::Open)
        ->and($finding->resolved_at)->toBeNull()
        ->and($finding->reopened_count)->toBe(1)
        ->and($finding->events->pluck('event')->map->value->all())->toBe(['opened', 'resolved', 'reopened'])
        ->and($finding->events->pluck('health_report_id')->filter()->count())->toBe(3);
});

it('does not resolve findings of checks that did not run in the report (standalone CLI, --only, skipped, data source down)', function () {
    $project = Project::factory()->create();

    pushLifecycle($project, [
        'composer-audit' => [['a/a', 'high']],
        'npm-audit' => [['left-pad', 'medium']],
        'php-eol' => [['8.2', 'medium']],
        'env-debug' => [['APP_DEBUG', 'critical']],
    ]);

    // Fewer checks: npm-audit is missing, php-eol skipped, composer-audit had a data-source failure.
    $partial = pushLifecycle($project, [
        'composer-audit' => ['status' => 'skipped', 'findings' => [[
            'checkId' => 'composer-audit', 'severity' => 'info', 'status' => 'skipped', 'subject' => 'packagist.org',
            'message' => 'Data source failed', 'fixHint' => '', 'evidenceUrl' => null, 'skipReason' => 'data-source-failure',
        ]]],
        'php-eol' => ['status' => 'skipped', 'findings' => []],
        'env-debug' => [],
    ]);

    expect(subjects($partial->resolved))->toBe(['APP_DEBUG'])
        ->and($partial->worstBefore)->toBe(SeverityEnum::Critical)
        ->and($partial->worstAfter)->toBe(SeverityEnum::High)
        ->and($project->findings()->where('status', 'open')->count())->toBe(3);
});

it('keeps a check with a data-source failure row from resolving, even when the check itself warns', function () {
    $project = Project::factory()->create();
    pushLifecycle($project, ['composer-audit' => [['a/a', 'high']]]);

    $report = lifecycleReport(['composer-audit' => [['b/b', 'low']]]);
    $report['checks'][0]['findings'][] = [
        'checkId' => 'composer-audit', 'severity' => 'info', 'status' => 'skipped', 'subject' => 'packagist.org',
        'message' => 'Data source failed', 'fixHint' => '', 'evidenceUrl' => null, 'skipReason' => 'data-source-failure',
    ];
    pushLifecycle($project, $report);

    expect(findingFor($project, 'a/a')->status)->toBe(FindingStatusEnum::Open)
        ->and(findingFor($project, 'b/b')->status)->toBe(FindingStatusEnum::Open);
});

it('keys findings on core fingerprint and computes the same recipe for reports without one', function () {
    $core = new FindingData(
        checkId: 'composer-audit',
        severity: CoreSeverityEnum::High,
        status: CoreFindingStatusEnum::Fail,
        subject: 'guzzlehttp/guzzle',
        message: 'Known vulnerability',
        fixHint: '',
        evidenceUrl: null,
        advisoryId: 'GHSA-1234',
    );
    $service = app(ReportFindingsService::class);

    expect($service->fingerprint(['checkId' => 'composer-audit', 'subject' => 'guzzlehttp/guzzle', 'advisoryId' => 'GHSA-1234']))->toBe($core->fingerprint())
        ->and($service->fingerprint(['checkId' => 'x', 'subject' => 'y', 'fingerprint' => str_repeat('a', 64)]))->toBe(str_repeat('a', 64));
});

it('leaves dismissed findings out of the changes and the worst severity until the dismissal ends', function () {
    Date::setTestNow('2026-11-01 10:00:00');
    $project = Project::factory()->create();
    pushLifecycle($project, ['composer-audit' => [['a/a', 'critical'], ['b/b', 'low']]]);

    app(UpdateFindingDismissalAction::class)->execute(findingFor($project, 'a/a'), User::factory()->create(), 'Not reachable', Date::parse('2026-11-03'));

    $resolving = pushLifecycle($project, ['composer-audit' => [['b/b', 'low']]]);
    $reopening = pushLifecycle($project, ['composer-audit' => [['a/a', 'critical'], ['b/b', 'low']]]);

    expect($resolving->resolved)->toBe([])
        ->and($resolving->worstBefore)->toBe(SeverityEnum::Low)
        ->and($reopening->new)->toBe([])
        ->and($reopening->worstAfter)->toBe(SeverityEnum::Low)
        ->and(findingFor($project, 'a/a')->status)->toBe(FindingStatusEnum::Open);

    // The last day still counts (inclusive, as excubia.json `until`); the day after it does not.
    Date::setTestNow('2026-11-03 23:00:00');
    expect(pushLifecycle($project, ['composer-audit' => [['a/a', 'critical'], ['b/b', 'low']]])->worstAfter)->toBe(SeverityEnum::Low);

    Date::setTestNow('2026-11-04 08:00:00');
    expect(pushLifecycle($project, ['composer-audit' => [['a/a', 'critical'], ['b/b', 'low']]])->worstAfter)->toBe(SeverityEnum::Critical);
});

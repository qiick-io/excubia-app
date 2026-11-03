<?php

declare(strict_types=1);

use App\Models\HealthReport;
use App\Models\Project;
use App\Services\ProjectStalenessService;
use App\Services\SettingsRepositoryService;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;

/**
 * Report history pruning and the stale rule (#7).
 */
function storedReport(Project $project, string $receivedAt): HealthReport
{
    return $project->healthReports()->create([
        'batch_id' => (string) Str::uuid(),
        'received_at' => $receivedAt,
        'generated_at' => $receivedAt,
        'report' => ['schemaVersion' => 1, 'checks' => [], 'upgradePlan' => []],
    ]);
}

it('keeps the newest reports of each project and deletes the rest', function () {
    app(SettingsRepositoryService::class)->setMany(['reports_kept_per_project' => 2]);
    $shop = Project::factory()->create();
    $blog = Project::factory()->create();

    $oldest = storedReport($shop, '2026-11-01 08:00:00');
    $kept = [storedReport($shop, '2026-11-03 08:00:00')->id, storedReport($shop, '2026-11-02 08:00:00')->id];
    $blogReport = storedReport($blog, '2026-10-01 08:00:00');

    $this->artisan('excubia:prune-reports')
        ->expectsOutputToContain('Deleted 1 reports, keeping the newest 2 per project.')
        ->assertSuccessful();

    expect($shop->healthReports()->pluck('id')->sort()->values()->all())->toBe(collect($kept)->sort()->values()->all())
        ->and(HealthReport::query()->find($oldest->id))->toBeNull()
        ->and(HealthReport::query()->find($blogReport->id))->not->toBeNull();
});

it('keeps 30 reports per project by default', function () {
    $project = Project::factory()->create();

    foreach (range(1, 31) as $day) {
        storedReport($project, Date::parse('2026-10-01')->addDays($day)->toDateTimeString());
    }

    $this->artisan('excubia:prune-reports')->assertSuccessful();

    expect($project->healthReports()->count())->toBe(30)
        ->and($project->healthReports()->min('received_at'))->toStartWith('2026-10-03');
});

it('schedules the pruning daily', function () {
    $this->artisan('schedule:list')->expectsOutputToContain('excubia:prune-reports')->assertSuccessful();
});

it('tells fresh, stale and never reported projects apart', function () {
    Date::setTestNow('2026-11-05 12:00:00');
    app(SettingsRepositoryService::class)->setMany(['stale_after_hours' => 36]);
    $staleness = app(ProjectStalenessService::class);

    $fresh = Project::factory()->create(['last_report_at' => '2026-11-04 01:00:00']);
    $stale = Project::factory()->create(['last_report_at' => '2026-11-03 23:00:00']);
    $neverReported = Project::factory()->create(['last_report_at' => null]);

    expect($staleness->isStale($fresh))->toBeFalse()
        ->and($staleness->isStale($stale))->toBeTrue()
        ->and($staleness->isStale($neverReported))->toBeTrue();
});

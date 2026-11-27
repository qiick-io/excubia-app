<?php

declare(strict_types=1);

use App\Actions\PruneRowsAction;
use App\Models\HealthReport;
use App\Models\Project;
use App\Services\ProjectStalenessService;
use App\Services\SettingsRepositoryService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
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

it('allows only one scheduler to start each daily prune and skips overlap', function () {
    $firstScheduler = app(Schedule::class);
    $secondScheduler = new Schedule;
    $time = Date::now()->startOfDay();

    foreach (['excubia:prune-reports', 'excubia:prune-telemetry'] as $command) {
        $event = collect($firstScheduler->events())->sole(fn ($event) => str_ends_with((string) $event->command, $command));

        expect($event->onOneServer)->toBeTrue()
            ->and($firstScheduler->serverShouldRun($event, $time))->toBeTrue()
            ->and($secondScheduler->serverShouldRun($event, $time))->toBeFalse()
            ->and($event->shouldSkipDueToOverlapping())->toBeFalse()
            ->and($event->shouldSkipDueToOverlapping())->toBeTrue();
    }
});

it('bounds each report delete and keeps the newest report when receive times tie', function () {
    app(SettingsRepositoryService::class)->setMany(['reports_kept_per_project' => 1]);
    $project = Project::factory()->create();
    $receivedAt = '2026-11-01 08:00:00';

    foreach (array_chunk(range(1, PruneRowsAction::BATCH_SIZE + 1), 100) as $numbers) {
        DB::table('health_reports')->insert(array_map(fn (int $number): array => [
            'project_id' => $project->id,
            'batch_id' => (string) Str::uuid(),
            'received_at' => $receivedAt,
            'generated_at' => $receivedAt,
            'report' => '{}',
        ], $numbers));
    }

    $latest = storedReport($project, $receivedAt);
    $deleteCount = 0;
    DB::listen(function ($query) use (&$deleteCount): void {
        if (str_starts_with($query->sql, 'delete from') && str_contains($query->sql, 'health_reports')) {
            $deleteCount++;
        }
    });

    $this->artisan('excubia:prune-reports')->assertSuccessful();
    expect($deleteCount)->toBe(2)->and($project->healthReports()->sole()->id)->toBe($latest->id);
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

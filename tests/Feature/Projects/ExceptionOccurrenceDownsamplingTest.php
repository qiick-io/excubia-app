<?php

declare(strict_types=1);

use App\Actions\DownsampleExceptionOccurrencesAction;
use App\Actions\DownsampleRuntimeAction;
use App\Actions\PruneTelemetryAction;
use App\Actions\StorePushedExceptionsAction;
use App\Data\TimeRangeData;
use App\Enums\RoleEnum;
use App\Enums\TimeRangeEnum;
use App\Models\ExceptionGroup;
use App\Models\ExceptionOccurrence;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectTelemetryService;
use App\Services\WeeklyDigestService;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;

/**
 * Downsampling and retention of the exception occurrences (#214): minutes older than the
 * per-minute window fold into one row per group and hour, the Exceptions tab ("In range" counts
 * and each group's chart) and the digest read the same before and after the fold, occurrences go
 * after the 14 d chart window, and the groups keep their totals for the exceptions retention.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    Date::setTestNow('2026-11-08 12:30:00');
    Cache::forget(DownsampleExceptionOccurrencesAction::FOLDED_UNTIL_KEY);
    Cache::forget(DownsampleRuntimeAction::FOLDED_UNTIL_KEY);
    $this->project = Project::factory()->create();
    $this->reader = User::factory()->create()->assignRole(RoleEnum::Reader->value);
});

/**
 * Pushes one exceptions batch a minute from `$from` for `$minutes` minutes, every `$step`-th
 * minute: group `a` every time, group `b` in every third pushed minute, counts varied by minute.
 */
function pushOccurrenceMinutes(Project $project, CarbonImmutable $from, int $minutes, int $step = 1, string $release = 'v1.2.0'): void
{
    for ($offset = 0; $offset < $minutes; $offset += $step) {
        $at = $from->addMinutes($offset);
        $groups = [occurrenceGroup('a', 1 + $offset % 5, $at)];

        if (intdiv($offset, $step) % 3 === 0) {
            $groups[] = occurrenceGroup('b', 2, $at);
        }

        app(StorePushedExceptionsAction::class)->execute($project, (string) Str::uuid(), $groups, $release);
    }
}

/**
 * An exceptions group as excubia-core pushes it, seen in the minute `$at`.
 *
 * @return array<string, mixed>
 */
function occurrenceGroup(string $fingerprint, int $count, CarbonImmutable $at): array
{
    return [
        'class' => 'RuntimeException',
        'fingerprint' => str_repeat($fingerprint, 64),
        'message' => 'Payment failed',
        'frames' => [],
        'previous' => [],
        'context' => ['kind' => 'request', 'name' => 'checkout.store'],
        'environment' => 'production',
        'count' => $count,
        'firstSeen' => $at->addSeconds(5)->toIso8601ZuluString(),
        'lastSeen' => $at->addSeconds(50)->toIso8601ZuluString(),
    ];
}

/**
 * The Exceptions tab over every range (groups, "In range" counts, charts), the stored groups and
 * the digest, as their readers return them.
 *
 * @return array<string, mixed>
 */
function occurrenceViews(Project $project, User $reader): array
{
    $views = [];

    foreach (TimeRangeEnum::cases() as $range) {
        $views[$range->value] = app(ProjectTelemetryService::class)->exceptions($project->refresh(), TimeRangeData::fromPreset($range, CarbonImmutable::instance(Date::now())));
    }

    $views['groups'] = ExceptionGroup::query()->orderBy('id')->get(['count', 'first_seen_at', 'last_seen_at', 'first_release', 'last_release'])->toArray();
    $views['digest'] = app(WeeklyDigestService::class)->forUser($reader);

    return $views;
}

it('folds old occurrences into one row per group and hour with the same counts and charts', function () {
    // Two whole hours and a partial one three days ago, a sparse hour ten days ago, and the
    // last hour, which stays in minutes.
    pushOccurrenceMinutes($this->project, CarbonImmutable::parse('2026-11-05 09:00:00'), 150);
    pushOccurrenceMinutes($this->project, CarbonImmutable::parse('2026-10-29 17:05:00'), 50, 7);
    pushOccurrenceMinutes($this->project, CarbonImmutable::parse('2026-11-08 11:30:00'), 60);

    $before = occurrenceViews($this->project, $this->reader);
    $rowsBefore = ExceptionOccurrence::query()->count();
    $recentRows = ExceptionOccurrence::query()->where('bucket', '>=', '2026-11-06 12:00:00')->count();

    $folded = app(DownsampleExceptionOccurrencesAction::class)->execute(CarbonImmutable::now()->subHours(48));

    expect($folded)->toBeGreaterThan(0)
        ->and(ExceptionOccurrence::query()->count())->toBe($rowsBefore - $folded)
        // Two groups over four hours: one row per group and hour.
        ->and(ExceptionOccurrence::query()->where('bucket', '<', '2026-11-06 12:00:00')->count())->toBe(4 * 2)
        ->and(ExceptionOccurrence::query()->where('bucket', '>=', '2026-11-06 12:00:00')->count())->toBe($recentRows)
        ->and($before['14d'][0]['range_count'])->toBeGreaterThan($before['24h'][0]['range_count'])
        ->and($before['digest'])->not->toBeNull()
        ->and(occurrenceViews($this->project, $this->reader))->toEqual($before);

    // The hour keeps its latest minute, and re-running folds nothing and changes nothing.
    expect(ExceptionOccurrence::query()->where('bucket', '>=', '2026-11-05 09:00:00')->where('bucket', '<', '2026-11-05 10:00:00')->max('bucket'))->toStartWith('2026-11-05 09:59:00')
        ->and(app(DownsampleExceptionOccurrencesAction::class)->execute(CarbonImmutable::now()->subHours(48)))->toBe(0)
        ->and(occurrenceViews($this->project, $this->reader))->toEqual($before);
});

it('keeps the occurrences of the per-minute window and of the hour the cutoff falls in', function () {
    pushOccurrenceMinutes($this->project, CarbonImmutable::parse('2026-11-06 12:00:00'), 60, 10);
    $rows = ExceptionOccurrence::query()->count();

    expect(app(DownsampleExceptionOccurrencesAction::class)->execute(CarbonImmutable::now()->subHours(48)))->toBe(0)
        ->and(ExceptionOccurrence::query()->count())->toBe($rows);
});

it('folds a late push into an hour folded before, within a day of the last run', function () {
    pushOccurrenceMinutes($this->project, CarbonImmutable::parse('2026-11-06 10:00:00'), 60, 10);
    app(DownsampleExceptionOccurrencesAction::class)->execute(CarbonImmutable::now()->subHours(48));

    pushOccurrenceMinutes($this->project, CarbonImmutable::parse('2026-11-06 10:05:00'), 1);
    $before = occurrenceViews($this->project, $this->reader);

    // Both groups got a late minute next to their folded hour.
    expect(app(DownsampleExceptionOccurrencesAction::class)->execute(CarbonImmutable::now()->subHours(48)))->toBe(2)
        ->and(ExceptionOccurrence::query()->count())->toBe(2)
        ->and(occurrenceViews($this->project, $this->reader))->toEqual($before);
});

it('folds the occurrences with the runtime minutes in the hourly command', function () {
    pushOccurrenceMinutes($this->project, CarbonImmutable::parse('2026-11-07 08:00:00'), 60, 10);

    config(['excubia.runtime_minute_hours' => 48]);
    $this->artisan('excubia:downsample-runtime')->expectsOutputToContain('Folded 0 exception occurrence minutes older than 48 hours')->assertSuccessful();

    // Six minutes of group `a` and two of group `b` fold into one row each.
    config(['excubia.runtime_minute_hours' => 1]);
    $this->artisan('excubia:downsample-runtime')->expectsOutputToContain('Folded 6 exception occurrence minutes older than 24 hours')->assertSuccessful();
});

it('prunes occurrences after the chart window while the groups keep their totals and releases', function () {
    pushOccurrenceMinutes($this->project, CarbonImmutable::parse('2026-10-20 09:00:00'), 3, 1, 'v1.0.0');
    pushOccurrenceMinutes($this->project, CarbonImmutable::parse('2026-11-07 09:00:00'), 3, 1, 'v1.1.0');
    $groups = ExceptionGroup::query()->orderBy('id')->get(['count', 'first_seen_at', 'last_seen_at', 'first_release', 'last_release'])->toArray();

    config(['excubia.exception_occurrence_days' => 1]);
    $this->artisan('excubia:prune-telemetry')->assertSuccessful();

    // At least the 14 d range is kept, so only the October minutes go.
    expect(ExceptionOccurrence::query()->where('bucket', '<', '2026-11-01 00:00:00')->count())->toBe(0)
        ->and(ExceptionOccurrence::query()->count())->toBe(3 + 1)
        ->and(ExceptionGroup::query()->orderBy('id')->get(['count', 'first_seen_at', 'last_seen_at', 'first_release', 'last_release'])->toArray())->toBe($groups)
        ->and($groups[0])->toMatchArray(['count' => 1 + 2 + 3 + 1 + 2 + 3, 'first_release' => 'v1.0.0', 'last_release' => 'v1.1.0']);

    // A longer occurrence window never outlives the exceptions retention.
    pushOccurrenceMinutes($this->project, CarbonImmutable::parse('2026-10-20 09:00:00'), 1);
    app(PruneTelemetryAction::class)->execute(14, 30, 60);

    expect(ExceptionOccurrence::query()->where('bucket', '<', '2026-11-01 00:00:00')->count())->toBe(2);

    app(PruneTelemetryAction::class)->execute(14, 10, 60);

    expect(ExceptionOccurrence::query()->where('bucket', '<', '2026-11-01 00:00:00')->count())->toBe(0)
        ->and(ExceptionGroup::query()->count())->toBe(2);
});

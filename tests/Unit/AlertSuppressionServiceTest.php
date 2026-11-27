<?php

declare(strict_types=1);

use App\Models\MaintenanceWindow;
use App\Services\AlertSuppressionService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * Maintenance windows (#114): one-off periods, weekly ones in their own timezone (DST included)
 * and weekly ones that run past midnight.
 */
uses(TestCase::class);

/**
 * @param  array<string, mixed>  $attributes
 */
function weeklyWindow(array $attributes = []): MaintenanceWindow
{
    return new MaintenanceWindow([
        'name' => 'Nightly deploy',
        'recurrence' => MaintenanceWindow::WEEKLY,
        'weekdays' => [2],
        'start_time' => '02:00',
        'duration_minutes' => 60,
        'timezone' => 'Europe/Rome',
        ...$attributes,
    ]);
}

function isActiveAt(MaintenanceWindow $window, string $utc): bool
{
    return app(AlertSuppressionService::class)->isWindowActive($window, CarbonImmutable::parse($utc, 'UTC'));
}

it('covers a one-off window from its start to its end, end excluded', function () {
    $window = new MaintenanceWindow([
        'recurrence' => MaintenanceWindow::ONCE,
        'starts_at' => '2026-11-10 08:00:00',
        'ends_at' => '2026-11-10 09:00:00',
        'timezone' => 'UTC',
    ]);

    expect(isActiveAt($window, '2026-11-10 07:59:59'))->toBeFalse()
        ->and(isActiveAt($window, '2026-11-10 08:00:00'))->toBeTrue()
        ->and(isActiveAt($window, '2026-11-10 08:59:59'))->toBeTrue()
        ->and(isActiveAt($window, '2026-11-10 09:00:00'))->toBeFalse();
});

it('reads a weekly window in its timezone', function () {
    // Tuesday 02:00 in Rome is 01:00 UTC in winter (CET, +1).
    $window = weeklyWindow();

    expect(isActiveAt($window, '2026-11-10 00:59:00'))->toBeFalse()
        ->and(isActiveAt($window, '2026-11-10 01:00:00'))->toBeTrue()
        ->and(isActiveAt($window, '2026-11-10 01:59:00'))->toBeTrue()
        ->and(isActiveAt($window, '2026-11-10 02:00:00'))->toBeFalse()
        // Wednesday at the same time: not one of its days.
        ->and(isActiveAt($window, '2026-11-11 01:30:00'))->toBeFalse();
});

it('follows the daylight saving time of its timezone', function () {
    // In summer (CEST, +2) Tuesday 02:00 in Rome is 00:00 UTC.
    $window = weeklyWindow();

    expect(isActiveAt($window, '2026-07-07 00:30:00'))->toBeTrue()
        ->and(isActiveAt($window, '2026-07-07 01:30:00'))->toBeFalse();

    // New York, Sunday 2026-03-08 is the switch to EDT: 09:00 local is 13:00 UTC, a week before 14:00 UTC.
    $newYork = weeklyWindow(['weekdays' => [7], 'start_time' => '09:00', 'timezone' => 'America/New_York']);

    expect(isActiveAt($newYork, '2026-03-01 14:30:00'))->toBeTrue()
        ->and(isActiveAt($newYork, '2026-03-08 13:30:00'))->toBeTrue()
        ->and(isActiveAt($newYork, '2026-03-08 14:30:00'))->toBeFalse();
});

it('runs a weekly window past midnight into the next day', function () {
    // Sunday 23:00 for 3 hours in Tokyo (+9): Sunday 14:00 to 17:00 UTC.
    $window = weeklyWindow(['weekdays' => [7], 'start_time' => '23:00', 'duration_minutes' => 180, 'timezone' => 'Asia/Tokyo']);

    expect(isActiveAt($window, '2026-11-08 13:59:00'))->toBeFalse()
        ->and(isActiveAt($window, '2026-11-08 14:00:00'))->toBeTrue()
        // Monday 01:30 in Tokyo, still inside Sunday's window.
        ->and(isActiveAt($window, '2026-11-08 16:30:00'))->toBeTrue()
        ->and(isActiveAt($window, '2026-11-08 17:00:00'))->toBeFalse();
});

it('covers every listed weekday and nothing without days', function () {
    $window = weeklyWindow(['weekdays' => [1, 2, 3, 4, 5, 6, 7], 'timezone' => 'UTC']);

    expect(isActiveAt($window, '2026-11-12 02:30:00'))->toBeTrue()
        ->and(isActiveAt(weeklyWindow(['weekdays' => []]), '2026-11-10 01:30:00'))->toBeFalse();
});

<?php

declare(strict_types=1);

use App\Services\SettingsRepositoryService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Date;
use Spatie\Activitylog\Models\Activity;

/**
 * Data retention (#94): the daily activity log clean reads the setting when it runs.
 */
it('cleans the activity log with the Data retention setting', function () {
    Date::setTestNow('2026-11-05 12:00:00');
    app(SettingsRepositoryService::class)->setMany(['activity_retention_days' => 30]);

    activity()->log('recent');
    Activity::query()->latest('id')->first()->forceFill(['created_at' => now()->subDays(10)])->save();
    activity()->log('old');
    Activity::query()->latest('id')->first()->forceFill(['created_at' => now()->subDays(40)])->save();

    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => $event->description === 'activitylog:clean');

    expect($event)->not->toBeNull();

    $event->run(app());

    expect(Activity::query()->pluck('description')->all())->toBe(['recent']);
});

<?php

declare(strict_types=1);

use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;

/**
 * Daily expiry warning (#351): keys whose expires_at falls in [now+7d, now+8d].
 */
it('skips revoked keys and keys outside the warning window', function () {
    Notification::fake();
    Date::setTestNow('2026-11-17 10:00:00');
    $user = User::factory()->create();

    ApiKey::factory()->for($user, 'owner')->create([
        'created_by' => $user->id,
        'expires_at' => now()->addDays(7)->addHours(6),
        'revoked_at' => now(),
    ]);
    ApiKey::factory()->for($user, 'owner')->create([
        'created_by' => $user->id,
        'expires_at' => null,
    ]);

    $this->artisan('excubia:notify-expiring-api-keys')->assertSuccessful();

    Notification::assertNothingSent();
});

it('is registered on the daily schedule', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains((string) $event->command, 'excubia:notify-expiring-api-keys'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 0 * * *');
});

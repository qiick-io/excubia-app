<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use App\Models\Activity;
use App\Models\User;
use Database\Seeders\PermissionSeeder;

/**
 * Dates follow the app language and the user's timezone, not the browser's (#428).
 */
beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
});

it('formats dates in the app language and the user timezone, whatever the browser says', function () {
    $admin = User::factory()->create(['locale' => 'en', 'timezone' => 'Europe/Rome'])->assignRole(RoleEnum::SuperAdmin->value);
    $this->actingAs($admin);
    activity()->causedBy($admin)->log('Browser check');
    Activity::query()->update(['created_at' => '2026-11-26 19:29:28']);

    // An Italian browser in New York: the page still says English and Rome time (20:29).
    visit(route('activity-logs.index', absolute: false))
        ->withLocale('it-IT')
        ->withTimezone('America/New_York')
        ->assertSee('Nov 26, 2026, 8:29 PM')
        ->assertDontSee('26/11/2026')
        ->assertNoJavaScriptErrors();
});

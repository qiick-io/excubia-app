<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use App\Models\User;
use App\Services\SettingsRepositoryService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
});

function settingsPayload(array $overrides = []): array
{
    return [
        'app_name' => 'Acme Health',
        'password_policy' => 'medium',
        'login_max_attempts' => 3,
        'two_factor_required' => false,
        'reports_kept_per_project' => 10,
        'stale_after_hours' => 12,
        ...$overrides,
    ];
}

function settingsManager(): User
{
    return User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value);
}

it('keeps the settings page behind settings.manage', function (RoleEnum $role) {
    $user = User::factory()->create()->assignRole($role->value);

    $this->actingAs($user)->get(route('application-settings.edit'))->assertForbidden();
    $this->actingAs($user)->put(route('application-settings.update'), settingsPayload())->assertForbidden();
})->with([RoleEnum::Admin, RoleEnum::Reader]);

it('shows defaults from config until a value is saved', function () {
    config(['excubia.reports_kept_per_project' => 30, 'excubia.stale_after_hours' => 36]);

    $this->actingAs(settingsManager())->get(route('application-settings.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/application')
            ->where('settings.reports_kept_per_project', 30)
            ->where('settings.stale_after_hours', 36)
            ->where('settings.login_max_attempts', 5)
            ->where('settings.two_factor_required', false));
});

it('saves the settings and the app uses them', function () {
    $this->actingAs(settingsManager())
        ->put(route('application-settings.update'), settingsPayload())
        ->assertRedirect(route('application-settings.edit'));

    $settings = app(SettingsRepositoryService::class);

    expect($settings->appName())->toBe('Acme Health')
        ->and($settings->passwordPolicy())->toBe('medium')
        ->and($settings->loginMaxAttempts())->toBe(3)
        ->and($settings->reportsKeptPerProject())->toBe(10)
        ->and($settings->staleAfterHours())->toBe(12);

    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('name', 'Acme Health'));

    expect(validator(['password' => 'alllowercase'], ['password' => Password::default()])->fails())->toBeTrue()
        ->and(validator(['password' => 'Mixed1Case'], ['password' => Password::default()])->passes())->toBeTrue();
});

it('validates the settings', function () {
    $this->actingAs(settingsManager())
        ->put(route('application-settings.update'), settingsPayload([
            'password_policy' => 'none',
            'login_max_attempts' => 0,
            'reports_kept_per_project' => 'many',
            'stale_after_hours' => 9999,
        ]))
        ->assertSessionHasErrors(['password_policy', 'login_max_attempts', 'reports_kept_per_project', 'stale_after_hours']);
});

it('uses the saved login attempts for the login rate limit', function () {
    app(SettingsRepositoryService::class)->setMany(['login_max_attempts' => 2]);
    $user = User::factory()->create();

    RateLimiter::increment(md5('login'.implode('|', [$user->email, '127.0.0.1'])), amount: 2);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertTooManyRequests();
});

it('sends users without 2FA to their security page when 2FA is required', function () {
    app(SettingsRepositoryService::class)->setMany(['two_factor_required' => true]);

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertRedirect(route('security.edit'));

    $this->actingAs(User::factory()->withTwoFactor()->create())
        ->get(route('dashboard'))
        ->assertOk();
});

<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\SettingsRepositoryService;
use Illuminate\Support\Facades\Http;
use Laravel\Prompts\Prompt;

/**
 * The CLI uses the same initial-admin password gate as the seeder: a known default or a weak
 * password can never be used to bootstrap a server, even by an operator at the console.
 */
beforeEach(function () {
    Http::fake();

    // Simulating a server makes Laravel's test harness treat the command as non-interactive.
    Prompt::fallbackWhen(true);
});

it('refuses the known default password from excubia:create-user on a server', function (string $password) {
    app()['env'] = 'production';
    app(SettingsRepositoryService::class)->save(['password_policy' => 'strong']);

    $this->artisan('excubia:create-user', [
        '--first-name' => 'Ada',
        '--last-name' => 'Lovelace',
        '--email' => 'ada@example.com',
    ])
        ->expectsQuestion('Password', $password)
        ->expectsChoice('Role', 'super-admin', ['admin', 'reader', 'super-admin'])
        ->expectsOutputToContain('must not be the known default')
        ->assertFailed();

    expect(User::query()->exists())->toBeFalse();
})->with(['password', 'PASSWORD']);

it('refuses a weak password from excubia:create-user on a server', function () {
    app()['env'] = 'production';
    app(SettingsRepositoryService::class)->save(['password_policy' => 'strong']);

    $this->artisan('excubia:create-user', [
        '--first-name' => 'Ada',
        '--last-name' => 'Lovelace',
        '--email' => 'ada@example.com',
    ])
        ->expectsQuestion('Password', 'short')
        ->expectsChoice('Role', 'super-admin', ['admin', 'reader', 'super-admin'])
        ->expectsOutputToContain('does not meet the password policy')
        ->assertFailed();

    expect(User::query()->exists())->toBeFalse();
});

it('accepts a strong password from excubia:create-user on a server', function () {
    app()['env'] = 'production';
    app(SettingsRepositoryService::class)->save(['password_policy' => 'strong']);

    $this->artisan('excubia:create-user', [
        '--first-name' => 'Ada',
        '--last-name' => 'Lovelace',
        '--email' => 'ada@example.com',
    ])
        ->expectsQuestion('Password', 'V3ry-Str0ng-Passphrase-2026')
        ->expectsChoice('Role', 'super-admin', ['admin', 'reader', 'super-admin'])
        ->assertSuccessful();

    expect(User::query()->where('email', 'ada@example.com')->exists())->toBeTrue();
});

<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Passkeys\Passkeys;

it('sends guests to the login page', function (string $path) {
    $this->followingRedirects()
        ->get($path)
        ->assertInertia(fn (Assert $page) => $page->component('auth/login'));
})->with(['/', '/dashboard']);

it('creates a user from the CLI who can log in', function () {
    $this->artisan('excubia:create-user')
        ->expectsQuestion('First name', 'Ada')
        ->expectsQuestion('Last name', 'Lovelace')
        ->expectsQuestion('Email', 'Ada@Example.com')
        ->expectsQuestion('Password', 'correct-horse-battery')
        ->expectsChoice('Role', 'super-admin', ['admin', 'reader', 'super-admin'])
        ->assertSuccessful();

    $user = User::query()->where('email', 'ada@example.com')->firstOrFail();

    expect($user->is_active)->toBeTrue()
        ->and($user->hasVerifiedEmail())->toBeTrue();

    $this->post(route('login.store'), [
        'email' => 'ada@example.com',
        'password' => 'correct-horse-battery',
    ])->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
});

it('refuses a duplicate email from the CLI', function () {
    User::factory()->create(['email' => 'ada@example.com']);

    $this->artisan('excubia:create-user', ['--first-name' => 'Ada', '--last-name' => 'Lovelace', '--email' => 'ada@example.com'])
        ->expectsQuestion('Password', 'correct-horse-battery')
        ->expectsChoice('Role', 'reader', ['admin', 'reader', 'super-admin'])
        ->assertFailed();

    expect(User::query()->count())->toBe(1);
});

it('refuses an inactive user with a password', function () {
    $user = User::factory()->inactive()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('refuses an inactive user with two-factor enabled', function () {
    $user = User::factory()->inactive()->withTwoFactor()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
    expect(session('login.id'))->toBeNull();
});

it('refuses an inactive user with a passkey', function () {
    $active = User::factory()->create();
    $inactive = User::factory()->inactive()->create();

    $passkeyFor = fn (User $user) => $user->passkeys()->create([
        'name' => 'Laptop',
        'credential_id' => 'credential-'.$user->id,
        'credential' => [],
    ]);

    expect(Passkeys::allowsLogin(new Request, $passkeyFor($active)))->toBeTrue()
        ->and(Passkeys::allowsLogin(new Request, $passkeyFor($inactive)))->toBeFalse();
});

<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Session;

beforeEach(function () {
    config(['session.driver' => 'database', 'session.lifetime' => 48 * 60]);

    // Each request authenticates only from the persisted session or cookies it sends.
    app()->terminating(function (): void {
        Auth::forgetGuards();
        Session::forgetDrivers();
        Cookie::flushQueuedCookies();
        app()->forgetInstance('auth.driver');
        app()->forgetInstance('session.store');
    });
});

it('expires an active browser session exactly one day after authentication', function () {
    $user = User::factory()->create();
    $signedInAt = Date::now()->startOfSecond();
    $this->travelTo($signedInAt);
    $sessionCookieName = Config::string('session.cookie');
    $login = $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));
    $activeSession = $login->getCookie($sessionCookieName, decrypt: false);
    expect($activeSession)->not->toBeNull();
    $dashboard = route('dashboard');

    $this->travelTo($signedInAt->addDay()->subSecond());
    $this->call('GET', $dashboard, cookies: [$sessionCookieName => $activeSession->getValue()])->assertOk();

    $this->travelTo($signedInAt->addDay());
    $this->call('GET', $dashboard, cookies: [$sessionCookieName => $activeSession->getValue()])->assertRedirect(route('login'));
    $this->call('GET', $dashboard, cookies: [$sessionCookieName => $activeSession->getValue()])->assertRedirect(route('login'));

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect($dashboard);
});

it('restores a remembered session only until the original authentication deadline', function () {
    $user = User::factory()->create();
    $signedInAt = Date::now()->startOfSecond();
    $this->travelTo($signedInAt);
    /** @var SessionGuard $guard */
    $guard = Auth::guard('web');
    $rememberCookieName = $guard->getRecallerName();
    $sessionCookieName = Config::string('session.cookie');
    $dashboard = route('dashboard');
    $login = $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password', 'remember' => true])
        ->assertRedirect(route('dashboard', absolute: false));
    $rememberCookie = $login->getCookie($rememberCookieName, decrypt: false);
    expect($rememberCookie?->getExpiresTime())->toBe($signedInAt->addDay()->getTimestamp());
    $rememberedBrowserCookies = [$rememberCookieName => $rememberCookie->getValue()];

    $this->travelTo($signedInAt->addHours(12));
    $restored = $this->call('GET', $dashboard, cookies: $rememberedBrowserCookies)->assertOk();
    $restoredSession = $restored->getCookie($sessionCookieName, decrypt: false);
    expect($restoredSession)->not->toBeNull();

    $this->travelTo($signedInAt->addDay());
    $this->call('GET', $dashboard, cookies: [...$rememberedBrowserCookies, $sessionCookieName => $restoredSession->getValue()])
        ->assertRedirect(route('login'));
    $this->call('GET', $dashboard, cookies: $rememberedBrowserCookies)->assertRedirect(route('login'));
});

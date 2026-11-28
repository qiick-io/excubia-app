<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

it('keeps two browser sessions independent after sign-in and sign-out', function () {
    config(['session.driver' => 'database']);

    // Pest serves both browsers from one container. Clear in-memory state after each response so the next request uses its own cookie.
    app()->terminating(function (): void {
        Auth::forgetGuards();
        Session::forgetDrivers();
        app()->forgetInstance('auth.driver');
        app()->forgetInstance('session.store');
    });

    $user = User::factory()->create();
    $dashboard = route('dashboard', absolute: false);
    $login = route('login', absolute: false);

    $firstBrowser = visit($login)->inLightMode()
        ->type('email', $user->email)
        ->type('password', 'password')
        ->press('Log in')
        ->assertPathIs($dashboard);

    $secondBrowser = visit($login)->inDarkMode()
        ->type('email', $user->email)
        ->type('password', 'password')
        ->press('Log in')
        ->assertPathIs($dashboard);

    $firstBrowser->navigate($dashboard)
        ->assertPathIs($dashboard)
        ->click('[data-test="sidebar-menu-button"]')
        ->click('[data-test="logout-button"]')
        ->assertPathIs($login)
        ->navigate($dashboard)
        ->assertPathIs($login);

    $secondBrowser->navigate($dashboard)
        ->assertPathIs($dashboard)
        ->assertNoJavaScriptErrors();
});

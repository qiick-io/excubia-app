<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use App\Models\User;
use Database\Seeders\PermissionSeeder;

require_once __DIR__.'/BrowserSeedHelpers.php';

/**
 * WCAG 2.2 AA flow evidence for authentication assistance, reused entry and consistent help.
 * The target-size assertion runs in the existing axe smoke with the shared script.
 */
beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
});

it('supports password paste and password-manager autocomplete at sign-in', function () {
    visit('/login')
        ->type('email', 'browser-test@example.test')
        ->keys('#email', ['ControlOrMeta+a', 'ControlOrMeta+c'])
        ->keys('#password', 'ControlOrMeta+v')
        ->assertValue('#password', 'browser-test@example.test')
        ->assertAttribute('#password', 'autocomplete', 'current-password');
});

it('supports pasting an authentication code into the two-factor challenge', function () {
    $user = User::factory()->withTwoFactor()->create();

    visit('/login')
        // Copy known digits before signing in so paste exercises the real browser event.
        ->type('email', '123456')
        ->keys('#email', ['ControlOrMeta+a', 'ControlOrMeta+c'])
        ->type('email', $user->email)
        ->type('password', 'password')
        ->press('Log in')
        ->assertPresent('input[name=code]')
        ->keys('input[name=code]', 'ControlOrMeta+v')
        ->assertValue('input[name=code]', '123456');
});

it('reuses the recovery email on the reset form', function () {
    visit('/reset-password/browser-test-token?email=browser-test%40example.test')
        ->assertValue('input[name=email]', 'browser-test@example.test');
});

it('opens keyboard help consistently across pages in the authenticated shell', function () {
    $this->actingAs(User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value));
    seededA11yProject();

    foreach (['dashboard', 'profile.edit'] as $routeName) {
        visit(route($routeName, absolute: false))
            ->keys('#main-content', 'Shift+Slash')
            ->assertSeeIn('[role=dialog] [data-slot=dialog-title]', 'Keyboard shortcuts');
    }
});

<?php

declare(strict_types=1);

/**
 * EXCUBIA_MAINTENANCE_SECRET keeps the app in maintenance from its environment, so a deploy (which
 * resets the filesystem of `artisan down`) and a new database (which drops a cache-based flag)
 * still come up closed. Only the bypass path of the secret opens it, as `artisan down --secret`.
 */
it('keeps every request out while the secret is set, except through its bypass', function () {
    config(['excubia.maintenance_secret' => 'cutover-0f8c2a7d4e']);

    $this->get('/login')->assertServiceUnavailable();
    $this->postJson('/api/reports')->assertServiceUnavailable();
    // The probes answer, so the platform keeps the closed deploy running.
    $this->get('/health/live')->assertOk();
    expect(app()->isDownForMaintenance())->toBeTrue();

    $bypass = $this->get('/cutover-0f8c2a7d4e')->assertRedirect('/');
    $cookie = collect($bypass->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === 'laravel_maintenance');

    expect($cookie)->not->toBeNull();

    $this->withUnencryptedCookie('laravel_maintenance', $cookie->getValue())->get('/login')->assertOk();
});

it('is live without the secret', function () {
    config(['excubia.maintenance_secret' => null]);

    $this->get('/login')->assertOk();
    expect(app()->isDownForMaintenance())->toBeFalse();
});

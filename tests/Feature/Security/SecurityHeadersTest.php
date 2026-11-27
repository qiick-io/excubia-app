<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Inertia\Inertia;

/**
 * Baseline security headers on web responses (#153).
 */
beforeEach(function () {
    $this->withoutVite();
    $this->seed(PermissionSeeder::class);
});

it('sends the anti-framing and baseline headers on guest and signed-in pages', function () {
    $user = User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value);

    foreach ([$this->get(route('login')), $this->actingAs($user)->get(route('dashboard')), $this->actingAs($user)->get(route('projects.index'))] as $response) {
        $response->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()')
            ->assertHeaderMissing('Strict-Transport-Security');
    }
});

it('sends the headers on redirects too', function () {
    $this->get(route('dashboard'))->assertRedirect()->assertHeader('X-Frame-Options', 'DENY');
});

it('prevents browser caching of session pages, Inertia payloads and error responses', function () {
    $this->get(route('login'))->assertOk()->assertHeaderContains('Cache-Control', 'no-store');

    $user = User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value);
    $this->actingAs($user);

    $this->get(route('dashboard'))->assertOk()->assertHeaderContains('Cache-Control', 'no-store');
    $this->get(route('dashboard'), ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) Inertia::getVersion()])
        ->assertOk()
        ->assertHeader('X-Inertia', 'true')
        ->assertHeaderContains('Cache-Control', 'no-store');
    $this->get('/missing-page')->assertNotFound()->assertHeaderContains('Cache-Control', 'no-store');
});

it('prevents browser caching of authenticated JSON', function () {
    $user = User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value);

    $this->actingAs($user)->getJson(route('notifications.index'))
        ->assertOk()
        ->assertHeaderContains('Cache-Control', 'no-store');
});

it('sends HSTS only on https requests and only when configured', function () {
    $this->get('https://localhost/login')->assertOk()->assertHeaderMissing('Strict-Transport-Security');

    config(['excubia.hsts_max_age' => 31536000]);

    $this->get('http://localhost/login')->assertOk()->assertHeaderMissing('Strict-Transport-Security');
    $this->get('https://localhost/login')->assertOk()->assertHeader('Strict-Transport-Security', 'max-age=31536000');
});

it('sends HSTS behind a TLS proxy it does not trust when APP_URL is https', function () {
    // #478: Laravel Cloud and similar proxies reach the app over http without TRUSTED_PROXIES.
    config(['excubia.hsts_max_age' => 31536000, 'app.url' => 'https://excubia.example']);

    $this->get('http://localhost/login')->assertOk()->assertHeader('Strict-Transport-Security', 'max-age=31536000');
});

it('keeps browser-only headers off the API and the health check', function () {
    $this->get('/up')->assertOk()->assertHeaderMissing('X-Frame-Options');
    $this->postJson('/api/reports')->assertUnauthorized()
        ->assertHeaderMissing('X-Frame-Options')
        ->assertHeaderContains('Cache-Control', 'no-store');
});

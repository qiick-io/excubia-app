<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Http\Middleware\TrustProxies;
use Inertia\Inertia;
use Qiick\HttpBasics\Security\SecurityHeadersPolicy;

/**
 * Security headers (#153) through qiick/laravel-http-basics, configured in config/http-basics.php.
 */
beforeEach(function () {
    $this->withoutVite();
    $this->seed(PermissionSeeder::class);
});

afterEach(function () {
    TrustProxies::flushState();
});

/** The package reads its policy once into a singleton: rebuild it after a config change. */
function hstsMaxAge(int $seconds): void
{
    config(['http-basics.security.hsts.max_age' => $seconds]);
    app()->forgetInstance(SecurityHeadersPolicy::class);
}

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

    hstsMaxAge(31536000);

    $this->get('http://localhost/login')->assertOk()->assertHeaderMissing('Strict-Transport-Security');
    $this->get('https://localhost/login')->assertOk()->assertHeader('Strict-Transport-Security', 'max-age=31536000');
});

it('sends HSTS behind a trusted TLS proxy, and not when the proxy is untrusted', function () {
    // #478: the forwarded scheme counts only from a trusted proxy (TRUSTED_PROXIES).
    hstsMaxAge(31536000);
    config(['app.url' => 'https://excubia.example']);
    $forwardedHttps = ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '443'];

    $this->get('http://localhost/login', $forwardedHttps)->assertOk()->assertHeaderMissing('Strict-Transport-Security');

    TrustProxies::at('*');

    $this->get('http://localhost/login', $forwardedHttps)->assertOk()->assertHeader('Strict-Transport-Security', 'max-age=31536000');
});

it('sends HSTS behind the Laravel Cloud load balancer without TRUSTED_PROXIES', function () {
    hstsMaxAge(31536000);
    $_SERVER['LARAVEL_CLOUD'] = '1';

    try {
        $this->get('http://localhost/login', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '443'])
            ->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    } finally {
        unset($_SERVER['LARAVEL_CLOUD']);
    }
});

it('gives every response a request id, an error and a machine door too', function () {
    $this->get('/no-such-page')->assertNotFound()->assertHeader('X-Request-Id');
    $this->postJson('/api/reports')->assertUnauthorized()->assertHeader('X-Request-Id');
});

it('keeps browser-only headers off the API and the health check', function () {
    $this->get('/up')->assertOk()->assertHeaderMissing('X-Frame-Options');
    // The API keeps only the baseline headers: no framing rule for JSON.
    $this->postJson('/api/reports')->assertUnauthorized()
        ->assertHeaderMissing('X-Frame-Options')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeaderContains('Cache-Control', 'no-store');
});

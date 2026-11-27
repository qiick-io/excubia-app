<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Http\Middleware\TrustProxies;
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

it('leaves the API and the health check as they were', function () {
    $this->get('/up')->assertOk();
    // The API keeps only the baseline headers: no framing rule for JSON.
    $this->postJson('/api/reports')->assertUnauthorized()->assertHeaderMissing('X-Frame-Options')->assertHeader('X-Content-Type-Options', 'nosniff');
});

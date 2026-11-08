<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use App\Models\User;
use Database\Seeders\PermissionSeeder;

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

it('sends HSTS only on https requests and only when configured', function () {
    $this->get('https://localhost/login')->assertOk()->assertHeaderMissing('Strict-Transport-Security');

    config(['excubia.hsts_max_age' => 31536000]);

    $this->get('http://localhost/login')->assertOk()->assertHeaderMissing('Strict-Transport-Security');
    $this->get('https://localhost/login')->assertOk()->assertHeader('Strict-Transport-Security', 'max-age=31536000');
});

it('leaves the API and the health check as they were', function () {
    $this->get('/up')->assertOk();
    $this->postJson('/api/reports')->assertUnauthorized()->assertHeaderMissing('X-Frame-Options');
});

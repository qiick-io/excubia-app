<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Inertia\Testing\AssertableInertia as Assert;

it('shares the default locale and the available locales', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('locale', 'en')
            ->where('availableLocales', ['en' => 'English', 'it' => 'Italiano', 'de' => 'Deutsch']));
});

it('saves the locale on the user and applies it to the next request', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('dashboard'))
        ->patch(route('locale.update'), ['locale' => 'it'])
        ->assertRedirect(route('dashboard'))
        ->assertCookie('locale', 'it', false);

    expect($user->fresh()->locale)->toBe('it');

    $this->actingAs($user->fresh())
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'it'));
});

it('rejects a locale that is not available', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('locale.update'), ['locale' => 'fr'])
        ->assertSessionHasErrors('locale');

    expect($user->fresh()->locale)->toBe('en');
});

it('uses the locale cookie for guests', function () {
    $this->withUnencryptedCookie('locale', 'de')
        ->get(route('login'))
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'de'));
});

it('shares the role names and the super-admin flag', function () {
    $this->seed(PermissionSeeder::class);
    $user = User::factory()->create()->assignRole('super-admin');

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.roleNames', ['super-admin'])
            ->where('auth.isSuperAdmin', true));
});

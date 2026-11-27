<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use App\Models\User;
use Database\Seeders\PermissionSeeder;

/**
 * Overlays opened without a Radix trigger (#456): a visible close on a phone and focus never left
 * on <body> when they close.
 */
beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
});

it('closes the assistant panel with a visible button on a phone and keeps focus on the page', function () {
    config(['excubia.ai_enabled' => true]);
    $this->actingAs(User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value));

    $page = visit(route('dashboard', absolute: false))->resize(390, 844);
    $page->keys('#main-content', 'ControlOrMeta+j');
    $page->assertVisible('[role="dialog"] button[aria-label="Close"]')
        ->click('[role="dialog"] button[aria-label="Close"]')
        ->wait(1)
        ->assertNotPresent('[role="dialog"]');

    expect($page->script('() => document.activeElement === document.body'))->toBeFalse();
});

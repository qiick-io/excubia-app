<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\PermissionSeeder;

/**
 * The command palette (#274): opened from the header or with ⌘K / Ctrl+K, it finds a project
 * through the server search and opens it, in light and dark.
 */
beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
});

it('opens from the header, finds a project and navigates to it', function (string $scheme) {
    $admin = User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value);
    $project = Project::factory()->create(['name' => 'Billing API', 'slug' => 'billing-api']);
    $this->actingAs($admin);

    $path = route('dashboard', absolute: false);
    $page = ($scheme === 'dark' ? visit($path)->inDarkMode() : visit($path)->inLightMode())
        ->click('[aria-haspopup="dialog"]:has-text("Search")')
        ->assertSee('Navigation')
        ->type('[cmdk-input]', 'billing')
        ->wait(1)
        ->assertSee('Billing API')
        ->keys('[cmdk-input]', 'Enter')
        ->wait(1)
        ->assertPathIs(route('projects.show', $project->slug, false))
        ->assertNoJavaScriptErrors();

    // ⌘K / Ctrl+K reopens it, and the project is now under Recent.
    $page->keys('#main-content', 'ControlOrMeta+k')
        ->assertSee('Recent')
        ->assertSee('Billing API')
        ->assertNoJavaScriptErrors();
})->with(['light', 'dark']);

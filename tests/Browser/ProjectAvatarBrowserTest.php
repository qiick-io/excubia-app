<?php

declare(strict_types=1);

use App\Actions\SaveProjectLogoAction;
use App\Enums\RoleEnum;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Project avatars (#287): initials or the uploaded logo in the projects list, the sidebar, the
 * project header and the command palette, without axe issues, in light and dark.
 */
beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    Storage::fake(SaveProjectLogoAction::DISK);
});

it('shows the initials or the logo of a project everywhere it is named', function (string $scheme) {
    $admin = User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value);
    $billing = Project::factory()->create(['name' => 'Billing API', 'slug' => 'billing-api']);
    $shop = Project::factory()->create(['name' => 'Shop', 'slug' => 'shop']);
    app(SaveProjectLogoAction::class)->execute($shop, UploadedFile::fake()->image('logo.png', 64, 32));
    $admin->pinnedProjects()->attach([$billing->id, $shop->id]);
    $this->actingAs($admin);

    $avatarTexts = "() => [...document.querySelectorAll(SELECTOR)].map((avatar) => avatar.querySelector('img') ? 'logo' : avatar.innerText.trim())";

    $path = route('projects.index', absolute: false);
    $page = ($scheme === 'dark' ? visit($path)->inDarkMode() : visit($path)->inLightMode())
        ->assertSee('Billing API')
        ->wait(1)
        ->assertNoJavaScriptErrors()
        ->assertNoAccessibilityIssues(1);

    expect($page->script(str_replace('SELECTOR', "'tbody [data-test=\"project-avatar\"]'", $avatarTexts)))->toBe(['BA', 'logo'])
        ->and($page->script(str_replace('SELECTOR', "'[data-sidebar=\"menu-sub\"] [data-test=\"project-avatar\"]'", $avatarTexts)))->toBe(['BA', 'logo']);

    $page = ($scheme === 'dark' ? visit(route('projects.show', $billing->slug, false))->inDarkMode() : visit(route('projects.show', $billing->slug, false))->inLightMode())
        ->assertNoJavaScriptErrors();

    expect($page->script(str_replace('SELECTOR', "'[data-slot=\"breadcrumb\"] [data-test=\"project-avatar\"]'", $avatarTexts)))->toBe(['BA']);

    $page->click('[aria-haspopup="dialog"]:has-text("Search")')
        ->type('[cmdk-input]', 'shop')
        ->wait(1)
        ->assertSee('Shop')
        ->assertNoJavaScriptErrors();

    expect($page->script(str_replace('SELECTOR', "'[cmdk-item] [data-test=\"project-avatar\"]'", $avatarTexts)))->toBe(['logo']);
})->with(['light', 'dark']);

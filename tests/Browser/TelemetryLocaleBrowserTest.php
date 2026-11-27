<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use App\Models\User;
use Database\Seeders\PermissionSeeder;

require_once __DIR__.'/BrowserSeedHelpers.php';

it('uses the UI locale for dashboard and exception counts with an English browser', function (string $locale) {
    $this->seed(PermissionSeeder::class);
    $this->actingAs(User::factory()->create(['locale' => $locale])->assignRole(RoleEnum::SuperAdmin->value));
    $project = seededA11yProject();
    $project->exceptionGroups()->firstOrFail()->forceFill(['count' => 12345])->save();

    foreach ([route('dashboard', absolute: false), route('projects.view', [$project, 'exceptions'], false)] as $path) {
        visit($path)->withLocale('en-US')
            ->assertScript('() => navigator.language', 'en-US')
            ->assertScript('() => document.documentElement.lang', $locale)
            ->assertSee('12.345')
            ->assertDontSee('12,345')
            ->assertNoJavaScriptErrors();
    }
})->with(['it', 'de']);

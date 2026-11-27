<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use App\Models\RuntimeAggregate;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Str;

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

it('uses the UI locale for telemetry decimals and estimated AI spend with an English browser', function () {
    $this->seed(PermissionSeeder::class);
    $this->actingAs(User::factory()->create(['locale' => 'de'])->assignRole(RoleEnum::SuperAdmin->value));
    $project = seededA11yProject();
    $project->forceFill(['ai_monthly_budget' => 1234.50])->save();
    $report = $project->healthReports()->firstOrFail();
    $report->forceFill(['high_count' => 21])->save();
    $report->replicate()->forceFill([
        'batch_id' => (string) Str::uuid(),
        'received_at' => now()->subDays(40),
        'generated_at' => now()->subDays(40),
        'high_count' => 20,
    ])->save();
    RuntimeAggregate::query()->create([
        'project_id' => $project->id,
        'type' => 'cache',
        'bucket' => now()->startOfMinute(),
        'sample_rate' => 1,
        'dimension_key' => hash('sha256', 'cache-fixture'),
        'dimensions' => ['store' => 'redis', 'keyPrefix' => 'users'],
        'count' => 3,
        'error_count' => 0,
        'metrics' => ['hits' => 2, 'misses' => 1, 'writes' => 0, 'deletes' => 0],
    ]);

    visit(route('projects.view', [$project, 'runtime'], false))->withLocale('en-US')
        ->assertSee('66,7')
        ->assertDontSee('66.7')
        ->assertNoJavaScriptErrors();

    visit(route('projects.view', [$project, 'ai'], false))->withLocale('en-US')
        ->assertSee('1.234,50')
        ->assertSee('0,2865')
        ->assertDontSee('1,234.50')
        ->assertNoJavaScriptErrors();

    visit(route('projects.show', $project, false))->withLocale('en-US')
        ->assertSee('2.048,5')
        ->assertDontSee('2,048.5')
        ->assertNoJavaScriptErrors();

    visit(route('dashboard', absolute: false))->withLocale('en-US')
        ->assertSee('+5,0')
        ->assertDontSee('+5.0%')
        ->assertNoJavaScriptErrors();
});

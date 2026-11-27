<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use App\Enums\SeverityEnum;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Str;

/**
 * The upgrade plan copies as a prompt for the user's own coding assistant and names the MCP
 * tool that returns the same steps (#469), in light and dark.
 */
beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    // The MCP line shows only while MCP serves the tool.
    config(['excubia.mcp_enabled' => true]);
    $this->actingAs(User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value));

    $project = Project::factory()->create(['name' => 'Shop', 'slug' => 'shop']);
    $project->healthReports()->create([
        'batch_id' => (string) Str::uuid(),
        'received_at' => now(),
        'generated_at' => now(),
        'worst_severity' => SeverityEnum::Low,
        'low_count' => 1,
        'report' => [
            'schemaVersion' => 1,
            'project' => ['laravel' => ['version' => '13.2.0']],
            'checks' => [],
            'upgradePlan' => [[
                'stepNumber' => 1,
                'kind' => 'composer-patch',
                'message' => 'Update to the latest patch: laravel/ai v1.4.2.',
                'targetMajor' => null,
                'packages' => [['name' => 'laravel/ai', 'ecosystem' => 'composer', 'installed' => 'v1.4.1', 'target' => 'v1.4.2']],
            ]],
        ],
    ]);
    $project->forceFill(['last_report_at' => now()])->save();
});

it('copies the plan as a prompt and names the MCP tool', function (string $scheme) {
    $path = route('projects.view', ['project' => 'shop', 'view' => 'plan'], false);

    ($scheme === 'dark' ? visit($path)->inDarkMode() : visit($path)->inLightMode())
        ->assertSee('get-upgrade-plan')
        ->click('Copy prompt for AI')
        ->assertSee('Copied to the clipboard')
        ->assertNoJavaScriptErrors();
})->with(['light', 'dark']);

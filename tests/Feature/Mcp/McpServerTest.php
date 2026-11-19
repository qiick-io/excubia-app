<?php

declare(strict_types=1);

use App\Actions\CreateApiKeyAction;
use App\Ai\Agents\ExcubiaAssistantAgent;
use App\Data\ApiKeyData;
use App\Enums\AiToolKindEnum;
use App\Enums\ApiKeyScopeEnum;
use App\Enums\PermissionEnum;
use App\Enums\ProjectVisibilityEnum;
use App\Enums\RoleEnum;
use App\Enums\SeverityEnum;
use App\Mcp\Adapters\AssistantMcpToolAdapter;
use App\Mcp\Servers\ExcubiaServer;
use App\Models\ApiKey;
use App\Models\Project;
use App\Models\User;
use App\Services\SettingsRepositoryService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;

/**
 * The MCP server (#234) over HTTP: off by default, a personal API key with `mcp:read` that acts as
 * its owner, the Assistant tools only, logged. The write tools (#320) are in AiWriteToolsTest.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    config(['excubia.mcp_enabled' => true]);
});

function mcpUser(RoleEnum $role = RoleEnum::Reader): User
{
    $user = User::factory()->create()->assignRole($role->value);

    // mcp scopes require can-use-ai; Reader does not hold it by default.
    if (! $user->hasPermissionTo(PermissionEnum::CanUseAi->value)) {
        $user->givePermissionTo(PermissionEnum::CanUseAi->value);
    }

    return $user;
}

function mcpKey(User $user): string
{
    return app(CreateApiKeyAction::class)->execute($user, new ApiKeyData('Claude Code', [ApiKeyScopeEnum::McpRead], null));
}

/**
 * Sends one JSON-RPC message to `/mcp` with the headers a streamable HTTP client sends.
 *
 * @param  array<string, mixed>  $params
 */
function mcpCall(?string $plainKey, string $method, array $params = [], ?string $name = null): TestResponse
{
    $headers = ['Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => $method];

    if ($name !== null) {
        $headers['Mcp-Name'] = $name;
    }

    if ($plainKey !== null) {
        $headers['Authorization'] = 'Bearer '.$plainKey;
    }

    return test()->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => $method,
        'params' => [
            ...$params,
            '_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                'io.modelcontextprotocol/clientCapabilities' => new stdClass,
                'io.modelcontextprotocol/clientInfo' => ['name' => 'excubia-pest', 'version' => '1.0.0'],
            ],
        ],
    ], $headers);
}

/**
 * The text a `tools/call` returned, decoded.
 *
 * @return array<string, mixed>
 */
function mcpToolResult(TestResponse $response): array
{
    return json_decode((string) $response->json('result.content.0.text'), true);
}

it('answers 404 while EXCUBIA_MCP_ENABLED is off, before looking at the key', function () {
    config(['excubia.mcp_enabled' => false]);

    mcpCall(mcpKey(mcpUser()), 'ping')->assertNotFound();
});

it('is off by default', function () {
    expect(require config_path('excubia.php'))->toHaveKey('mcp_enabled', false);
});

it('rejects a missing, unknown or revoked key and a key without mcp:read', function () {
    $user = mcpUser();
    $plainKey = mcpKey($user);

    mcpCall(null, 'ping')->assertUnauthorized();
    mcpCall('exk_'.str_repeat('x', 40), 'ping')->assertUnauthorized();

    ApiKey::query()->sole()->forceFill(['scopes' => []])->save();
    mcpCall($plainKey, 'ping')->assertForbidden();

    ApiKey::query()->sole()->forceFill(['scopes' => ['mcp:read'], 'revoked_at' => now()])->save();
    mcpCall($plainKey, 'ping')->assertUnauthorized();
});

it('lists the Assistant read tools to an mcp:read key, every one read-only, and only those enabled in Settings → AI', function () {
    $plainKey = mcpKey(mcpUser());

    $tools = collect(mcpCall($plainKey, 'tools/list')->assertOk()->json('result.tools'));

    expect($tools->pluck('name')->all())->toBe(ExcubiaAssistantAgent::toolNames(AiToolKindEnum::Read))
        ->and($tools->every(fn (array $tool): bool => $tool['annotations']['readOnlyHint'] === true))->toBeTrue()
        ->and($tools->firstWhere('name', 'get-latest-report')['inputSchema']['required'])->toBe(['project'])
        ->and($tools->firstWhere('name', 'get-latest-report')['title'])->toBe('Get Latest Report')
        ->and($tools->pluck('title')->unique()->count())->toBe($tools->count());

    app(SettingsRepositoryService::class)->save(['ai_enabled_tools' => ['list-projects']]);

    expect(collect(mcpCall($plainKey, 'tools/list')->json('result.tools'))->pluck('name')->all())->toBe(['list-projects']);
    mcpCall($plainKey, 'tools/call', ['name' => 'get-upgrade-plan', 'arguments' => ['project' => 'shop']], 'get-upgrade-plan')
        ->assertJsonPath('error.code', -32602);
});

it('runs a tool as the key owner, hides admin-only projects from a reader and logs the call', function () {
    Project::factory()->create(['name' => 'Shop', 'slug' => 'shop']);
    Project::factory()->create(['name' => 'Vault', 'slug' => 'vault', 'visibility' => ProjectVisibilityEnum::Admins]);
    $reader = mcpUser();

    $readerProjects = mcpToolResult(mcpCall(mcpKey($reader), 'tools/call', ['name' => 'list-projects', 'arguments' => []], 'list-projects')->assertOk());
    $adminProjects = mcpToolResult(mcpCall(mcpKey(mcpUser(RoleEnum::Admin)), 'tools/call', ['name' => 'list-projects', 'arguments' => []], 'list-projects')->assertOk());

    expect(collect($readerProjects['projects'])->pluck('slug')->all())->toBe(['shop'])
        ->and(collect($adminProjects['projects'])->pluck('slug')->all())->toBe(['shop', 'vault']);

    $activity = Activity::query()->where('log_name', 'ai')->where('causer_id', $reader->id)->sole();

    expect($activity->event)->toBe('ai_tool')
        ->and($activity->properties['surface'])->toBe('mcp')
        ->and($activity->properties['tool'])->toBe('list-projects')
        ->and($activity->properties['status'])->toBe('ok')
        ->and($activity->properties['api_key_id'])->toBe(ApiKey::query()->where('owner_id', $reader->id)->value('id'));
});

it('returns a tool error for a user without can-show-projects and for an unknown project', function () {
    $user = User::factory()->create();
    $user->givePermissionTo(PermissionEnum::CanUseAi->value);
    $plainKey = mcpKey($user);

    mcpCall($plainKey, 'tools/call', ['name' => 'list-projects', 'arguments' => []], 'list-projects')
        ->assertOk()
        ->assertJsonPath('result.isError', true);

    mcpCall(mcpKey(mcpUser()), 'tools/call', ['name' => 'get-latest-report', 'arguments' => ['project' => 'nope']], 'get-latest-report')
        ->assertJsonPath('result.isError', true);
});

it('lets the test API reach every adapter as the acting user', function () {
    $project = Project::factory()->create(['slug' => 'shop']);
    $project->healthReports()->create([
        'batch_id' => (string) Str::uuid(),
        'received_at' => now(),
        'generated_at' => now(),
        'worst_severity' => SeverityEnum::High,
        'report' => ['schemaVersion' => 1, 'checks' => [], 'upgradePlan' => [['stepNumber' => 1, 'message' => 'composer update']]],
    ]);

    ExcubiaServer::actingAs(mcpUser())
        ->tool(new AssistantMcpToolAdapter('get-upgrade-plan', ExcubiaAssistantAgent::TOOLS['get-upgrade-plan']), ['project' => 'shop'])
        ->assertOk()
        ->assertSee('composer update');
});

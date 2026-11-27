<?php

declare(strict_types=1);

use App\Actions\CreateApiKeyAction;
use App\Ai\Agents\ExcubiaAssistantAgent;
use App\Ai\Tools\AssistantWriteTool;
use App\Data\ApiKeyData;
use App\Enums\AiToolKindEnum;
use App\Enums\ApiKeyScopeEnum;
use App\Enums\ExceptionStatusEnum;
use App\Enums\PermissionEnum;
use App\Enums\ProjectVisibilityEnum;
use App\Enums\RoleEnum;
use App\Jobs\RunSystemReportJob;
use App\Models\Activity;
use App\Models\AlertLog;
use App\Models\AlertMute;
use App\Models\ExceptionGroup;
use App\Models\Finding;
use App\Models\MaintenanceWindow;
use App\Models\Project;
use App\Models\User;
use App\Services\SettingsRepositoryService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Tools\Request;

/**
 * The write tools of the Assistant and the MCP server (#320): each one through the Assistant and
 * through `/mcp`, the refusals (an `mcp:read` key, the switches off, a missing permission, a
 * project the user cannot see), the in-app approval (approve, reject, "always" for the chat) and
 * the activity entries. The provider is faked; nothing leaves the test.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    Http::preventStrayRequests();
    Queue::fake();
});

function writerUser(RoleEnum $role = RoleEnum::Admin): User
{
    return User::factory()->create()->assignRole($role->value);
}

function writerException(Project $project, string $fingerprint): ExceptionGroup
{
    return ExceptionGroup::query()->create([
        'project_id' => $project->id, 'fingerprint' => str_repeat($fingerprint, 64), 'environment' => 'production',
        'class' => 'RuntimeException', 'message' => 'Payment failed', 'frames' => [], 'previous' => [],
        'context_kind' => 'request', 'context_name' => 'checkout.store', 'count' => 1,
        'first_seen_at' => now(), 'last_seen_at' => now(), 'status' => 'open',
    ]);
}

function writerFinding(Project $project, string $subject = 'guzzlehttp/guzzle'): Finding
{
    return $project->findings()->create([
        'fingerprint' => hash('sha256', $subject), 'check_id' => 'composer-audit', 'severity' => 'high', 'status' => 'open',
        'finding' => ['checkId' => 'composer-audit', 'severity' => 'high', 'status' => 'fail', 'subject' => $subject, 'message' => 'Known vulnerability'],
        'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);
}

/**
 * Runs a tool as the Assistant runs it (the approval is the agent loop's job, tested below).
 *
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function runWriteTool(string $name, User $user, array $arguments): array
{
    $class = ExcubiaAssistantAgent::TOOLS[$name];

    return json_decode((string) (new $class($user))->handle(new Request($arguments)), true);
}

/**
 * @param  list<ApiKeyScopeEnum>  $scopes
 */
function writerKey(User $user, array $scopes = [ApiKeyScopeEnum::McpRead, ApiKeyScopeEnum::McpWrite]): string
{
    return app(CreateApiKeyAction::class)->execute($user, new ApiKeyData('Claude Code', $scopes, null));
}

/**
 * @param  array<string, mixed>  $params
 */
function writerMcp(string $plainKey, string $method, array $params = [], ?string $name = null): TestResponse
{
    return test()->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => $method,
        'params' => [...$params, '_meta' => [
            'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
            'io.modelcontextprotocol/clientCapabilities' => new stdClass,
            'io.modelcontextprotocol/clientInfo' => ['name' => 'excubia-pest', 'version' => '1.0.0'],
        ]],
    ], array_filter([
        'Accept' => 'application/json, text/event-stream',
        'MCP-Protocol-Version' => '2026-07-28',
        'Mcp-Method' => $method,
        'Mcp-Name' => $name,
        'Authorization' => 'Bearer '.$plainKey,
    ]));
}

/**
 * @return list<string>
 */
function writerMcpToolNames(string $plainKey): array
{
    return collect(writerMcp($plainKey, 'tools/list')->assertOk()->json('result.tools'))->pluck('name')->all();
}

it('is on by default', function () {
    $config = require config_path('excubia.php');

    expect($config['ai_write_enabled'])->toBeTrue()
        ->and($config['mcp_write_enabled'])->toBeTrue()
        ->and($config['mcp_enabled'])->toBeFalse()
        ->and(ExcubiaAssistantAgent::toolNames(AiToolKindEnum::Write))->toBe(['update-exception-status', 'assign-items', 'dismiss-finding', 'restore-finding', 'mute-alert', 'unmute-alert', 'refresh-report']);
});

it('resolves and reopens exception groups of one project with the bulk action, and logs the call', function () {
    $shop = Project::factory()->create(['slug' => 'shop']);
    $first = writerException($shop, 'a');
    $second = writerException($shop, 'b');
    $foreign = writerException(Project::factory()->create(['slug' => 'blog']), 'c');
    $admin = writerUser();

    expect(runWriteTool('update-exception-status', $admin, ['project' => 'shop', 'ids' => [$first->id, $second->id], 'status' => 'resolved']))
        ->toBe(['project' => 'shop', 'status' => 'resolved', 'selected' => 2, 'changed' => 2])
        ->and($first->fresh()->status)->toBe(ExceptionStatusEnum::Resolved)
        ->and(Activity::query()->where('event', 'status_changed')->where('causer_id', $admin->id)->count())->toBe(2)
        ->and(runWriteTool('update-exception-status', $admin, ['project' => 'shop', 'ids' => [$first->id, $foreign->id], 'status' => 'open']))->toHaveKey('error')
        ->and($first->fresh()->status)->toBe(ExceptionStatusEnum::Resolved)
        ->and(runWriteTool('update-exception-status', $admin, ['project' => 'shop', 'ids' => range(1, 201), 'status' => 'open']))->toHaveKey('error')
        ->and(runWriteTool('update-exception-status', $admin, ['project' => 'shop', 'ids' => [$first->id], 'status' => 'gone']))->toHaveKey('error');

    $activity = Activity::query()->where('log_name', 'ai')->where('event', 'ai_tool')->oldest('id')->first();

    expect($activity->causer_id)->toBe((string) $admin->id)
        ->and($activity->properties->only(['surface', 'tool', 'kind', 'status'])->all())->toBeSameJson(['surface' => 'assistant', 'tool' => 'update-exception-status', 'kind' => 'write', 'status' => 'ok'])
        ->and($activity->properties['arguments']['ids'])->toBe([$first->id, $second->id])
        ->and(Activity::query()->where('event', 'ai_tool')->where('properties->status', 'error')->count())->toBe(3);
});

it('assigns exception groups and findings to me, to a user by email or to nobody, only to users who can see the project', function () {
    $shop = Project::factory()->create(['slug' => 'shop', 'visibility' => ProjectVisibilityEnum::Admins]);
    $group = writerException($shop, 'a');
    $finding = writerFinding($shop);
    $admin = writerUser();
    $otherAdmin = User::factory()->create(['email' => 'ops@example.test'])->assignRole(RoleEnum::Admin->value);
    User::factory()->create(['email' => 'reader@example.test'])->assignRole(RoleEnum::Reader->value);

    $result = runWriteTool('assign-items', $admin, ['project' => 'shop', 'exception_group_ids' => [$group->id], 'assignee' => 'me']);

    expect($result)->toBe(['project' => 'shop', 'type' => 'exception_groups', 'count' => 1, 'isAssigned' => true])
        ->and($group->fresh()->assigned_user_id)->toBe($admin->id)
        ->and(runWriteTool('assign-items', $admin, ['project' => 'shop', 'finding_fingerprints' => [$finding->fingerprint], 'assignee' => 'OPS@example.test'])['count'])->toBe(1)
        ->and($finding->fresh()->assigned_user_id)->toBe($otherAdmin->id)
        ->and($finding->events()->where('event', 'assigned')->exists())->toBeTrue()
        // A reader cannot see an admin-only project, and an unknown address gets the same answer.
        ->and(runWriteTool('assign-items', $admin, ['project' => 'shop', 'finding_fingerprints' => [$finding->fingerprint], 'assignee' => 'reader@example.test']))->toBe(['error' => 'No active user with this email address can see the project.'])
        ->and(runWriteTool('assign-items', $admin, ['project' => 'shop', 'finding_fingerprints' => [$finding->fingerprint], 'assignee' => 'nobody@example.test']))->toBe(['error' => 'No active user with this email address can see the project.'])
        ->and(runWriteTool('assign-items', $admin, ['project' => 'shop', 'exception_group_ids' => [$group->id], 'finding_fingerprints' => [$finding->fingerprint], 'assignee' => 'me']))->toHaveKey('error');

    runWriteTool('assign-items', $admin, ['project' => 'shop', 'exception_group_ids' => [$group->id], 'assignee' => 'nobody']);

    expect($group->fresh()->assigned_user_id)->toBeNull();
});

it('dismisses, snoozes and restores a finding by its fingerprint from SearchFindings', function () {
    $shop = Project::factory()->create(['slug' => 'shop']);
    $finding = writerFinding($shop);
    $admin = writerUser();
    $until = now()->addWeek()->toDateString();

    expect(runWriteTool('dismiss-finding', $admin, ['project' => 'shop', 'fingerprint' => $finding->fingerprint, 'reason' => 'Dev only', 'until' => $until]))
        ->toBe(['project' => 'shop', 'fingerprint' => $finding->fingerprint, 'isDismissed' => true, 'until' => $until])
        ->and($finding->fresh()->dismissed_reason)->toBe('Dev only')
        ->and($finding->fresh()->dismissed_until?->toDateString())->toBe($until)
        ->and(runWriteTool('dismiss-finding', $admin, ['project' => 'shop', 'fingerprint' => $finding->fingerprint, 'reason' => '']))->toHaveKey('error')
        ->and(runWriteTool('dismiss-finding', $admin, ['project' => 'shop', 'fingerprint' => $finding->fingerprint, 'reason' => 'x', 'until' => '2020-01-01']))->toHaveKey('error')
        ->and(runWriteTool('dismiss-finding', $admin, ['project' => 'shop', 'fingerprint' => str_repeat('0', 64), 'reason' => 'x']))->toHaveKey('error');

    expect(runWriteTool('restore-finding', $admin, ['project' => 'shop', 'fingerprint' => $finding->fingerprint])['isDismissed'])->toBeFalse()
        ->and($finding->fresh()->dismissed_at)->toBeNull()
        ->and($finding->events()->pluck('event')->map->value->all())->toBe(['dismissed', 'undismissed'])
        ->and(runWriteTool('restore-finding', $admin, ['project' => 'shop', 'fingerprint' => $finding->fingerprint]))->toHaveKey('note');
});

it('gives SearchFindings results the fingerprint the finding tools take', function () {
    $shop = Project::factory()->create(['slug' => 'shop']);
    $shop->healthReports()->create([
        'batch_id' => (string) Str::uuid(), 'received_at' => now(), 'generated_at' => now(),
        'report' => ['schemaVersion' => 1, 'checks' => [['checkId' => 'composer-audit', 'name' => 'Composer audit', 'status' => 'fail', 'findings' => [['checkId' => 'composer-audit', 'severity' => 'high', 'status' => 'fail', 'subject' => 'guzzlehttp/guzzle', 'advisoryId' => 'GHSA-1', 'message' => 'Advisory']]]]],
    ]);

    expect(runWriteTool('search-findings', writerUser(), ['project' => 'shop'])['findings'][0]['fingerprint'])
        ->toBe(hash('sha256', "composer-audit\0guzzlehttp/guzzle\0GHSA-1"));
});

it('mutes an alert event and opens a maintenance window, and undoes both', function () {
    $shop = Project::factory()->create(['slug' => 'shop']);
    $admin = writerUser();

    expect(runWriteTool('mute-alert', $admin, ['project' => 'shop', 'target' => 'rule', 'event' => 'project.stale', 'duration' => '7d']))
        ->toBe(['error' => 'This project has sent no alert of this event yet, so there is nothing to mute.']);

    AlertLog::query()->create(['uuid' => (string) Str::uuid(), 'project_id' => $shop->id, 'event' => 'project.stale', 'severity' => 'high', 'summary' => 'Shop stopped reporting', 'status' => AlertLog::SENT, 'deliveries' => []]);
    $muted = runWriteTool('mute-alert', $admin, ['project' => 'shop', 'target' => 'rule', 'event' => 'project.stale', 'duration' => '7d']);
    $window = runWriteTool('mute-alert', $admin, ['project' => 'shop', 'target' => 'maintenance_window', 'name' => 'Deploy', 'recurrence' => 'once', 'timezone' => 'Europe/Rome', 'starts_at' => now()->addDay()->format('Y-m-d\TH:i'), 'ends_at' => now()->addDay()->addHour()->format('Y-m-d\TH:i')]);

    expect($muted['mute']['event'])->toBe('project.stale')
        ->and(AlertMute::query()->sole()->subject)->toBeNull()
        ->and($window['maintenanceWindow']['name'])->toBe('Deploy')
        ->and(MaintenanceWindow::query()->sole()->timezone)->toBe('Europe/Rome')
        ->and(runWriteTool('mute-alert', $admin, ['project' => 'shop', 'target' => 'maintenance_window', 'name' => 'Bad', 'recurrence' => 'once', 'timezone' => 'UTC', 'starts_at' => '2030-01-02T10:00', 'ends_at' => '2030-01-01T10:00']))->toHaveKey('error');

    expect(runWriteTool('unmute-alert', $admin, ['project' => 'shop', 'target' => 'rule', 'event' => 'project.stale'])['endedMutes'])->toBe(1)
        ->and(runWriteTool('unmute-alert', $admin, ['project' => 'shop', 'target' => 'maintenance_window', 'maintenance_window_id' => $window['maintenanceWindow']['id']])['closedMaintenanceWindowId'])->toBe($window['maintenanceWindow']['id'])
        ->and(AlertMute::query()->count() + MaintenanceWindow::query()->count())->toBe(0);
});

it('refreshes only the system report', function () {
    Project::factory()->create(['slug' => 'excubia', 'is_system' => true]);
    Project::factory()->create(['slug' => 'shop']);
    $admin = writerUser();

    expect(runWriteTool('refresh-report', $admin, ['project' => 'excubia']))->toBe(['project' => 'excubia', 'isStarted' => true])
        ->and(runWriteTool('refresh-report', $admin, ['project' => 'shop']))->toHaveKey('error');

    Queue::assertPushed(RunSystemReportJob::class, 1);
});

it('refuses a user without the permission and a project the user cannot see, and changes nothing', function () {
    $shop = Project::factory()->create(['slug' => 'shop']);
    $vault = Project::factory()->create(['slug' => 'vault', 'visibility' => ProjectVisibilityEnum::Admins]);
    $shopGroup = writerException($shop, 'a');
    $vaultGroup = writerException($vault, 'b');
    $reader = writerUser(RoleEnum::Reader);

    expect(runWriteTool('update-exception-status', $reader, ['project' => 'shop', 'ids' => [$shopGroup->id], 'status' => 'resolved']))
        ->toBe(['error' => 'The user lacks the permission for this action on this project.']);

    $reader->givePermissionTo(PermissionEnum::CanManageExceptions->value);

    expect(runWriteTool('update-exception-status', $reader->fresh(), ['project' => 'vault', 'ids' => [$vaultGroup->id], 'status' => 'resolved']))
        ->toBe(['error' => 'No project matches this slug. Call ListProjects for the slugs.'])
        ->and(runWriteTool('update-exception-status', User::factory()->create(), ['project' => 'shop', 'ids' => [$shopGroup->id], 'status' => 'resolved']))->toHaveKey('error')
        ->and($shopGroup->fresh()->status)->toBe(ExceptionStatusEnum::Open)
        ->and($vaultGroup->fresh()->status)->toBe(ExceptionStatusEnum::Open)
        ->and(runWriteTool('update-exception-status', $reader->fresh(), ['project' => 'shop', 'ids' => [$shopGroup->id], 'status' => 'resolved'])['changed'])->toBe(1);
});

it('turns every write off with EXCUBIA_AI_WRITE_ENABLED=false, in the assistant and over MCP', function () {
    config(['excubia.ai_write_enabled' => false, 'excubia.mcp_enabled' => true]);
    $shop = Project::factory()->create(['slug' => 'shop']);
    $group = writerException($shop, 'a');
    $admin = writerUser();

    $agentTools = collect((new ExcubiaAssistantAgent($admin))->tools());

    expect($agentTools->contains(fn (object $tool): bool => $tool instanceof AssistantWriteTool))->toBeFalse()
        ->and($agentTools)->toHaveCount(9)
        ->and(runWriteTool('update-exception-status', $admin, ['project' => 'shop', 'ids' => [$group->id], 'status' => 'resolved'])['error'])->toContain('EXCUBIA_AI_WRITE_ENABLED=false')
        ->and($group->fresh()->status)->toBe(ExceptionStatusEnum::Open)
        ->and(writerMcpToolNames(writerKey($admin)))->toBe(ExcubiaAssistantAgent::toolNames(AiToolKindEnum::Read));
});

it('serves the write tools over MCP only to an mcp:write key while EXCUBIA_MCP_WRITE_ENABLED is on', function () {
    config(['excubia.mcp_enabled' => true]);
    $shop = Project::factory()->create(['slug' => 'shop']);
    $group = writerException($shop, 'a');
    $admin = writerUser();
    $readKey = writerKey($admin, [ApiKeyScopeEnum::McpRead]);
    $writeKey = writerKey($admin);

    $tools = collect(writerMcp($writeKey, 'tools/list')->assertOk()->json('result.tools'))->keyBy('name');

    expect($tools->keys()->all())->toBe(array_keys(ExcubiaAssistantAgent::TOOLS))
        ->and($tools['update-exception-status']['annotations'])->toMatchArray(['readOnlyHint' => false, 'destructiveHint' => false])
        ->and($tools['list-projects']['annotations'])->toMatchArray(['readOnlyHint' => true, 'idempotentHint' => true])
        ->and(writerMcpToolNames($readKey))->toBe(ExcubiaAssistantAgent::toolNames(AiToolKindEnum::Read));

    writerMcp($readKey, 'tools/call', ['name' => 'update-exception-status', 'arguments' => ['project' => 'shop', 'ids' => [$group->id], 'status' => 'resolved']], 'update-exception-status')
        ->assertJsonPath('error.code', -32602);
    expect($group->fresh()->status)->toBe(ExceptionStatusEnum::Open);

    writerMcp($writeKey, 'tools/call', ['name' => 'update-exception-status', 'arguments' => ['project' => 'shop', 'ids' => [$group->id], 'status' => 'resolved']], 'update-exception-status')
        ->assertOk()
        ->assertJsonPath('result.isError', false);

    $activity = Activity::query()->where('log_name', 'ai')->where('event', 'ai_tool')->sole();

    expect($group->fresh()->status)->toBe(ExceptionStatusEnum::Resolved)
        ->and($activity->properties->only(['surface', 'tool', 'status'])->all())->toBeSameJson(['surface' => 'mcp', 'tool' => 'update-exception-status', 'status' => 'ok']);

    config(['excubia.mcp_write_enabled' => false]);
    expect(writerMcpToolNames($writeKey))->toBe(ExcubiaAssistantAgent::toolNames(AiToolKindEnum::Read));
});

it('runs every write tool over MCP with an mcp:write key', function (string $name, array $arguments) {
    config(['excubia.mcp_enabled' => true]);
    $shop = Project::factory()->create(['slug' => 'shop']);
    Project::factory()->create(['slug' => 'excubia', 'is_system' => true]);
    $group = writerException($shop, 'a');
    $finding = writerFinding($shop);
    $finding->forceFill(['dismissed_at' => now(), 'dismissed_reason' => 'Old'])->save();
    AlertLog::query()->create(['uuid' => (string) Str::uuid(), 'project_id' => $shop->id, 'event' => 'project.stale', 'severity' => 'high', 'summary' => 'Shop', 'status' => AlertLog::SENT, 'deliveries' => []]);
    $window = MaintenanceWindow::query()->create(['project_id' => $shop->id, 'name' => 'Deploy', 'recurrence' => MaintenanceWindow::WEEKLY, 'timezone' => 'UTC', 'weekdays' => [1], 'start_time' => '02:00', 'duration_minutes' => 30]);
    $placeholders = ['{group}' => $group->id, '{fingerprint}' => $finding->fingerprint, '{window}' => $window->id];
    $arguments = array_map(fn (mixed $value): mixed => is_array($value) ? array_map(fn (mixed $item): mixed => $placeholders[$item] ?? $item, $value) : ($placeholders[$value] ?? $value), $arguments);

    writerMcp(writerKey(writerUser()), 'tools/call', ['name' => $name, 'arguments' => $arguments], $name)
        ->assertOk()
        ->assertJsonPath('result.isError', false);
})->with([
    'update-exception-status' => ['update-exception-status', ['project' => 'shop', 'ids' => ['{group}'], 'status' => 'ignored']],
    'assign-items' => ['assign-items', ['project' => 'shop', 'exception_group_ids' => ['{group}'], 'assignee' => 'me']],
    'dismiss-finding' => ['dismiss-finding', ['project' => 'shop', 'fingerprint' => '{fingerprint}', 'reason' => 'Dev only']],
    'restore-finding' => ['restore-finding', ['project' => 'shop', 'fingerprint' => '{fingerprint}']],
    'mute-alert' => ['mute-alert', ['project' => 'shop', 'target' => 'rule', 'event' => 'project.stale', 'duration' => '1h']],
    'unmute-alert' => ['unmute-alert', ['project' => 'shop', 'target' => 'maintenance_window', 'maintenance_window_id' => '{window}']],
    'refresh-report' => ['refresh-report', ['project' => 'excubia']],
]);

it('pauses an assistant turn on a write call until the user approves it', function () {
    config(['excubia.ai_enabled' => true]);
    $shop = Project::factory()->create(['slug' => 'shop']);
    $group = writerException($shop, 'a');
    $admin = writerUser();
    // The provider's HTTP API is faked rather than the agent: laravel/ai runs no tool when it
    // resumes a faked agent, and this test proves the approved call runs.
    Http::fake(['*/api/chat' => Http::sequence()
        ->push(['model' => 'qwen', 'done_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => [
            ['id' => 'call_1', 'function' => ['name' => 'UpdateExceptionStatusTool', 'arguments' => ['project' => 'shop', 'ids' => [$group->id], 'status' => 'resolved']]],
        ]]])
        ->push(['model' => 'qwen', 'done_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => 'Resolved the payment exception.']]),
    ]);

    $this->actingAs($admin)->postJson(route('ai.store'), ['message' => 'Resolve the payment exception on shop'])
        ->assertOk()
        ->assertJsonPath('messages.1.pending_approvals.0.tool', 'update-exception-status')
        ->assertJsonPath('messages.1.pending_approvals.0.arguments.status', 'resolved');

    $conversationId = Conversation::query()->sole()->getKey();
    expect($group->fresh()->status)->toBe(ExceptionStatusEnum::Open);

    $this->actingAs($admin)->get(route('ai.show', $conversationId))
        ->assertInertia(fn (Assert $page) => $page->where('messages.1.pending_approvals.0.id', 'call_1'));

    $this->actingAs(writerUser())->post(route('ai.approvals', $conversationId), ['approved' => true])->assertForbidden();

    $this->actingAs($admin)->post(route('ai.approvals', $conversationId), ['approved' => true])
        ->assertRedirect(route('ai.show', $conversationId));

    $approval = Activity::query()->where('event', 'ai_tool_approval')->sole();

    expect($group->fresh()->status)->toBe(ExceptionStatusEnum::Resolved)
        ->and($approval->properties->only(['surface', 'tool', 'decision', 'isAlways'])->all())->toBeSameJson(['surface' => 'assistant', 'tool' => 'update-exception-status', 'decision' => 'approved', 'isAlways' => false])
        ->and(Activity::query()->where('event', 'ai_tool')->sole()->properties['surface'])->toBe('assistant');

    $this->actingAs($admin)->get(route('ai.show', $conversationId))
        ->assertInertia(fn (Assert $page) => $page
            ->where('messages.1.pending_approvals', [])
            ->where('messages.1.content', 'Resolved the payment exception.'));

    $this->actingAs($admin)->postJson(route('ai.approvals', $conversationId), ['approved' => true])->assertUnprocessable();
});

it('skips a rejected write call and tells the model', function () {
    config(['excubia.ai_enabled' => true]);
    $group = writerException(Project::factory()->create(['slug' => 'shop']), 'a');
    $admin = writerUser();
    ExcubiaAssistantAgent::fake([
        new ToolCall('call_1', 'UpdateExceptionStatusTool', ['project' => 'shop', 'ids' => [$group->id], 'status' => 'ignored']),
        'Left it open.',
    ]);

    $this->actingAs($admin)->postJson(route('ai.store'), ['message' => 'Ignore it']);
    $conversationId = Conversation::query()->sole()->getKey();

    $this->actingAs($admin)->postJson(route('ai.approvals', $conversationId), ['approved' => false, 'always' => true])
        ->assertOk()
        ->assertJsonPath('messages.1.pending_approvals', []);

    expect($group->fresh()->status)->toBe(ExceptionStatusEnum::Open)
        ->and(Activity::query()->where('event', 'ai_tool_approval')->sole()->properties['decision'])->toBe('rejected')
        ->and(Activity::query()->where('event', 'ai_tool')->exists())->toBeFalse();
});

it('stops asking for a tool approved "always" in the same conversation only', function () {
    config(['excubia.ai_enabled' => true]);
    $shop = Project::factory()->create(['slug' => 'shop']);
    $first = writerException($shop, 'a');
    $second = writerException($shop, 'b');
    $third = writerException($shop, 'c');
    $admin = writerUser();
    ExcubiaAssistantAgent::fake([
        new ToolCall('call_1', 'UpdateExceptionStatusTool', ['project' => 'shop', 'ids' => [$first->id], 'status' => 'resolved']),
        'Done.',
        new ToolCall('call_2', 'UpdateExceptionStatusTool', ['project' => 'shop', 'ids' => [$second->id], 'status' => 'resolved']),
        'Done again.',
        new ToolCall('call_3', 'UpdateExceptionStatusTool', ['project' => 'shop', 'ids' => [$third->id], 'status' => 'resolved']),
    ]);

    $this->actingAs($admin)->postJson(route('ai.store'), ['message' => 'Resolve the first']);
    $conversationId = Conversation::query()->sole()->getKey();
    $this->actingAs($admin)->postJson(route('ai.approvals', $conversationId), ['approved' => true, 'always' => true])->assertOk();

    $this->actingAs($admin)->postJson(route('ai.store'), ['message' => 'And the second', 'conversation_id' => $conversationId])
        ->assertOk()
        ->assertJsonPath('messages.3.content', 'Done again.')
        ->assertJsonPath('messages.3.pending_approvals', []);

    expect($second->fresh()->status)->toBe(ExceptionStatusEnum::Resolved);

    // A new conversation asks again.
    $this->actingAs($admin)->postJson(route('ai.store'), ['message' => 'And the third'])
        ->assertJsonPath('messages.1.pending_approvals.0.id', 'call_3');

    expect($third->fresh()->status)->toBe(ExceptionStatusEnum::Open);

    $this->actingAs($admin)->delete(route('ai.conversations.destroy'), ['ids' => [$conversationId]]);
    expect(cache()->has('assistant-always-approved:'.$conversationId))->toBeFalse();
});

it('keeps the write tools on after an upgrade that kept an older tool list, and lets Settings → AI switch one off', function () {
    app(SettingsRepositoryService::class)->save(['ai_enabled_tools' => ['list-projects']]);

    expect(app(SettingsRepositoryService::class)->aiEnabledTools())->toBe(['list-projects', ...ExcubiaAssistantAgent::toolNames(AiToolKindEnum::Write)]);

    $this->actingAs(writerUser(RoleEnum::SuperAdmin))
        ->put(route('ai-settings.update'), ['tools' => ['list-projects', 'assign-items']])
        ->assertRedirect(route('ai-settings.edit'));

    expect(collect((new ExcubiaAssistantAgent(writerUser()))->tools())->map(fn (object $tool): string => $tool::class)->all())
        ->toBe([ExcubiaAssistantAgent::TOOLS['list-projects'], ExcubiaAssistantAgent::TOOLS['assign-items']]);

    $this->actingAs(writerUser(RoleEnum::SuperAdmin))->get(route('ai-settings.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('isWriteEnabled', true)
            ->where('isMcpWriteEnabled', true)
            ->where('tools.9.name', 'update-exception-status')
            ->where('tools.9.kind', 'write'));
});

it('lets a super-admin\'s key use every permission in the MCP tools, never the super-admin pass', function () {
    config(['excubia.mcp_enabled' => true]);
    $shop = Project::factory()->create(['slug' => 'shop']);
    Project::factory()->create(['slug' => 'vault', 'visibility' => ProjectVisibilityEnum::Admins]);
    $group = writerException($shop, 'a');
    // Decision 2026-11-26: the owner's ceiling is every PermissionEnum case, but the
    // super-admin pass (here: admins-only projects) never reaches the key.
    $owner = User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value);
    $plainKey = writerKey($owner);

    $projects = json_decode((string) writerMcp($plainKey, 'tools/call', ['name' => 'list-projects', 'arguments' => []], 'list-projects')
        ->assertOk()->json('result.content.0.text'), true);

    expect(collect($projects['projects'])->pluck('slug')->all())->toBe(['shop']);

    writerMcp($plainKey, 'tools/call', ['name' => 'update-exception-status', 'arguments' => ['project' => 'shop', 'ids' => [$group->id], 'status' => 'ignored']], 'update-exception-status')
        ->assertOk()
        ->assertJsonPath('result.isError', false);

    expect($group->fresh()->status)->toBe(ExceptionStatusEnum::Ignored);
});

it('offers a super-admin alone every scope, so they can create a key without another role', function () {
    $superAdmin = User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value);

    expect(ApiKeyScopeEnum::scopesCreatorMayGrant($superAdmin))->toBe(ApiKeyScopeEnum::cases());

    $this->actingAs($superAdmin)
        ->post(route('api-keys.store'), ['name' => 'Agent', 'scopes' => ['mcp:read']])
        ->assertSessionHasNoErrors();
});

it('offers no scope to a user without can-use-ai', function () {
    $reader = User::factory()->create()->assignRole(RoleEnum::Reader->value);

    expect(ApiKeyScopeEnum::scopesCreatorMayGrant($reader))->toBe([]);
});

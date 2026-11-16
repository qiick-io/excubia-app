<?php

declare(strict_types=1);

use App\Actions\CreateApiKeyAction;
use App\Actions\RotateProjectTokenAction;
use App\Ai\Agents\ExcubiaAssistantAgent;
use App\Ai\Tools\CompareReportsTool;
use App\Ai\Tools\GetLatestReportTool;
use App\Ai\Tools\GetReportHistoryTool;
use App\Ai\Tools\GetRuntimeSummaryTool;
use App\Ai\Tools\GetScheduleStatusTool;
use App\Ai\Tools\GetUpgradePlanTool;
use App\Ai\Tools\ListExceptionGroupsTool;
use App\Ai\Tools\ListProjectsTool;
use App\Ai\Tools\SearchFindingsTool;
use App\Data\ApiKeyData;
use App\Enums\AiToolKindEnum;
use App\Enums\ApiKeyScopeEnum;
use App\Enums\RoleEnum;
use App\Enums\SeverityEnum;
use App\Models\AlertLog;
use App\Models\ExceptionGroup;
use App\Models\HealthReport;
use App\Models\MaintenanceWindow;
use App\Models\Project;
use App\Models\RuntimeAggregate;
use App\Models\ScheduledTask;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;

/**
 * The assistant tools (#17, #234, #320), called directly and over the MCP server: what the read
 * tools return, the permission check, and that no token, API key, user data or path ever reaches
 * the data sent to the provider or to an MCP client, from a read or a write tool.
 */

/** Every assistant tool class, in the order of ExcubiaAssistantAgent::TOOLS. */
const ALL_ASSISTANT_TOOLS = [ListProjectsTool::class, SearchFindingsTool::class, GetLatestReportTool::class, CompareReportsTool::class, GetReportHistoryTool::class, GetUpgradePlanTool::class, ListExceptionGroupsTool::class, GetRuntimeSummaryTool::class, GetScheduleStatusTool::class];
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
});

function toolUser(RoleEnum $role = RoleEnum::Reader): User
{
    return User::factory()->create()->assignRole($role->value);
}

/**
 * @param  list<array<string, mixed>>  $findings
 */
function toolReport(Project $project, string $receivedAt, array $findings, array $upgradePlan = []): HealthReport
{
    return $project->healthReports()->create([
        'batch_id' => (string) Str::uuid(),
        'received_at' => $receivedAt,
        'generated_at' => $receivedAt,
        'worst_severity' => $findings === [] ? null : SeverityEnum::from($findings[0]['severity']),
        'report' => [
            'schemaVersion' => 1,
            'checks' => [['checkId' => 'composer-audit', 'name' => 'Composer audit', 'status' => $findings === [] ? 'pass' : 'fail', 'findings' => $findings]],
            'upgradePlan' => $upgradePlan,
        ],
    ]);
}

function toolFinding(string $subject, string $severity, ?string $advisoryId = null, string $status = 'fail'): array
{
    return ['checkId' => 'composer-audit', 'severity' => $severity, 'status' => $status, 'subject' => $subject, 'message' => "Advisory for {$subject}", 'fixHint' => "composer update {$subject}", 'evidenceUrl' => null, 'advisoryId' => $advisoryId];
}

/**
 * @return array<string, mixed>
 */
function runTool(string $tool, User $user, array $arguments = []): array
{
    return json_decode((string) (new $tool($user))->handle(new Request($arguments)), true);
}

it('lists the projects with their latest report summary', function () {
    $shop = Project::factory()->create(['name' => 'Shop', 'slug' => 'shop']);
    toolReport($shop, '2026-11-01 08:00:00', [toolFinding('monolog/monolog', 'low')]);
    toolReport($shop, '2026-11-02 08:00:00', [toolFinding('guzzlehttp/guzzle', 'high', 'GHSA-1')]);
    Project::factory()->create(['name' => 'Blog', 'slug' => 'blog']);

    $result = runTool(ListProjectsTool::class, toolUser());

    expect($result['projects'])->toHaveCount(2)
        ->and($result['projects'][0]['slug'])->toBe('blog')
        ->and($result['projects'][0]['latestReport'])->toBeNull()
        ->and($result['projects'][1]['latestReport']['worst_severity'])->toBe('high');
});

it('returns the latest report findings and the upgrade plan of a project', function () {
    $shop = Project::factory()->create(['slug' => 'shop']);
    toolReport($shop, '2026-11-02 08:00:00', [toolFinding('guzzlehttp/guzzle', 'high'), toolFinding('laravel/framework', 'critical', status: 'pass')], [['stepNumber' => 1, 'message' => 'composer update guzzlehttp/guzzle']]);

    $user = toolUser();
    $report = runTool(GetLatestReportTool::class, $user, ['project' => 'shop']);
    $plan = runTool(GetUpgradePlanTool::class, $user, ['project' => 'shop']);

    expect($report['report']['findings'])->toHaveCount(1)
        ->and($report['report']['findings'][0]['subject'])->toBe('guzzlehttp/guzzle')
        ->and($report['report']['findings'][0]['checkName'])->toBe('Composer audit')
        ->and($plan['upgradePlan'][0]['message'])->toBe('composer update guzzlehttp/guzzle')
        ->and(runTool(GetLatestReportTool::class, $user, ['project' => 'unknown']))->toHaveKey('error');
});

it('searches findings across projects by severity, package and advisory', function () {
    $shop = Project::factory()->create(['slug' => 'shop']);
    $blog = Project::factory()->create(['slug' => 'blog']);
    toolReport($shop, '2026-11-02 08:00:00', [toolFinding('guzzlehttp/guzzle', 'high', 'GHSA-1'), toolFinding('monolog/monolog', 'low')]);
    toolReport($blog, '2026-11-01 08:00:00', [toolFinding('guzzlehttp/guzzle', 'medium', 'GHSA-2')]);
    toolReport($blog, '2026-11-02 08:00:00', [toolFinding('guzzlehttp/guzzle', 'critical', 'GHSA-3')]);
    $user = toolUser();

    $highOrWorse = runTool(SearchFindingsTool::class, $user, ['minimum_severity' => 'high']);
    $guzzle = runTool(SearchFindingsTool::class, $user, ['package' => 'GUZZLE']);
    $advisory = runTool(SearchFindingsTool::class, $user, ['advisory_id' => 'GHSA-2', 'from' => '2026-10-01', 'to' => '2026-11-30']);

    expect(collect($highOrWorse['findings'])->pluck('advisoryId')->sort()->values()->all())->toBe(['GHSA-1', 'GHSA-3'])
        ->and($guzzle['findings'])->toHaveCount(2)
        ->and($guzzle['isTruncated'])->toBeFalse()
        ->and($advisory['findings'])->toHaveCount(1)
        ->and($advisory['findings'][0]['project'])->toBe('blog');
});

it('compares the latest two reports and returns the history', function () {
    $shop = Project::factory()->create(['slug' => 'shop']);
    $older = toolReport($shop, '2026-11-01 08:00:00', [toolFinding('monolog/monolog', 'low'), toolFinding('guzzlehttp/guzzle', 'high', 'GHSA-1')]);
    $newer = toolReport($shop, '2026-11-02 08:00:00', [toolFinding('guzzlehttp/guzzle', 'high', 'GHSA-1'), toolFinding('symfony/http-kernel', 'medium', 'GHSA-9')]);

    $user = toolUser();
    $comparison = runTool(CompareReportsTool::class, $user, ['project' => 'shop']);
    $history = runTool(GetReportHistoryTool::class, $user, ['project' => 'shop', 'limit' => 5]);

    expect($comparison['from']['id'])->toBe($older->id)
        ->and($comparison['to']['id'])->toBe($newer->id)
        ->and(collect($comparison['new'])->pluck('subject')->all())->toBe(['symfony/http-kernel'])
        ->and(collect($comparison['resolved'])->pluck('subject')->all())->toBe(['monolog/monolog'])
        ->and(collect($history['reports'])->pluck('id')->all())->toBe([$newer->id, $older->id]);
});

it('returns the exception groups, the runtime summary and the schedule status of a project', function () {
    leakyProject();
    $user = toolUser();

    $exceptions = runTool(ListExceptionGroupsTool::class, $user, ['project' => 'shop', 'range' => '6h']);
    $resolvedOnly = runTool(ListExceptionGroupsTool::class, $user, ['project' => 'shop', 'status' => 'resolved']);
    $runtime = runTool(GetRuntimeSummaryTool::class, $user, ['project' => 'shop']);
    $schedule = runTool(GetScheduleStatusTool::class, $user, ['project' => 'shop', 'range' => '7d']);

    expect($exceptions['range'])->toBe('6h')
        ->and($exceptions['exceptionGroups'][0])->toMatchArray(['class' => 'RuntimeException', 'message' => 'Payment failed', 'status' => 'open', 'range_count' => 0, 'count' => 4])
        ->and($exceptions['exceptionGroups'][0])->not->toHaveKeys(['frames', 'previous', 'fix', 'assignee', 'fingerprint'])
        ->and($resolvedOnly['exceptionGroups'])->toBe([])
        ->and($runtime['runtime']['range'])->toBe('24h')
        ->and($runtime['runtime']['requests'])->not->toHaveKey('timeline')
        ->and($runtime['runtime']['requests']['routes'][0])->toMatchArray(['route' => 'checkout.store', 'method' => 'POST', 'count' => 10])
        ->and($runtime['runtime'])->not->toHaveKeys(['servers', 'deprecations', 'deploys'])
        ->and($schedule['scheduledTasks'][0])->toMatchArray(['task' => 'artisan reports:send', 'expression' => '0 3 * * *', 'runs' => ['finished' => 0, 'failed' => 0, 'skipped' => 0]])
        ->and(runTool(GetRuntimeSummaryTool::class, $user, ['project' => 'unknown']))->toHaveKey('error');

    Project::factory()->create(['slug' => 'quiet']);
    expect(runTool(GetRuntimeSummaryTool::class, $user, ['project' => 'quiet'])['runtime'])->toBeNull();
});

it('gives nothing to a user without can-show-projects', function (string $tool) {
    $shop = Project::factory()->create(['slug' => 'shop']);
    toolReport($shop, '2026-11-02 08:00:00', [toolFinding('guzzlehttp/guzzle', 'high')]);

    expect(runTool($tool, User::factory()->create(), ['project' => 'shop']))
        ->toBe(['error' => 'The user lacks the can-show-projects permission.']);
})->with(ALL_ASSISTANT_TOOLS);

/**
 * A project with a push token, two reports, an exception group with stack frames (file paths)
 * assigned to a user, a request runtime row, a deprecation with its source file and a scheduled
 * task: everything a tool could leak.
 *
 * @return array{project: Project, token: string, assignee: User}
 */
function leakyProject(): array
{
    $shop = Project::factory()->create(['slug' => 'shop']);
    $token = app(RotateProjectTokenAction::class)->execute($shop);
    toolReport($shop, now()->subDay()->toDateTimeString(), [toolFinding('monolog/monolog', 'low')]);
    toolReport($shop, now()->toDateTimeString(), [toolFinding('guzzlehttp/guzzle', 'high', 'GHSA-1')]);
    $assignee = User::factory()->create(['email' => 'assignee@example.test', 'first_name' => 'Assigned', 'last_name' => 'Person']);

    ExceptionGroup::query()->create([
        'project_id' => $shop->id, 'fingerprint' => str_repeat('a', 64), 'environment' => 'production', 'class' => 'RuntimeException',
        'message' => 'Payment failed', 'frames' => [['file' => 'app/Actions/SecretPathAction.php', 'line' => 12, 'function' => 'pay', 'inApp' => true]],
        'previous' => [], 'context_kind' => 'request', 'context_name' => 'checkout.store', 'count' => 4,
        'first_seen_at' => now()->subHour(), 'last_seen_at' => now(), 'status' => 'open',
    ])->forceFill(['assigned_user_id' => $assignee->id])->save();

    foreach ([['request', ['route' => 'checkout.store', 'method' => 'POST', 'statusClass' => '2xx']], ['deprecation', ['source' => 'vendor/acme/secret-path/src/Legacy.php', 'message' => 'Legacy call', 'phpVersion' => '8.4']], ['server', ['server' => 'web-secret-host']]] as $position => [$type, $dimensions]) {
        RuntimeAggregate::query()->create([
            'project_id' => $shop->id, 'type' => $type, 'bucket' => now()->subMinutes(5)->startOfMinute(), 'sample_rate' => 1,
            'dimension_key' => str_repeat((string) $position, 64), 'dimensions' => $dimensions, 'count' => 10, 'error_count' => 1, 'total_ms' => 500, 'max_ms' => 90,
        ]);
    }

    ScheduledTask::query()->create([
        'project_id' => $shop->id, 'task_id' => hash('sha256', 'artisan reports:send'), 'task' => 'artisan reports:send',
        'expression' => '0 3 * * *', 'timezone' => 'UTC', 'environments' => ['production'], 'position' => 0,
    ]);

    return ['project' => $shop, 'token' => $token, 'assignee' => $assignee];
}

/**
 * What a tool output may never contain.
 */
function expectNoLeaks(string $output, Project $project, string $token, User $assignee, ?string $apiKey = null): void
{
    expect($output)->not->toContain($token)
        ->and($output)->not->toContain((string) $project->fresh()->push_token_hash)
        ->and($output)->not->toContain('push_token')
        ->and($output)->not->toContain('assignee@example.test')
        ->and($output)->not->toContain('Assigned Person')
        ->and($output)->not->toContain('reader@example.test')
        ->and($output)->not->toContain(base_path())
        ->and($output)->not->toContain('"path"')
        ->and($output)->not->toContain('SecretPathAction')
        ->and($output)->not->toContain('secret-path')
        ->and($output)->not->toContain('web-secret-host');

    if ($apiKey !== null) {
        expect($output)->not->toContain($apiKey)
            ->and($output)->not->toContain(hash('sha256', $apiKey));
    }
}

it('never puts tokens, user data or file paths in what the tools return', function () {
    ['project' => $shop, 'token' => $token, 'assignee' => $assignee] = leakyProject();
    $user = toolUser();
    $user->forceFill(['email' => 'reader@example.test'])->save();

    $output = collect(ALL_ASSISTANT_TOOLS)
        ->map(fn (string $tool): string => (string) (new $tool($user))->handle(new Request(['project' => 'shop'])))
        ->implode("\n");

    // The tools did read the data: the leak checks below are not vacuous.
    expect($output)->toContain('Payment failed')
        ->and($output)->toContain('checkout.store')
        ->and($output)->toContain('artisan reports:send');

    expectNoLeaks($output, $shop, $token, $assignee);
});

it('never puts tokens, API keys, user data or file paths in what the MCP tools return', function () {
    config(['excubia.mcp_enabled' => true]);
    ['project' => $shop, 'token' => $token, 'assignee' => $assignee] = leakyProject();
    $user = toolUser();
    $user->forceFill(['email' => 'reader@example.test'])->save();
    $apiKey = app(CreateApiKeyAction::class)->execute($user, new ApiKeyData('Claude Code', [ApiKeyScopeEnum::McpRead], null));
    $headers = ['Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2026-07-28', 'Authorization' => 'Bearer '.$apiKey];
    $meta = ['io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientCapabilities' => new stdClass, 'io.modelcontextprotocol/clientInfo' => ['name' => 'excubia-pest', 'version' => '1.0.0']];

    $outputs = [(string) $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => ['_meta' => $meta]], [...$headers, 'Mcp-Method' => 'tools/list'])->assertOk()->getContent()];

    foreach (ExcubiaAssistantAgent::toolNames(AiToolKindEnum::Read) as $name) {
        $outputs[] = (string) $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => ['project' => 'shop'], '_meta' => $meta]], [...$headers, 'Mcp-Method' => 'tools/call', 'Mcp-Name' => $name])
            ->assertOk()
            ->assertJsonPath('result.isError', false)
            ->getContent();
    }

    $output = implode("\n", $outputs);

    expect($output)->toContain('Payment failed')->and($output)->toContain('artisan reports:send');
    expectNoLeaks($output, $shop, $token, $assignee, $apiKey);
});

/**
 * One successful call of every write tool (#320) on the leaky project, with the arguments a model
 * would send: the assignee is given by email, so a leak of it would show.
 *
 * @return array<string, array<string, mixed>>
 */
function writeToolCalls(Project $shop): array
{
    $finding = $shop->findings()->firstOrCreate(['fingerprint' => str_repeat('f', 64)], [
        'check_id' => 'composer-audit', 'severity' => 'high', 'status' => 'open',
        'finding' => toolFinding('guzzlehttp/guzzle', 'high', 'GHSA-1'), 'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);
    AlertLog::query()->create(['uuid' => (string) Str::uuid(), 'project_id' => $shop->id, 'event' => 'finding.new', 'severity' => 'high', 'subject' => 'app/Secret/SecretPathAction.php', 'summary' => 'New finding', 'status' => AlertLog::SENT, 'deliveries' => []]);
    $groupId = ExceptionGroup::query()->where('project_id', $shop->id)->value('id');
    $windowId = MaintenanceWindow::query()->create(['project_id' => $shop->id, 'name' => 'Deploy', 'recurrence' => MaintenanceWindow::WEEKLY, 'timezone' => 'UTC', 'weekdays' => [1], 'start_time' => '02:00', 'duration_minutes' => 30])->id;

    return [
        'update-exception-status' => ['project' => 'shop', 'ids' => [$groupId], 'status' => 'resolved'],
        'assign-items' => ['project' => 'shop', 'exception_group_ids' => [$groupId], 'assignee' => 'assignee@example.test'],
        'dismiss-finding' => ['project' => 'shop', 'fingerprint' => $finding->fingerprint, 'reason' => 'Not used in production', 'until' => now()->addWeek()->toDateString()],
        'restore-finding' => ['project' => 'shop', 'fingerprint' => $finding->fingerprint],
        'mute-alert' => ['project' => 'shop', 'target' => 'rule', 'event' => 'finding.new', 'duration' => '24h'],
        'unmute-alert' => ['project' => 'shop', 'target' => 'maintenance_window', 'maintenance_window_id' => $windowId],
        'refresh-report' => ['project' => 'excubia'],
    ];
}

it('never puts tokens, API keys, user data or file paths in what the write tools return, in the assistant and over MCP', function () {
    config(['excubia.mcp_enabled' => true]);
    Queue::fake();
    ['project' => $shop, 'token' => $token, 'assignee' => $assignee] = leakyProject();
    $assignee->assignRole(RoleEnum::Reader->value);
    Project::factory()->create(['slug' => 'excubia', 'is_system' => true]);
    $admin = toolUser(RoleEnum::Admin);
    $admin->forceFill(['email' => 'reader@example.test'])->save();
    $apiKey = app(CreateApiKeyAction::class)->execute($admin, new ApiKeyData('Claude Code', [ApiKeyScopeEnum::McpRead, ApiKeyScopeEnum::McpWrite], null));
    $headers = ['Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2026-07-28', 'Authorization' => 'Bearer '.$apiKey];
    $meta = ['io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientCapabilities' => new stdClass, 'io.modelcontextprotocol/clientInfo' => ['name' => 'excubia-pest', 'version' => '1.0.0']];
    $outputs = [];

    foreach (writeToolCalls($shop) as $name => $arguments) {
        $class = ExcubiaAssistantAgent::TOOLS[$name];
        $outputs[] = $assistantOutput = (string) (new $class($admin))->handle(new Request($arguments));
        expect(json_decode($assistantOutput, true))->not->toHaveKey('error');
    }

    // The same calls again over MCP: the mute, window and finding exist again first.
    foreach (writeToolCalls($shop) as $name => $arguments) {
        $outputs[] = (string) $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments, '_meta' => $meta]], [...$headers, 'Mcp-Method' => 'tools/call', 'Mcp-Name' => $name])
            ->assertOk()
            ->assertJsonPath('result.isError', false)
            ->getContent();
    }

    expectNoLeaks(implode("\n", $outputs), $shop, $token, $assignee, $apiKey);
});

it('gives the agent the nine read tools and the write tools (#320) of the user who asks', function () {
    $tools = collect((new ExcubiaAssistantAgent(toolUser()))->tools())->map(fn (object $tool): string => $tool::class)->all();

    expect(array_slice($tools, 0, 9))->toBe(ALL_ASSISTANT_TOOLS)
        ->and(array_slice($tools, 9))->toBe(array_values(array_intersect_key(ExcubiaAssistantAgent::TOOLS, array_flip(ExcubiaAssistantAgent::toolNames(AiToolKindEnum::Write)))));
});

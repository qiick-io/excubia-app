<?php

declare(strict_types=1);

use App\Actions\RotateProjectTokenAction;
use App\Ai\Agents\ExcubiaAssistantAgent;
use App\Ai\Tools\CompareReportsTool;
use App\Ai\Tools\GetLatestReportTool;
use App\Ai\Tools\GetReportHistoryTool;
use App\Ai\Tools\GetUpgradePlanTool;
use App\Ai\Tools\ListProjectsTool;
use App\Ai\Tools\SearchFindingsTool;
use App\Enums\RoleEnum;
use App\Enums\SeverityEnum;
use App\Models\HealthReport;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;

/**
 * The read-only assistant tools (#17), called directly: what they return, the permission check,
 * and that no token, user data or path ever reaches the data sent to the provider.
 */
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

it('gives nothing to a user without can-show-projects', function (string $tool) {
    $shop = Project::factory()->create(['slug' => 'shop']);
    toolReport($shop, '2026-11-02 08:00:00', [toolFinding('guzzlehttp/guzzle', 'high')]);

    expect(runTool($tool, User::factory()->create(), ['project' => 'shop']))
        ->toBe(['error' => 'The user lacks the can-show-projects permission.']);
})->with([ListProjectsTool::class, SearchFindingsTool::class, GetLatestReportTool::class, CompareReportsTool::class, GetReportHistoryTool::class, GetUpgradePlanTool::class]);

it('never puts tokens, user data or file paths in what the tools return', function () {
    $shop = Project::factory()->create(['slug' => 'shop']);
    $token = app(RotateProjectTokenAction::class)->execute($shop);
    toolReport($shop, '2026-11-01 08:00:00', [toolFinding('monolog/monolog', 'low')]);
    toolReport($shop, '2026-11-02 08:00:00', [toolFinding('guzzlehttp/guzzle', 'high', 'GHSA-1')]);
    $user = toolUser();
    $user->forceFill(['email' => 'reader@example.test'])->save();

    $output = collect([ListProjectsTool::class, SearchFindingsTool::class, GetLatestReportTool::class, CompareReportsTool::class, GetReportHistoryTool::class, GetUpgradePlanTool::class])
        ->map(fn (string $tool): string => (string) (new $tool($user))->handle(new Request(['project' => 'shop'])))
        ->implode("\n");

    expect($output)->not->toContain($token)
        ->and($output)->not->toContain((string) $shop->fresh()->push_token_hash)
        ->and($output)->not->toContain('push_token')
        ->and($output)->not->toContain('reader@example.test')
        ->and($output)->not->toContain(base_path())
        ->and($output)->not->toContain('"path"');
});

it('gives the agent the six read-only tools of the user who asks', function () {
    $tools = collect((new ExcubiaAssistantAgent(toolUser()))->tools())->map(fn (object $tool): string => $tool::class)->all();

    expect($tools)->toBe([ListProjectsTool::class, SearchFindingsTool::class, GetLatestReportTool::class, CompareReportsTool::class, GetReportHistoryTool::class, GetUpgradePlanTool::class]);
});

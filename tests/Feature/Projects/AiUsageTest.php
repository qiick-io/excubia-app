<?php

declare(strict_types=1);

use App\Actions\RotateProjectTokenAction;
use App\Enums\RoleEnum;
use App\Mail\WeeklyDigestMail;
use App\Models\AiUsageDay;
use App\Models\AlertLog;
use App\Models\Project;
use App\Models\RuntimeAggregate;
use App\Models\RuntimeHourlyRollup;
use App\Models\User;
use App\Services\AiPriceService;
use App\Services\SettingsRepositoryService;
use App\Services\WeeklyDigestService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * AI usage and cost (#129): ingest and merge of the `ai` and `aiTool` runtime types, the daily
 * usage, cost from the price table (cache split, snapshots, unknown models), the AI tab, the
 * price table and budget settings, the AI alert rules and the weekly digest.
 */
beforeEach(function () {
    Http::preventStrayRequests();
    $this->seed(PermissionSeeder::class);
    Date::setTestNow('2026-11-20 12:30:00');
    $this->project = Project::factory()->create(['name' => 'Shop', 'slug' => 'shop']);
    $this->token = app(RotateProjectTokenAction::class)->execute($this->project);
    // Rotating the token alerted `project.token_changed`; the tests count only AI alerts.
    AlertLog::query()->delete();
});

/**
 * One `ai` entry as excubia-core sends it, an agent run of Claude by default.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function aiEntry(array $overrides = []): array
{
    $entry = [
        'type' => 'ai', 'bucket' => '2026-11-20T12:28:00Z', 'sampleRate' => 1, 'operation' => 'agent', 'agent' => 'App\\Ai\\SupportAgent',
        'provider' => 'anthropic', 'model' => 'claude-sonnet-4-6', 'count' => 10, 'errorCount' => 1, 'failoverCount' => 1,
        'totalMs' => 5000, 'maxMs' => 1200, 'histogram' => [0, 0, 0, 0, 0, 2, 4, 3, 1, 0, 0, 0],
        'inputTokens' => 1_000_000, 'outputTokens' => 100_000, 'cacheReadInputTokens' => 600_000, 'cacheWriteInputTokens' => 100_000,
        'reasoningTokens' => null, 'imageInputTokens' => null, 'imageOutputTokens' => null, 'audioSeconds' => null, 'searchUnits' => null,
        ...$overrides,
    ];

    // Core leaves the histogram out rather than sending null.
    return array_filter($entry, fn (mixed $value, string $key): bool => $key !== 'histogram' || $value !== null, ARRAY_FILTER_USE_BOTH);
}

/**
 * @param  list<array<string, mixed>>  $aggregates
 */
function pushAiRuntime(array $aggregates): void
{
    test()->postJson(route('api.runtime.store'), [
        'protocolVersion' => 1, 'type' => 'runtime', 'batchId' => (string) Str::uuid(), 'sentAt' => '2026-11-20T12:29:00Z',
        'runtime' => ['bucketSeconds' => 60, 'aggregates' => $aggregates],
    ], ['Authorization' => 'Bearer '.test()->token])->assertCreated();
}

function aiUser(RoleEnum $role): User
{
    return User::factory()->create()->assignRole($role->value);
}

/**
 * A day of usage of one model, as StoreAiUsageDaysAction stores it.
 */
function aiUsageDay(Project $project, string $day, float $outputTokens, string $model = 'claude-sonnet-4-6'): void
{
    AiUsageDay::query()->forceCreate([
        'project_id' => $project->id, 'day' => $day, 'usage_key' => hash('sha256', "anthropic\0{$model}"), 'provider' => 'anthropic', 'model' => $model,
        'calls' => 10, 'output_tokens' => $outputTokens,
    ]);
}

it('stores and merges AI entries with null-safe usage sums, keeps agent runs apart and rolls them up', function () {
    pushAiRuntime([aiEntry(), ['type' => 'aiTool', 'bucket' => '2026-11-20T12:28:00Z', 'sampleRate' => 1, 'agent' => 'App\\Ai\\SupportAgent', 'tool' => 'App\\Ai\\Tools\\OrderTool', 'count' => 3, 'errorCount' => 1, 'totalMs' => 30, 'maxMs' => 20]]);
    pushAiRuntime([
        aiEntry(['count' => 2, 'errorCount' => 2, 'failoverCount' => 2, 'inputTokens' => null, 'outputTokens' => null, 'cacheReadInputTokens' => null, 'cacheWriteInputTokens' => null, 'reasoningTokens' => 50, 'maxMs' => 3000, 'histogram' => null]),
        // The same model without an agent (embeddings) is its own series.
        array_diff_key(aiEntry(['operation' => 'embeddings', 'model' => 'text-embedding-3-small', 'provider' => 'openai', 'count' => 1, 'errorCount' => 0, 'failoverCount' => 0, 'inputTokens' => 1000, 'outputTokens' => 0, 'cacheReadInputTokens' => null, 'cacheWriteInputTokens' => null]), ['agent' => true]),
    ]);

    $agent = RuntimeAggregate::query()->where('type', 'ai')->where('dimensions->operation', 'agent')->sole();

    expect($agent->dimensions)->toBe(['operation' => 'agent', 'provider' => 'anthropic', 'model' => 'claude-sonnet-4-6', 'agent' => 'App\\Ai\\SupportAgent'])
        ->and([$agent->count, $agent->error_count, $agent->total_ms, $agent->max_ms])->toBe([12, 3, 10000.0, 3000.0])
        ->and($agent->histogram)->toBeNull()
        ->and($agent->metrics)->toMatchArray([
            'failoverCount' => 3, 'inputTokens' => 1_000_000, 'cacheReadInputTokens' => 600_000, 'reasoningTokens' => 50,
            'imageInputTokens' => null, 'audioSeconds' => null,
        ])
        ->and(RuntimeAggregate::query()->where('type', 'ai')->where('dimensions->operation', 'embeddings')->sole()->dimensions)->not->toHaveKey('agent')
        ->and(RuntimeAggregate::query()->where('type', 'aiTool')->sole()->dimensions)->toBe(['agent' => 'App\\Ai\\SupportAgent', 'tool' => 'App\\Ai\\Tools\\OrderTool']);

    // Hourly rollups (#137) hold the AI types like every other type.
    expect(RuntimeHourlyRollup::query()->where('type', 'ai')->sole()->only(['count', 'error_count', 'total_ms']))->toBe(['count' => 13, 'error_count' => 3, 'total_ms' => 15000.0]);

    // Daily usage per provider and model, null counts adding nothing.
    $day = AiUsageDay::query()->where('model', 'claude-sonnet-4-6')->sole();

    expect([$day->day->toDateString(), $day->calls, $day->errors, $day->input_tokens, $day->cache_read_input_tokens, $day->reasoning_tokens])
        ->toBe(['2026-11-20', 12.0, 3.0, 1_000_000.0, 600_000.0, 50.0])
        ->and(AiUsageDay::query()->count())->toBe(2);
});

it('scales the daily usage by the sample rate', function () {
    pushAiRuntime([aiEntry(['sampleRate' => 0.5, 'count' => 2, 'errorCount' => 0, 'failoverCount' => 0, 'inputTokens' => 100, 'outputTokens' => 10, 'cacheReadInputTokens' => 0, 'cacheWriteInputTokens' => 0])]);

    expect(AiUsageDay::query()->sole()->only(['calls', 'input_tokens', 'output_tokens']))->toBe(['calls' => 4.0, 'input_tokens' => 200.0, 'output_tokens' => 20.0]);
});

it('prices uncached input, cache reads, cache writes and output at their own rates', function () {
    $prices = app(AiPriceService::class);
    $usage = ['inputTokens' => 1_000_000.0, 'outputTokens' => 100_000.0, 'cacheReadInputTokens' => 600_000.0, 'cacheWriteInputTokens' => 100_000.0, 'reasoningTokens' => 0.0, 'imageInputTokens' => 0.0, 'imageOutputTokens' => 0.0, 'audioSeconds' => 0.0, 'searchUnits' => 0.0];

    // claude-sonnet-4-6: 300k uncached × $3 + 600k × $0.30 + 100k × $3.75 + 100k × $15 per 1M.
    expect($prices->cost('anthropic', 'claude-sonnet-4-6', $usage))->toEqualWithDelta(0.9 + 0.18 + 0.375 + 1.5, 0.000001)
        // A dated snapshot takes its model's row, case aside; another provider or model has no price.
        ->and($prices->cost('Anthropic', 'claude-sonnet-4-6-20260101', $usage))->toEqualWithDelta(2.955, 0.000001)
        ->and($prices->cost('openai', 'claude-sonnet-4-6', $usage))->toBeNull()
        ->and($prices->cost('anthropic', 'claude-sonnet-4-6-custom', $usage))->toBeNull()
        ->and($prices->cost('anthropic', 'unknown', array_map(fn (): float => 0.0, $usage)))->toBe(0.0);

    // Without cache prices the input price applies; audio and search units by their unit.
    app(SettingsRepositoryService::class)->save(['ai_prices' => [
        ['provider' => 'openai', 'model' => 'gpt-test', 'input' => 2, 'output' => 8, 'cache_read' => null, 'cache_write' => null, 'reasoning' => 8],
        ['provider' => 'openai', 'model' => 'whisper-1', 'input' => 0, 'output' => 0, 'audio_second' => 0.0001],
    ]]);
    $prices = app(AiPriceService::class);

    expect($prices->cost('openai', 'gpt-test', [...$usage, 'reasoningTokens' => 1_000_000.0]))->toEqualWithDelta(2.0 + 0.8 + 8.0, 0.000001)
        ->and($prices->cost('openai', 'whisper-1', [...array_map(fn (): float => 0.0, $usage), 'audioSeconds' => 600.0]))->toEqualWithDelta(0.06, 0.000001)
        ->and($prices->cost('anthropic', 'claude-sonnet-4-6', $usage))->toBeNull();
});

it('shows the AI tab with spend, calls, failovers, p95, token split, breakdowns and tools to readers', function () {
    pushAiRuntime([
        aiEntry(),
        aiEntry(['provider' => 'acme', 'model' => 'secret-model', 'agent' => 'App\\Ai\\OtherAgent', 'count' => 5, 'errorCount' => 0, 'failoverCount' => 0, 'histogram' => null]),
        ['type' => 'aiTool', 'bucket' => '2026-11-20T12:28:00Z', 'sampleRate' => 1, 'agent' => 'App\\Ai\\SupportAgent', 'tool' => 'App\\Ai\\Tools\\OrderTool', 'count' => 3, 'errorCount' => 1, 'totalMs' => 30, 'maxMs' => 20],
    ]);

    $this->actingAs(aiUser(RoleEnum::Reader))
        ->get(route('projects.view', ['project' => 'shop', 'view' => 'ai']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/show')
            ->where('view', 'ai')
            ->where('runtime', null)
            ->where('canManageAlerts', false)
            ->where('ai.totals.count', 15)
            ->where('ai.totals.errors', 1)
            ->where('ai.totals.failovers', 1)
            ->where('ai.totals.uncachedInputTokens', 600_000)
            ->where('ai.totals.cacheReadTokens', 1_200_000)
            ->where('ai.totals.cacheWriteTokens', 200_000)
            ->where('ai.totals.outputTokens', 200_000)
            ->where('ai.totals.cost', 2.955)
            ->where('ai.totals.hasUnpriced', true)
            ->where('ai.totals.p95', fn (?float $p95): bool => $p95 !== null && $p95 > 500 && $p95 <= 1200)
            ->where('ai.models.0.model', 'claude-sonnet-4-6')
            ->where('ai.models.0.cost', 2.955)
            ->where('ai.models.1.model', 'secret-model')
            ->where('ai.models.1.cost', null)
            ->where('ai.providers', fn ($providers): bool => collect($providers)->pluck('provider')->sort()->values()->all() === ['acme', 'anthropic'])
            ->where('ai.agents.0.agent', 'App\\Ai\\SupportAgent')
            ->where('ai.tools.0.tool', 'App\\Ai\\Tools\\OrderTool')
            ->where('ai.tools.0.errors', 1)
            ->where('ai.timeline', fn ($points): bool => abs(collect($points)->sum('cost') - 2.955) < 0.0001 && collect($points)->sum('count') === 15)
            ->where('ai.month.cost', 2.955)
            ->where('ai.month.budget', null)
            ->where('ai.pricesUpdatedOn', AiPriceService::DEFAULTS_UPDATED_ON));
});

it('sends an empty AI tab to a project without AI usage and keeps the runtime tab free of AI rows', function () {
    $reader = aiUser(RoleEnum::Reader);

    $this->actingAs($reader)->get(route('projects.view', ['project' => 'shop', 'view' => 'ai']))
        ->assertInertia(fn (Assert $page) => $page->where('ai', null));

    pushAiRuntime([aiEntry()]);

    $this->actingAs($reader)->get(route('projects.view', ['project' => 'shop', 'view' => 'runtime']))
        ->assertInertia(fn (Assert $page) => $page->where('ai', null)->where('runtime.isSampled', false)->where('runtime.requests.count', 0));
});

it('lets alert managers set the monthly AI budget, not readers', function () {
    $this->actingAs(aiUser(RoleEnum::Reader))->put(route('projects.ai-budget.update', $this->project), ['ai_monthly_budget' => 50])->assertForbidden();
    $this->actingAs(aiUser(RoleEnum::Admin))->put(route('projects.ai-budget.update', $this->project), ['ai_monthly_budget' => -1])->assertSessionHasErrors('ai_monthly_budget');
    $this->actingAs(aiUser(RoleEnum::Admin))->put(route('projects.ai-budget.update', $this->project), ['ai_monthly_budget' => '49.999'])->assertSessionHasNoErrors();

    expect($this->project->fresh()->ai_monthly_budget)->toBe(50.0);

    $this->actingAs(aiUser(RoleEnum::Admin))->put(route('projects.ai-budget.update', $this->project), ['ai_monthly_budget' => ''])->assertSessionHasNoErrors();

    expect($this->project->fresh()->ai_monthly_budget)->toBeNull();
});

it('edits the AI price table with can-manage-alerts and restores nothing it was not sent', function () {
    $this->actingAs(aiUser(RoleEnum::Reader))->get(route('ai-prices.edit'))->assertForbidden();

    $admin = aiUser(RoleEnum::Admin);
    $this->actingAs($admin)->get(route('ai-prices.edit'))->assertInertia(fn (Assert $page) => $page
        ->component('settings/ai-prices')
        ->where('updatedOn', AiPriceService::DEFAULTS_UPDATED_ON)
        ->has('prices', count(app(AiPriceService::class)->defaults()))
        ->where('prices.0.model', 'claude-opus-5-5')
        ->has('defaults'));

    $row = ['provider' => 'acme', 'model' => 'secret-model', 'input' => 1, 'output' => 2, 'cache_read' => '', 'cache_write' => 0.5];
    $this->actingAs($admin)->put(route('ai-prices.update'), ['prices' => [$row, [...$row, 'provider' => 'ACME']]])->assertSessionHasErrors('prices.1.model');
    $this->actingAs($admin)->put(route('ai-prices.update'), ['prices' => [[...$row, 'output' => '']]])->assertSessionHasErrors('prices.0.output');
    $this->actingAs($admin)->put(route('ai-prices.update'), ['prices' => [[...$row, 'provider' => ' Acme ']]])->assertSessionHasNoErrors()->assertRedirect(route('ai-prices.edit'));

    $prices = app(AiPriceService::class);

    expect($prices->prices())->toHaveCount(1)
        ->and($prices->priceFor('acme', 'secret-model'))->toMatchArray(['provider' => 'acme', 'input' => 1.0, 'output' => 2.0, 'cache_read' => null, 'cache_write' => 0.5])
        ->and($prices->priceFor('anthropic', 'claude-sonnet-4-6'))->toBeNull()
        ->and($prices->updatedOn())->toBe('2026-11-20');
});

it('saves the AI alert rules in Settings → Integrations', function () {
    $channels = ['channels' => array_fill_keys(['webhook', 'slack', 'telegram'], ['minimum_severity' => 'high', 'event_types' => ['ai.budget_exceeded']])];

    $this->actingAs(aiUser(RoleEnum::Admin))->put(route('project.integrations.update'), [...$channels, 'alert_ai_spend_spike_percent' => 50])->assertSessionHasErrors('alert_ai_spend_spike_percent');
    $this->actingAs(aiUser(RoleEnum::Admin))->put(route('project.integrations.update'), [...$channels, 'alert_ai_spend_spike_percent' => 300, 'alert_ai_error_rate_above' => 10])->assertSessionHasNoErrors();

    $this->actingAs(aiUser(RoleEnum::Admin))->get(route('project.integrations.edit'))->assertInertia(fn (Assert $page) => $page
        ->where('project.alert_ai_spend_spike_percent', 300)
        ->where('project.alert_ai_error_rate_above', 10));
});

it('alerts once at 80 % and once at 100 % of the monthly budget, never back at 80 %', function () {
    // $15 per 1M output tokens: 1M is $15.
    aiUsageDay($this->project, '2026-11-02', 1_000_000);
    aiUsageDay($this->project, '2026-10-30', 10_000_000);

    $this->artisan('excubia:evaluate-runtime-alerts')->expectsOutputToContain('No runtime alert rule is on')->assertSuccessful();

    $this->project->forceFill(['ai_monthly_budget' => 18])->save();
    $this->artisan('excubia:evaluate-runtime-alerts')->assertSuccessful();
    $this->artisan('excubia:evaluate-runtime-alerts')->assertSuccessful();

    expect(AlertLog::query()->pluck('event')->all())->toBe(['ai.budget_warning']);

    aiUsageDay($this->project, '2026-11-20', 300_000);
    $this->artisan('excubia:evaluate-runtime-alerts')->assertSuccessful();
    $this->artisan('excubia:evaluate-runtime-alerts')->assertSuccessful();

    expect(AlertLog::query()->orderBy('id')->get()->map(fn (AlertLog $log): string => "{$log->event}|{$log->subject}")->all())
        ->toBe(['ai.budget_warning|ai-budget:2026-11:80', 'ai.budget_exceeded|ai-budget:2026-11:100'])
        ->and(AlertLog::query()->latest('id')->first()?->summary)->toContain('$19.50');
});

it('jumps straight to 100 % without a late 80 % alert, and starts over next month', function () {
    $this->project->forceFill(['ai_monthly_budget' => 10])->save();
    aiUsageDay($this->project, '2026-11-20', 1_000_000);

    $this->artisan('excubia:evaluate-runtime-alerts')->assertSuccessful();
    $this->artisan('excubia:evaluate-runtime-alerts')->assertSuccessful();

    expect(AlertLog::query()->pluck('event')->all())->toBe(['ai.budget_exceeded']);

    Date::setTestNow('2026-12-01 09:00:00');
    aiUsageDay($this->project, '2026-12-01', 600_000);
    $this->artisan('excubia:evaluate-runtime-alerts')->assertSuccessful();

    expect(AlertLog::query()->orderBy('id')->pluck('subject')->all())->toBe(['ai-budget:2026-11:100', 'ai-budget:2026-12:80']);
});

it('alerts on a spend spike against the 7-day average once a day', function () {
    foreach (range(13, 19) as $day) {
        aiUsageDay($this->project, "2026-11-{$day}", 100_000);
    }

    // $1.50 a day on average; today $6 is 400 %.
    aiUsageDay($this->project, '2026-11-20', 400_000);
    app(SettingsRepositoryService::class)->save(['alert_ai_spend_spike_percent' => 500]);
    $this->artisan('excubia:evaluate-runtime-alerts')->assertSuccessful();

    expect(AlertLog::query()->count())->toBe(0);

    app(SettingsRepositoryService::class)->save(['alert_ai_spend_spike_percent' => 300]);
    $this->artisan('excubia:evaluate-runtime-alerts')->assertSuccessful();
    $this->artisan('excubia:evaluate-runtime-alerts')->assertSuccessful();

    expect(AlertLog::query()->sole()->only(['event', 'subject']))->toBe(['event' => 'ai.spend_spike', 'subject' => 'ai-spike:2026-11-20']);
});

it('alerts when the AI error rate of the last hour is above the threshold, with enough calls', function () {
    app(SettingsRepositoryService::class)->save(['alert_ai_error_rate_above' => 20]);
    pushAiRuntime([aiEntry(['count' => 10, 'errorCount' => 5, 'failoverCount' => 0])]);
    $this->artisan('excubia:evaluate-runtime-alerts')->assertSuccessful();

    // 10 calls are too few to tell.
    expect(AlertLog::query()->count())->toBe(0);

    pushAiRuntime([aiEntry(['count' => 20, 'errorCount' => 2, 'failoverCount' => 0])]);
    $this->artisan('excubia:evaluate-runtime-alerts')->assertSuccessful();
    $this->artisan('excubia:evaluate-runtime-alerts')->assertSuccessful();

    expect(AlertLog::query()->sole()->only(['event', 'subject']))->toBe(['event' => 'ai.error_rate', 'subject' => 'ai-errors'])
        ->and(AlertLog::query()->sole()->summary)->toContain('23.3%');
});

it('puts the AI cost trend in the weekly digest, with no message or prompt', function () {
    aiUsageDay($this->project, '2026-11-16', 1_000_000);
    aiUsageDay($this->project, '2026-11-08', 500_000);
    aiUsageDay($this->project, '2026-11-17', 1_000, 'unpriced-model');
    $reader = aiUser(RoleEnum::Reader);

    $digest = app(WeeklyDigestService::class)->forUser($reader);

    expect($digest)->toHaveCount(1)
        ->and($digest[0]['aiCost'])->toBe(['week' => 15.0, 'previousWeek' => 7.5, 'change' => 100, 'hasUnpriced' => true]);

    $html = (new WeeklyDigestMail($digest))->render();

    expect($html)->toContain('AI spend (estimate)')
        ->toContain('$15.00 in the last 7 days, $7.50 in the 7 days before (+100%)')
        ->toContain('Some models have no price');
});

it('leaves projects without AI usage out of the digest AI section', function () {
    expect(app(WeeklyDigestService::class)->forUser(aiUser(RoleEnum::Reader)))->toBeNull();
});

it('keeps the daily AI usage for two months even with a shorter runtime retention', function () {
    aiUsageDay($this->project, '2026-09-25', 1);
    aiUsageDay($this->project, '2026-09-10', 1);

    $this->artisan('excubia:prune-telemetry')->assertSuccessful();

    expect(AiUsageDay::query()->pluck('day')->map->toDateString()->all())->toBe(['2026-09-25']);
});

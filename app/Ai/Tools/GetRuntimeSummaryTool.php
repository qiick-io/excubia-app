<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Enums\TimeRangeEnum;
use App\Services\AssistantQueryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * The runtime of a project over a time range (#234): the tables of the Runtime tab, without charts.
 */
class GetRuntimeSummaryTool extends AssistantTool
{
    public function description(): Stringable|string
    {
        return 'Get the runtime summary of one project over a time range (default the last 24 hours): request totals with p50, p95 and p99, the slowest routes, slow queries, jobs, queue wait, scheduled task runs, outgoing HTTP hosts, Artisan commands, cache hit rate, mail and notifications. Counts are estimates when isSampled is true. runtime is null when the project never sent runtime data.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('Project slug, from ListProjects.')->required(),
            'range' => $schema->string()->enum(array_column(TimeRangeEnum::cases(), 'value'))->description('Time range, ending now. Default 24h.'),
        ];
    }

    protected function rules(Request $request): array
    {
        return [
            'project' => ['required', 'string'],
            'range' => ['nullable', Rule::in(array_column(TimeRangeEnum::cases(), 'value'))],
        ];
    }

    protected function read(AssistantQueryService $query, Request $request): ?array
    {
        return $query->runtimeSummary(
            (string) $request['project'],
            TimeRangeEnum::tryFrom((string) ($request['range'] ?? '')) ?? TimeRangeEnum::DEFAULT,
        );
    }
}

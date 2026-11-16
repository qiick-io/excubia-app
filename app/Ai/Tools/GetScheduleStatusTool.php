<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Enums\TimeRangeEnum;
use App\Services\AssistantQueryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * The scheduled tasks of a project and their status (#234), as the Schedule tab lists them.
 */
class GetScheduleStatusTool extends AssistantTool
{
    public function description(): Stringable|string
    {
        return 'Get the scheduled tasks of one project: command, cron expression, timezone, status (pending, on_time, late, missed, failed, skipped, scheduler_down, not_expected, paused or unknown), last and next due time, last run and its outcome, and the runs per outcome (finished, failed, skipped) in a time range (default the last 24 hours).';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('Project slug, from ListProjects.')->required(),
            'range' => $schema->string()->enum(array_column(TimeRangeEnum::cases(), 'value'))->description('Time range of the run counts, ending now. Default 24h.'),
        ];
    }

    protected function read(AssistantQueryService $query, Request $request): ?array
    {
        return $query->scheduleStatus(
            (string) $request['project'],
            TimeRangeEnum::tryFrom((string) ($request['range'] ?? '')) ?? TimeRangeEnum::DEFAULT,
        );
    }
}

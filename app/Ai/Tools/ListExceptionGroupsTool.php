<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Enums\ExceptionStatusEnum;
use App\Enums\TimeRangeEnum;
use App\Services\AssistantQueryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * The exception groups of a project seen in a time range (#234), as the Exceptions tab lists them.
 */
class ListExceptionGroupsTool extends AssistantTool
{
    public function description(): Stringable|string
    {
        return 'List the exception groups of one project seen in a time range (default the last 24 hours), open first, then the latest: class, message, environment, where it happened (route, job or command), occurrences in the range and in total, first and last seen, the releases, and the status. Stack frames are not included. At most '.AssistantQueryService::EXCEPTION_GROUP_LIMIT.' groups.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('Project slug, from ListProjects.')->required(),
            'range' => $schema->string()->enum(array_column(TimeRangeEnum::cases(), 'value'))->description('Time range, ending now. Default 24h.'),
            'status' => $schema->string()->enum(array_column(ExceptionStatusEnum::cases(), 'value'))->description('Only groups with this status.'),
        ];
    }

    protected function rules(Request $request): array
    {
        return [
            'project' => ['required', 'string'],
            'range' => ['nullable', Rule::in(array_column(TimeRangeEnum::cases(), 'value'))],
            'status' => ['nullable', Rule::in(array_column(ExceptionStatusEnum::cases(), 'value'))],
        ];
    }

    protected function read(AssistantQueryService $query, Request $request): ?array
    {
        return $query->exceptionGroups(
            (string) $request['project'],
            TimeRangeEnum::tryFrom((string) ($request['range'] ?? '')) ?? TimeRangeEnum::DEFAULT,
            ExceptionStatusEnum::tryFrom((string) ($request['status'] ?? '')),
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Services\AssistantQueryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * The report summaries of a project over time, newest first.
 */
class GetReportHistoryTool extends AssistantTool
{
    public function description(): Stringable|string
    {
        return 'Get the history of one project: the summaries of its stored reports, newest first (id, received time, worst severity, counts per severity). Use the ids with CompareReports.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('Project slug, from ListProjects.')->required(),
            'limit' => $schema->integer()->description('How many reports, 1 to 100. Default 20.'),
        ];
    }

    protected function read(AssistantQueryService $query, Request $request): ?array
    {
        return $query->reportHistory((string) $request['project'], max(1, min(100, (int) ($request['limit'] ?? 20))));
    }
}

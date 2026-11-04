<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Services\AssistantQueryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * What changed between two reports of a project: new and resolved findings.
 */
class CompareReportsTool extends AssistantTool
{
    public function description(): Stringable|string
    {
        return 'Compare two reports of one project and return the findings that are new and those that are resolved. Without report ids it compares the latest report with the previous one.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('Project slug, from ListProjects.')->required(),
            'from_report_id' => $schema->integer()->description('Older report id, from GetReportHistory.'),
            'to_report_id' => $schema->integer()->description('Newer report id, from GetReportHistory.'),
        ];
    }

    protected function read(AssistantQueryService $query, Request $request): ?array
    {
        return $query->compareReports(
            (string) $request['project'],
            isset($request['from_report_id']) ? (int) $request['from_report_id'] : null,
            isset($request['to_report_id']) ? (int) $request['to_report_id'] : null,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Services\AssistantQueryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * The findings that warn or fail in a project's latest report, and its skipped checks.
 */
class GetLatestReportTool extends AssistantTool
{
    public function description(): Stringable|string
    {
        return 'Get the latest report of one project: its summary, every finding that warns or fails (check, severity, subject, message, fix hint, evidence URL, advisory ID) and the names of skipped checks.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('Project slug, from ListProjects.')->required(),
        ];
    }

    protected function read(AssistantQueryService $query, Request $request): ?array
    {
        return $query->latestReport((string) $request['project']);
    }
}

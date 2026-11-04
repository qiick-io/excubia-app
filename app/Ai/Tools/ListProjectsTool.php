<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Services\AssistantQueryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Every project with whether it is stale and the summary of its latest report.
 */
class ListProjectsTool extends AssistantTool
{
    public function description(): Stringable|string
    {
        return 'List every project: name, slug, enabled, stale, last report time, and the latest report summary (worst severity and the count of findings per severity). Call it first to learn the project slugs.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function read(AssistantQueryService $query, Request $request): ?array
    {
        return ['projects' => $query->projects()];
    }
}

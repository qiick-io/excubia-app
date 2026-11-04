<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Services\AssistantQueryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * The suggested upgrade order of a project's latest report.
 */
class GetUpgradePlanTool extends AssistantTool
{
    public function description(): Stringable|string
    {
        return 'Get the upgrade plan of one project: the ordered upgrade steps suggested by its latest report.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('Project slug, from ListProjects.')->required(),
        ];
    }

    protected function read(AssistantQueryService $query, Request $request): ?array
    {
        return $query->upgradePlan((string) $request['project']);
    }
}

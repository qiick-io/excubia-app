<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Jobs\RunSystemReportJob;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * "Refresh now" (#320, #70): queues a new report of the system project, as its button does.
 * Only the system project can be refreshed from Excubia; the other projects push their reports.
 */
class RefreshReportTool extends AssistantWriteTool
{
    public function description(): Stringable|string
    {
        return 'Ask for a new report of the Excubia system project now ("Refresh now"); it runs in the background and takes a minute. Other projects send their reports themselves and cannot be refreshed. Needs the user\'s approval.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('Slug of the system project, from ListProjects.')->required(),
        ];
    }

    protected function ability(Request $request): string
    {
        return 'refreshReport';
    }

    protected function rules(Request $request): array
    {
        return [];
    }

    /**
     * Queues RunSystemReportJob (unique: a refresh already waiting is not queued twice).
     */
    protected function write(Project $project, Request $request): array
    {
        RunSystemReportJob::dispatch();

        return ['project' => $project->slug, 'isStarted' => true];
    }
}

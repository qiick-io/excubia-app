<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Actions\UpdateFindingDismissalAction;
use App\Models\Finding;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Removes the dismissal (or snooze) of a finding (#320), with the action of the Findings tab (#18).
 */
class RestoreFindingTool extends AssistantWriteTool
{
    public function description(): Stringable|string
    {
        return 'Remove the dismissal or snooze of a finding of one project, so it counts and alerts again. Identify it by its fingerprint from SearchFindings. Needs the user\'s approval.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('Project slug, from ListProjects.')->required(),
            'fingerprint' => $schema->string()->description('The finding fingerprint, from SearchFindings.')->required(),
        ];
    }

    protected function ability(Request $request): string
    {
        return 'manageFindings';
    }

    protected function rules(Request $request): array
    {
        return ['fingerprint' => ['required', 'string']];
    }

    protected function write(Project $project, Request $request): array
    {
        $finding = Finding::query()->where('project_id', $project->id)->where('fingerprint', (string) $request['fingerprint'])->first();

        if ($finding === null) {
            return ['error' => 'No finding of this project has this fingerprint. Call SearchFindings for the fingerprints.'];
        }

        if ($finding->dismissed_at === null) {
            return ['project' => $project->slug, 'fingerprint' => $finding->fingerprint, 'isDismissed' => false, 'note' => 'The finding was not dismissed: nothing changed.'];
        }

        app(UpdateFindingDismissalAction::class)->execute($finding, $this->user, null);

        return ['project' => $project->slug, 'fingerprint' => $finding->fingerprint, 'isDismissed' => false];
    }
}

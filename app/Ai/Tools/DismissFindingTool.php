<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Actions\UpdateFindingDismissalAction;
use App\Models\Finding;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Dismisses a finding of a project with a reason, until a day or for good (#320), with the action
 * and the rules of the Findings tab (#18): `can-dismiss-findings`, a reason up to 500 characters,
 * a last day from today on. A dismissal with a last day is a snooze. RestoreFindingTool undoes it.
 */
class DismissFindingTool extends AssistantWriteTool
{
    public function description(): Stringable|string
    {
        return 'Dismiss a finding of one project with a reason, for good or until a day (a snooze). A dismissed finding stays in the history but raises no alerts and leaves the worst severity. Identify it by its fingerprint from SearchFindings. Needs the user\'s approval.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('Project slug, from ListProjects.')->required(),
            'fingerprint' => $schema->string()->description('The finding fingerprint, from SearchFindings.')->required(),
            'reason' => $schema->string()->description('Why it is dismissed, in the user\'s words (at most 500 characters).')->required(),
            'until' => $schema->string()->description('Last day of the dismissal, YYYY-MM-DD, today or later. Leave out to dismiss for good.'),
        ];
    }

    protected function ability(Request $request): string
    {
        return 'manageFindings';
    }

    protected function rules(Request $request): array
    {
        return [
            'fingerprint' => ['required', 'string'],
            'reason' => ['required', 'string', 'max:500'],
            'until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
        ];
    }

    protected function write(Project $project, Request $request): array
    {
        $finding = Finding::query()->where('project_id', $project->id)->where('fingerprint', (string) $request['fingerprint'])->first();

        if ($finding === null) {
            return ['error' => 'No finding of this project has this fingerprint. Call SearchFindings for the fingerprints.'];
        }

        $until = $request->filled('until') ? CarbonImmutable::parse($request->string('until')->toString()) : null;
        app(UpdateFindingDismissalAction::class)->execute($finding, $this->user, trim((string) $request['reason']), $until);

        return ['project' => $project->slug, 'fingerprint' => $finding->fingerprint, 'isDismissed' => true, 'until' => $until?->toDateString()];
    }
}

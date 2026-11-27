<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Actions\BulkAssignProjectItemsAction;
use App\Http\Requests\BulkAssignFindingsRequest;
use App\Models\ExceptionGroup;
use App\Models\Finding;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectAssigneeService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Assigns exception groups or findings of one project to a user, or unassigns them (#320), with
 * the bulk action of the Exceptions and Findings tabs (#254, #276): the permission of the list
 * (`can-manage-exceptions` or `can-dismiss-findings`), at most 200 items, an assignee who is
 * active and can see the project.
 *
 * The assignee is "me", "nobody" or an email address the user gave. The result never names
 * the assignee, and an unknown email and a user who cannot see the project get the same error,
 * so the tool cannot be used to look users up.
 */
class AssignItemsTool extends AssistantWriteTool
{
    public function description(): Stringable|string
    {
        return 'Assign exception groups (by id, from ListExceptionGroups) or findings (by fingerprint, from SearchFindings) of one project to a user, or unassign them. The assignee is "me" (the user you are talking to), "nobody" to unassign, or the email address of a user who can see the project. Needs the user\'s approval.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('Project slug, from ListProjects.')->required(),
            'exception_group_ids' => $schema->array()->items($schema->integer())->description('Exception group ids. Give these or finding_fingerprints.'),
            'finding_fingerprints' => $schema->array()->items($schema->string())->description('Finding fingerprints. Give these or exception_group_ids.'),
            'assignee' => $schema->string()->description('"me", "nobody", or an email address.')->required(),
        ];
    }

    protected function ability(Request $request): string
    {
        return $request->filled('exception_group_ids') ? 'manageExceptions' : 'manageFindings';
    }

    protected function rules(Request $request): array
    {
        $maximum = 'max:'.BulkAssignFindingsRequest::MAX_SELECTED_ITEMS;

        return [
            'exception_group_ids' => ['required_without:finding_fingerprints', 'prohibits:finding_fingerprints', 'array', 'min:1', $maximum],
            'exception_group_ids.*' => ['integer', 'distinct'],
            'finding_fingerprints' => ['required_without:exception_group_ids', 'array', 'min:1', $maximum],
            'finding_fingerprints.*' => ['string', 'distinct'],
            'assignee' => ['required', 'string', 'max:255'],
        ];
    }

    protected function write(Project $project, Request $request): array
    {
        $isException = $request->filled('exception_group_ids');
        $keys = $isException ? array_map(intval(...), (array) $request['exception_group_ids']) : array_map(strval(...), (array) $request['finding_fingerprints']);
        $items = $isException
            ? ExceptionGroup::query()->where('project_id', $project->id)->whereIn('id', $keys)->get()
            : Finding::query()->where('project_id', $project->id)->whereIn('fingerprint', $keys)->get();

        if ($items->count() !== count($keys)) {
            return ['error' => 'Some items are not in this project. Use the ids from ListExceptionGroups or the fingerprints from SearchFindings.'];
        }

        $assigneeName = mb_strtolower(trim((string) $request['assignee']));
        $assignee = match ($assigneeName) {
            'nobody' => null,
            'me' => $this->user,
            default => User::query()->where('email', $assigneeName)->first(),
        };

        if ($assigneeName !== 'nobody' && ($assignee === null || ! app(ProjectAssigneeService::class)->canBeAssigned($assignee, $project))) {
            return ['error' => 'No active user with this email address can see the project.'];
        }

        app(BulkAssignProjectItemsAction::class)->execute($items, $this->user, $assignee);

        return ['project' => $project->slug, 'type' => $isException ? 'exception_groups' : 'findings', 'count' => $items->count(), 'isAssigned' => $assignee !== null];
    }
}

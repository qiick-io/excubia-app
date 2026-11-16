<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Actions\BulkUpdateExceptionStatusAction;
use App\Enums\ExceptionStatusEnum;
use App\Http\Requests\BulkUpdateExceptionStatusRequest;
use App\Models\ExceptionGroup;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Resolves, ignores or reopens exception groups of one project (#320), with the bulk action of the
 * Exceptions tab (#276): `can-manage-exceptions`, at most 200 groups, every one in the project.
 */
class UpdateExceptionStatusTool extends AssistantWriteTool
{
    public function description(): Stringable|string
    {
        return 'Resolve, ignore or reopen exception groups of one project, by their ids from ListExceptionGroups (at most '.BulkUpdateExceptionStatusRequest::MAX_SELECTED_ITEMS.'). A resolved group reopens when it occurs again. Needs the user\'s approval.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('Project slug, from ListProjects.')->required(),
            'ids' => $schema->array()->items($schema->integer())->description('Exception group ids, from ListExceptionGroups.')->required(),
            'status' => $schema->string()->enum(array_column(ExceptionStatusEnum::cases(), 'value'))->description('The new status.')->required(),
        ];
    }

    protected function ability(Request $request): string
    {
        return 'manageExceptions';
    }

    protected function rules(Request $request): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:'.BulkUpdateExceptionStatusRequest::MAX_SELECTED_ITEMS],
            'ids.*' => ['integer', 'distinct'],
            'status' => ['required', Rule::enum(ExceptionStatusEnum::class)],
        ];
    }

    protected function write(Project $project, Request $request): array
    {
        $ids = array_map(intval(...), (array) $request['ids']);
        $groups = ExceptionGroup::query()->where('project_id', $project->id)->whereIn('id', $ids)->get();

        if ($groups->count() !== count($ids)) {
            return ['error' => 'Some exception groups are not in this project. Call ListExceptionGroups for the ids.'];
        }

        $status = ExceptionStatusEnum::from((string) $request['status']);
        $changed = app(BulkUpdateExceptionStatusAction::class)->execute($groups, $status, $this->user);

        return ['project' => $project->slug, 'status' => $status->value, 'selected' => count($ids), 'changed' => $changed];
    }
}

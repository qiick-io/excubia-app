<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Enums\PermissionEnum;
use App\Models\ExceptionGroup;
use App\Models\Project;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Validator;

/**
 * The selection of a bulk action on the Exceptions tab (#276): up to 200 exception group ids
 * of the project in the route. Handling them takes the same right as handling one
 * (`can-manage-exceptions` on a project the user may see), and an id of another project fails
 * the whole request rather than being skipped.
 */
trait TargetsProjectExceptionGroupsTrait
{
    public const int MAX_SELECTED_GROUPS = 200;

    public function authorize(): bool
    {
        $project = $this->route('project');
        $user = $this->user();

        return $user !== null
            && $project instanceof Project
            && $user->can('view', $project)
            && $user->can(PermissionEnum::CanManageExceptions->value);
    }

    /**
     * @return array<string, list<string>>
     */
    protected function selectionRules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_SELECTED_GROUPS],
            'ids.*' => ['integer', 'distinct'],
        ];
    }

    protected function validateSelectionBelongsToProject(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        if ($this->exceptionGroups()->count() !== count($this->selectedIds())) {
            $validator->errors()->add('ids', __('Some selected exceptions are not in this project.'));
        }
    }

    /**
     * @return Collection<int, ExceptionGroup>
     */
    public function exceptionGroups(): Collection
    {
        $project = $this->route('project');

        return ExceptionGroup::query()
            ->where('project_id', $project instanceof Project ? $project->id : 0)
            ->whereIn('id', $this->selectedIds())
            ->get();
    }

    /**
     * @return list<int>
     */
    private function selectedIds(): array
    {
        return array_values(array_map(intval(...), (array) $this->input('ids', [])));
    }
}

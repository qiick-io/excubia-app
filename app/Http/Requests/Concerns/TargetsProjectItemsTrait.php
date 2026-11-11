<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Models\Project;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Validator;

/**
 * The selection of a bulk action on an inbox list of a project (#276): up to 200 ids of the
 * request's item model in the project of the route. Handling them takes the same right as
 * handling one (the list's permission on a project the user may see), and an id of another
 * project fails the whole request rather than being skipped.
 *
 * The using request names the model and the permission.
 *
 * @template TItem of Model
 */
trait TargetsProjectItemsTrait
{
    public const int MAX_SELECTED_ITEMS = 200;

    /**
     * @return class-string<TItem>
     */
    abstract protected function itemModel(): string;

    abstract protected function itemPermission(): string;

    public function authorize(): bool
    {
        $project = $this->route('project');
        $user = $this->user();

        return $user !== null
            && $project instanceof Project
            && $user->can('view', $project)
            && $user->can($this->itemPermission());
    }

    /**
     * @return array<string, list<string>>
     */
    protected function selectionRules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_SELECTED_ITEMS],
            'ids.*' => ['integer', 'distinct'],
        ];
    }

    protected function validateSelectionBelongsToProject(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        if ($this->selectedItems()->count() !== count($this->selectedIds())) {
            $validator->errors()->add('ids', __('Some selected items are not in this project.'));
        }
    }

    /**
     * @return Collection<int, TItem>
     */
    public function selectedItems(): Collection
    {
        $project = $this->route('project');
        $model = $this->itemModel();

        return $model::query()
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

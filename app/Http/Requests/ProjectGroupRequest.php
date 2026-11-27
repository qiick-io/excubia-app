<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Project;
use App\Models\ProjectGroup;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a project group with its projects, for both create and update (route `project_group`
 * on update). Only projects the actor can see may be added, so a group never reveals a project.
 */
class ProjectGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        $projectGroup = $this->route('project_group');

        return $projectGroup instanceof ProjectGroup
            ? (bool) $this->user()?->can('update', $projectGroup)
            : (bool) $this->user()?->can('create', ProjectGroup::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $projectGroup = $this->route('project_group');

        return [
            'name' => ['required', 'string', 'max:100', Rule::unique(ProjectGroup::class)->ignore($projectGroup instanceof ProjectGroup ? $projectGroup->id : null)],
            'description' => ['nullable', 'string', 'max:1000'],
            'projects' => ['array'],
            'projects.*' => ['integer', 'distinct', Rule::in(Project::query()->visibleTo($this->user())->pluck('id')->all())],
        ];
    }

    /**
     * @return list<int>
     */
    public function projectIds(): array
    {
        return array_values(array_map(intval(...), array_filter($this->array('projects'), is_numeric(...))));
    }
}

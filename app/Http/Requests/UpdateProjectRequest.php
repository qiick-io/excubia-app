<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ProjectVisibilityEnum;
use App\Http\Requests\Concerns\ValidatesProjectLogoTrait;
use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a project rename, enable / disable and visibility (route `project`). The system
 * project keeps its name; only admins may send `visibility`. A new logo replaces the current
 * one, `remove_logo` goes back to the initials avatar (#287).
 */
class UpdateProjectRequest extends FormRequest
{
    use ValidatesProjectLogoTrait;

    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project && (bool) $this->user()?->can('update', $project);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $project = $this->route('project');
        $isSystem = $project instanceof Project && $project->is_system;
        $canChangeVisibility = $project instanceof Project && (bool) $this->user()?->can('changeVisibility', $project);

        return [
            'name' => [Rule::requiredIf(! $isSystem), 'string', 'max:100', Rule::unique(Project::class)->ignore($project instanceof Project ? $project->id : null)],
            'is_enabled' => ['boolean'],
            'visibility' => $canChangeVisibility
                ? ['sometimes', Rule::enum(ProjectVisibilityEnum::class)]
                : ['prohibited'],
            'remove_logo' => ['boolean'],
            ...$this->projectLogoRules(),
        ];
    }
}

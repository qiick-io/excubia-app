<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a project rename or enable / disable (route `project`).
 */
class UpdateProjectRequest extends FormRequest
{
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

        return [
            'name' => ['required', 'string', 'max:100', Rule::unique(Project::class)->ignore($project instanceof Project ? $project->id : null)],
            'is_enabled' => ['boolean'],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\PermissionEnum;
use App\Models\Finding;
use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Dismisses a finding of a project, or removes its dismissal (#18). Needs `can-dismiss-findings`
 * and a project the user may see; the finding must belong to the project. A dismissal needs a
 * reason and may end on a day from today on (`until`, inclusive, as in excubia.json).
 */
class UpdateFindingDismissalRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');
        $finding = $this->route('finding');
        $user = $this->user();

        return $user !== null
            && $project instanceof Project
            && $finding instanceof Finding
            && $finding->project_id === $project->id
            && $user->can('view', $project)
            && $user->can(PermissionEnum::CanDismissFindings->value);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'is_dismissed' => ['required', 'boolean'],
            'reason' => ['required_if:is_dismissed,true,1', 'nullable', 'string', 'max:500'],
            'until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
        ];
    }
}

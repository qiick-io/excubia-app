<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ExceptionStatusEnum;
use App\Enums\PermissionEnum;
use App\Models\ExceptionGroup;
use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Resolves, ignores or reopens an exception group of a project (#26). Needs `can-manage-exceptions`
 * and a project the user may see; the group must belong to the project.
 */
class UpdateExceptionStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');
        $group = $this->route('exceptionGroup');

        $user = $this->user();

        return $user !== null
            && $project instanceof Project
            && $group instanceof ExceptionGroup
            && $group->project_id === $project->id
            && $user->can('view', $project)
            && $user->can(PermissionEnum::CanManageExceptions->value);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(ExceptionStatusEnum::class)],
        ];
    }
}

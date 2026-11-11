<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\PermissionEnum;
use App\Http\Requests\Concerns\TargetsProjectItemsTrait;
use App\Models\ExceptionGroup;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectAssigneeService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Assigns several exception groups of a project to one user, or unassigns them (#276), with the
 * rules of a single assignment (#254): the assignee must be active and able to see the project.
 */
class BulkAssignExceptionGroupsRequest extends FormRequest
{
    /** @use TargetsProjectItemsTrait<ExceptionGroup> */
    use TargetsProjectItemsTrait;

    protected function itemModel(): string
    {
        return ExceptionGroup::class;
    }

    protected function itemPermission(): string
    {
        return PermissionEnum::CanManageExceptions->value;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...$this->selectionRules(),
            'assigned_user_id' => ['present', 'nullable', 'integer', 'exists:users,id'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->validateSelectionBelongsToProject($validator),
            function (Validator $validator): void {
                $assignee = $this->assignee();
                $project = $this->route('project');

                if ($assignee !== null && $project instanceof Project && ! app(ProjectAssigneeService::class)->canBeAssigned($assignee, $project)) {
                    $validator->errors()->add('assigned_user_id', __('This user cannot see the project.'));
                }
            },
        ];
    }

    public function assignee(): ?User
    {
        $assigneeId = $this->input('assigned_user_id');

        return $assigneeId === null ? null : User::query()->find((int) $assigneeId);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\PermissionEnum;
use App\Models\ExceptionGroup;
use App\Models\Finding;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectAssigneeService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Assigns an exception group or a finding of a project, or unassigns it (`assigned_user_id`
 * null) (#254). Assigning is part of handling the item, so it needs the same permission as its
 * other actions (`can-manage-exceptions` for an exception group, `can-dismiss-findings` for a
 * finding) and a project the user may see. The assignee must be active and able to see the
 * project too (ProjectAssigneeService), or the assignment would leak it.
 */
class UpdateAssigneeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');
        $item = $this->item();
        $user = $this->user();

        if ($user === null || ! $project instanceof Project || $item === null || $item->project_id !== $project->id || ! $user->can('view', $project)) {
            return false;
        }

        return $user->can($item instanceof ExceptionGroup
            ? PermissionEnum::CanManageExceptions->value
            : PermissionEnum::CanDismissFindings->value);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'assigned_user_id' => ['present', 'nullable', 'integer', Rule::exists(User::class, 'id')],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $assignee = $this->assignee();
            $project = $this->route('project');

            if ($assignee !== null && $project instanceof Project && ! app(ProjectAssigneeService::class)->canBeAssigned($assignee, $project)) {
                $validator->errors()->add('assigned_user_id', __('This user cannot see the project.'));
            }
        }];
    }

    /**
     * The exception group or finding of the route.
     */
    public function item(): ExceptionGroup|Finding|null
    {
        $item = $this->route('exceptionGroup') ?? $this->route('finding');

        return $item instanceof ExceptionGroup || $item instanceof Finding ? $item : null;
    }

    public function assignee(): ?User
    {
        $assigneeId = $this->input('assigned_user_id');

        return is_numeric($assigneeId) ? User::query()->find((int) $assigneeId) : null;
    }
}

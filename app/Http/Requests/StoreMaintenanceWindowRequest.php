<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\MaintenanceWindow;
use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A one-off or weekly maintenance window of a project (#114). Needs the right to edit the
 * project. One-off: local start and end (`Y-m-d\TH:i`, end after start). Weekly: ISO weekdays,
 * a local start time and a duration up to a week.
 */
class StoreMaintenanceWindowRequest extends FormRequest
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
        $once = 'required_if:recurrence,'.MaintenanceWindow::ONCE;
        $weekly = 'required_if:recurrence,'.MaintenanceWindow::WEEKLY;

        return [
            'name' => ['required', 'string', 'max:100'],
            'recurrence' => ['required', Rule::in([MaintenanceWindow::ONCE, MaintenanceWindow::WEEKLY])],
            'timezone' => ['required', 'timezone:all'],
            'starts_at' => ['nullable', $once, 'date_format:Y-m-d\TH:i'],
            'ends_at' => ['nullable', $once, 'date_format:Y-m-d\TH:i', 'after:starts_at'],
            'weekdays' => ['nullable', $weekly, 'array', 'min:1'],
            'weekdays.*' => ['integer', 'between:1,7', 'distinct'],
            'start_time' => ['nullable', $weekly, 'date_format:H:i'],
            'duration_minutes' => ['nullable', $weekly, 'integer', 'min:1', 'max:10080'],
        ];
    }
}

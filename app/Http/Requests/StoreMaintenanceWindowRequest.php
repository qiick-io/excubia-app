<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\MaintenanceWindow;
use App\Models\Project;
use App\Rules\EndsInFutureRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A one-off or weekly maintenance window of a project (#114). Needs `can-manage-alerts` on a
 * project the user can see (#22). One-off: local start and end (`Y-m-d\TH:i`, end after start and in the future). Weekly: ISO weekdays,
 * a local start time and a duration up to a week.
 */
class StoreMaintenanceWindowRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project && (bool) $this->user()?->can('manageAlerts', $project);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return self::windowRules();
    }

    /**
     * The rules of a window, shared with the `mute-alert` AI tool (#320).
     *
     * @return array<string, list<mixed>>
     */
    public static function windowRules(): array
    {
        // Each recurrence validates only its own fields: the form always sends both sets, and an
        // empty `weekdays` of a one-off window must not fail `min:1` (#421).
        $once = 'exclude_unless:recurrence,'.MaintenanceWindow::ONCE;
        $weekly = 'exclude_unless:recurrence,'.MaintenanceWindow::WEEKLY;

        return [
            'name' => ['required', 'string', 'max:100'],
            'recurrence' => ['required', Rule::in([MaintenanceWindow::ONCE, MaintenanceWindow::WEEKLY])],
            'timezone' => ['required', 'timezone:all'],
            'starts_at' => [$once, 'required', 'date_format:Y-m-d\TH:i'],
            'ends_at' => [$once, 'required', 'date_format:Y-m-d\TH:i', 'after:starts_at', new EndsInFutureRule],
            'weekdays' => [$weekly, 'required', 'array', 'min:1'],
            'weekdays.*' => [$weekly, 'integer', 'between:1,7', 'distinct'],
            'start_time' => [$weekly, 'required', 'date_format:H:i'],
            'duration_minutes' => [$weekly, 'required', 'integer', 'min:1', 'max:10080'],
        ];
    }
}

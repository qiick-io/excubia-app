<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Actions\MuteAlertAction;
use App\Models\AlertLog;
use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mutes the rule or the issue of an alert of the project's history (#114). Needs the right to
 * edit the project; the alert must belong to it, and an issue mute needs an alert with a subject.
 */
class StoreAlertMuteRequest extends FormRequest
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
        /** @var Project $project */
        $project = $this->route('project');

        return [
            'alert_id' => ['required', 'integer', Rule::exists(AlertLog::class, 'id')->where('project_id', $project->id)],
            'scope' => ['required', Rule::in(['rule', 'issue'])],
            'duration' => ['required', Rule::in(array_keys(MuteAlertAction::DURATIONS))],
        ];
    }

    public function alert(): AlertLog
    {
        $alert = AlertLog::query()->findOrFail($this->integer('alert_id'));

        abort_if($this->input('scope') === 'issue' && $alert->subject === null, 422);

        return $alert;
    }
}

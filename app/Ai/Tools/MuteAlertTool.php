<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Actions\CreateMaintenanceWindowAction;
use App\Actions\MuteAlertAction;
use App\Enums\AlertEventEnum;
use App\Http\Requests\StoreMaintenanceWindowRequest;
use App\Models\AlertLog;
use App\Models\MaintenanceWindow;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Silences alerts of a project (#320) with the actions of its Alerts tab (#114), behind
 * `can-manage-alerts`:
 * - `target: rule` mutes one alert event of the project for 1 h, 24 h, 7 d or forever, as "Mute
 *   rule" on the latest alert of that event does (MuteAlertAction needs a sent alert);
 * - `target: maintenance_window` opens a one-off or weekly maintenance window, with the rules of
 *   the window form (StoreMaintenanceWindowRequest::windowRules()).
 * UnmuteAlertTool undoes both. The ids it needs are in the result.
 */
class MuteAlertTool extends AssistantWriteTool
{
    public function description(): Stringable|string
    {
        return 'Silence alerts of one project: mute one alert event (target "rule") for 1h, 24h, 7d or forever, or open a maintenance window (target "maintenance_window") during which no alert is sent: one-off with local starts_at and ends_at (YYYY-MM-DDTHH:MM), or weekly with ISO weekdays (1 Monday to 7 Sunday), a local start_time (HH:MM) and duration_minutes. Returns the id to undo it with UnmuteAlert. Needs the user\'s approval.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('Project slug, from ListProjects.')->required(),
            'target' => $schema->string()->enum(['rule', 'maintenance_window'])->description('Mute an alert event, or open a maintenance window.')->required(),
            'event' => $schema->string()->enum(AlertEventEnum::selectableValues())->description('For a rule: the alert event to mute.'),
            'duration' => $schema->string()->enum(array_keys(MuteAlertAction::DURATIONS))->description('For a rule: how long.'),
            'name' => $schema->string()->description('For a window: its name, such as "Deploy".'),
            'recurrence' => $schema->string()->enum([MaintenanceWindow::ONCE, MaintenanceWindow::WEEKLY])->description('For a window: once or weekly.'),
            'timezone' => $schema->string()->description('For a window: the timezone of its times, such as Europe/Rome.'),
            'starts_at' => $schema->string()->description('For a one-off window: local start, YYYY-MM-DDTHH:MM.'),
            'ends_at' => $schema->string()->description('For a one-off window: local end, YYYY-MM-DDTHH:MM.'),
            'weekdays' => $schema->array()->items($schema->integer())->description('For a weekly window: ISO weekdays, 1 Monday to 7 Sunday.'),
            'start_time' => $schema->string()->description('For a weekly window: local start time, HH:MM.'),
            'duration_minutes' => $schema->integer()->description('For a weekly window: length in minutes, at most a week.'),
        ];
    }

    protected function ability(Request $request): string
    {
        return 'manageAlerts';
    }

    protected function rules(Request $request): array
    {
        $rules = ['target' => ['required', Rule::in(['rule', 'maintenance_window'])]];

        return $request->string('target')->toString() === 'maintenance_window'
            ? [...$rules, ...StoreMaintenanceWindowRequest::windowRules()]
            : [
                ...$rules,
                'event' => ['required', Rule::in(AlertEventEnum::selectableValues())],
                'duration' => ['required', Rule::in(array_keys(MuteAlertAction::DURATIONS))],
            ];
    }

    protected function write(Project $project, Request $request): array
    {
        if ($request['target'] === 'maintenance_window') {
            /** @var array{name: string, recurrence: string, timezone: string, starts_at?: string|null, ends_at?: string|null, weekdays?: list<int>|null, start_time?: string|null, duration_minutes?: int|null} $values */
            $values = $request->all(array_keys(StoreMaintenanceWindowRequest::windowRules()));
            $window = app(CreateMaintenanceWindowAction::class)->execute($project, $values, $this->user);

            return ['project' => $project->slug, 'maintenanceWindow' => ['id' => $window->id, 'name' => $window->name, 'recurrence' => $window->recurrence]];
        }

        $alert = AlertLog::query()->where('project_id', $project->id)->where('event', (string) $request['event'])->latest('id')->first();

        if ($alert === null) {
            return ['error' => 'This project has sent no alert of this event yet, so there is nothing to mute.'];
        }

        $mute = app(MuteAlertAction::class)->execute($alert, false, (string) $request['duration'], $this->user);

        return ['project' => $project->slug, 'mute' => ['id' => $mute->id, 'event' => $mute->event, 'until' => $mute->until?->toIso8601String()]];
    }
}

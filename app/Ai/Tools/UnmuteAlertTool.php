<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Actions\DeleteMaintenanceWindowAction;
use App\Actions\UnmuteAlertAction;
use App\Enums\AlertEventEnum;
use App\Models\AlertMute;
use App\Models\MaintenanceWindow;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Undoes MuteAlertTool (#320) with the actions of the Alerts tab (#114), behind
 * `can-manage-alerts`: ends every mute of one alert event of the project (`target: rule`), or
 * closes a maintenance window by its id (`target: maintenance_window`).
 */
class UnmuteAlertTool extends AssistantWriteTool
{
    public function description(): Stringable|string
    {
        return 'Undo MuteAlert on one project: end every mute of an alert event (target "rule"), or close a maintenance window by its id (target "maintenance_window"). Needs the user\'s approval.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('Project slug, from ListProjects.')->required(),
            'target' => $schema->string()->enum(['rule', 'maintenance_window'])->description('Unmute an alert event, or close a maintenance window.')->required(),
            'event' => $schema->string()->enum(AlertEventEnum::selectableValues())->description('For a rule: the alert event to unmute.'),
            'maintenance_window_id' => $schema->integer()->description('For a window: its id, from MuteAlert.'),
        ];
    }

    protected function ability(Request $request): string
    {
        return 'manageAlerts';
    }

    protected function rules(Request $request): array
    {
        return [
            'target' => ['required', Rule::in(['rule', 'maintenance_window'])],
            'event' => ['required_if:target,rule', 'nullable', Rule::in(AlertEventEnum::selectableValues())],
            'maintenance_window_id' => ['required_if:target,maintenance_window', 'nullable', 'integer'],
        ];
    }

    protected function write(Project $project, Request $request): array
    {
        if ($request['target'] === 'maintenance_window') {
            $window = MaintenanceWindow::query()->where('project_id', $project->id)->find((int) $request['maintenance_window_id']);

            if ($window === null) {
                return ['error' => 'This project has no maintenance window with this id.'];
            }

            app(DeleteMaintenanceWindowAction::class)->execute($window);

            return ['project' => $project->slug, 'closedMaintenanceWindowId' => $window->id];
        }

        $mutes = AlertMute::query()->where('project_id', $project->id)->where('event', (string) $request['event'])->get();

        foreach ($mutes as $mute) {
            app(UnmuteAlertAction::class)->execute($mute);
        }

        return ['project' => $project->slug, 'event' => (string) $request['event'], 'endedMutes' => $mutes->count()];
    }
}

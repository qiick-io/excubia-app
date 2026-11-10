<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateMaintenanceWindowAction;
use App\Actions\DeleteMaintenanceWindowAction;
use App\Actions\MuteAlertAction;
use App\Actions\UnmuteAlertAction;
use App\Actions\UpdateAiBudgetAction;
use App\Http\Requests\StoreAlertMuteRequest;
use App\Http\Requests\StoreMaintenanceWindowRequest;
use App\Http\Requests\UpdateAiBudgetRequest;
use App\Models\AlertMute;
use App\Models\MaintenanceWindow;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Alert controls of the project's Alerts tab (#114): mute or unmute a rule or an issue, add or
 * remove a maintenance window; and the monthly AI budget of the AI tab (#129). Needs
 * `can-manage-alerts` and a project the user can see (#22).
 */
class ProjectAlertsController extends Controller
{
    public function storeMute(StoreAlertMuteRequest $request, Project $project, MuteAlertAction $mute, #[CurrentUser] User $user): RedirectResponse
    {
        $mute->execute($request->alert(), $request->input('scope') === 'issue', $request->string('duration')->toString(), $user);

        return $this->done(__('Alert muted.'));
    }

    public function destroyMute(Project $project, AlertMute $alertMute, UnmuteAlertAction $unmute): RedirectResponse
    {
        Gate::authorize('manageAlerts', $project);
        abort_unless($alertMute->project_id === $project->id, 404);

        $unmute->execute($alertMute);

        return $this->done(__('Alert unmuted.'));
    }

    public function storeWindow(StoreMaintenanceWindowRequest $request, Project $project, CreateMaintenanceWindowAction $create): RedirectResponse
    {
        /** @var array{name: string, recurrence: string, timezone: string, starts_at?: string|null, ends_at?: string|null, weekdays?: list<int>|null, start_time?: string|null, duration_minutes?: int|null} $values */
        $values = $request->validated();
        $create->execute($project, $values);

        return $this->done(__('Maintenance window added.'));
    }

    public function destroyWindow(Project $project, MaintenanceWindow $maintenanceWindow, DeleteMaintenanceWindowAction $delete): RedirectResponse
    {
        Gate::authorize('manageAlerts', $project);
        abort_unless($maintenanceWindow->project_id === $project->id, 404);

        $delete->execute($maintenanceWindow);

        return $this->done(__('Maintenance window removed.'));
    }

    public function updateAiBudget(UpdateAiBudgetRequest $request, Project $project, UpdateAiBudgetAction $update): RedirectResponse
    {
        $update->execute($project, $request->budget());

        return $this->done(__('AI budget saved.'));
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}

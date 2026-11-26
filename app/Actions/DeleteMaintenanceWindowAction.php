<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ActivityEventEnum;
use App\Models\MaintenanceWindow;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Removes a maintenance window of a project (#114).
 */
class DeleteMaintenanceWindowAction
{
    /**
     * Removes the window and audits it with the acting user (#431), together.
     */
    public function execute(MaintenanceWindow $window, User $actor): void
    {
        DB::transaction(function () use ($window, $actor): void {
            if (! $window->delete()) {
                return;
            }

            activity()
                ->performedOn($window->loadMissing('project')->project ?? $window)
                ->causedBy($actor)
                ->event(ActivityEventEnum::MaintenanceWindowDeleted->value)
                ->withProperties(['maintenance_window_id' => $window->id])
                ->log(ActivityEventEnum::MaintenanceWindowDeleted->value);
        });
    }
}

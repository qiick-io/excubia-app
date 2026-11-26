<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ActivityEventEnum;
use App\Models\MaintenanceWindow;

/**
 * Removes a maintenance window of a project (#114).
 */
class DeleteMaintenanceWindowAction
{
    public function execute(MaintenanceWindow $window): void
    {
        $window->delete();

        // Audited with the acting user (#431).
        activity()
            ->performedOn($window)
            ->event(ActivityEventEnum::MaintenanceWindowDeleted->value)
            ->withProperties(['project_id' => $window->project_id, 'name' => $window->name])
            ->log('Maintenance window removed');
    }
}

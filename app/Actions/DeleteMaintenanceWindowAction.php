<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\MaintenanceWindow;

/**
 * Removes a maintenance window of a project (#114).
 */
class DeleteMaintenanceWindowAction
{
    public function execute(MaintenanceWindow $window): void
    {
        $window->delete();
    }
}

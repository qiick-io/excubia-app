<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Report history and stale projects
    |--------------------------------------------------------------------------
    |
    | Defaults for the "Checks" application settings. A value saved in the
    | settings page wins over these.
    |
    */

    'reports_kept_per_project' => (int) env('EXCUBIA_REPORTS_KEPT_PER_PROJECT', 30),

    'stale_after_hours' => (int) env('EXCUBIA_STALE_AFTER_HOURS', 36),

];

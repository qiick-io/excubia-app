<?php

declare(strict_types=1);

use App\Models\Activity;

/**
 * Excubia's overrides of spatie/laravel-activitylog, on top of qiick/laravel-audit, which owns the
 * `activity_log` table, the logging action and the retention purge (`audit:purge`). Keys left out
 * keep their defaults; in production the activity log stays on and unbuffered, or boot fails.
 */
return [

    // Excubia's subclass of the package model (qiick/laravel-audit requires one or the other).
    'activity_model' => Activity::class,

    // qiick/laravel-audit refuses to boot in production with logging off or buffered.
    'enabled' => env('ACTIVITYLOG_ENABLED', true),

    'buffer' => [
        'enabled' => env('ACTIVITYLOG_BUFFER_ENABLED', false),
    ],

];

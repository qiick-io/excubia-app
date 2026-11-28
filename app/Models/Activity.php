<?php

declare(strict_types=1);

namespace App\Models;

use Qiick\Audit\Models\Activity as AuditActivity;

/**
 * Excubia's activity row: qiick/laravel-audit's model (`public_id`, string morph ids, the
 * organisation perimeter, write-once rows, attribution and erasure), kept under Excubia's name so
 * its policy (ActivityPolicy) and the activity log page keep pointing at one class.
 */
class Activity extends AuditActivity {}

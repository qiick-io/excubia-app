<?php

declare(strict_types=1);

use App\Enums\PermissionEnum;
use Qiick\Access\Testing\DirectPermissionCheckScanner;

/**
 * qiick/laravel-access AC9: permissions are checked in Policies (and the Inertia share); every
 * other direct resolver call is listed with its reason in qiick-standards.json access.direct_checks.
 */
it('checks permissions only in policies or at a listed direct site', function () {
    $basePath = dirname(__DIR__, 2);
    $violations = (new DirectPermissionCheckScanner(PermissionEnum::class))
        ->scan($basePath, ['app', 'routes'], $basePath.'/qiick-standards.json');

    expect($violations)->toBe([]);
});

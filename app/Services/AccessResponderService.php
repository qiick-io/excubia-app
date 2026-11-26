<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RoleEnum;
use Illuminate\Http\Request;
use Qiick\Access\Contracts\AuthorizationResponder;
use Qiick\Access\Contracts\RoleCatalog;
use Symfony\Component\HttpFoundation\Response;

/**
 * Excubia's answers to qiick/laravel-access: the name of the super-admin role, and the refusal
 * of its `permission:` route middleware (Laravel's own 403, as every other refusal here).
 */
class AccessResponderService implements AuthorizationResponder, RoleCatalog
{
    public function superAdminRoleName(): string
    {
        return RoleEnum::SuperAdmin->value;
    }

    public function forbidden(Request $request, string $permission): Response
    {
        abort(403);
    }
}

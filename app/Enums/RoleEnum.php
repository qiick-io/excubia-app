<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Built-in roles. The names match every qiick app so that Signaculum SSO can map them later.
 */
enum RoleEnum: string
{
    case SuperAdmin = 'super-admin';
    case Admin = 'admin';
    case Reader = 'reader';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Default permissions of the role. Super-admin also passes every check through `Gate::before`.
     * Admin gets everything except the system-level ones (roles, settings, queue jobs).
     *
     * @return list<PermissionEnum>
     */
    public function defaultPermissions(): array
    {
        return match ($this) {
            self::SuperAdmin => PermissionEnum::cases(),
            self::Admin => array_values(array_filter(
                PermissionEnum::cases(),
                fn (PermissionEnum $permission): bool => ! in_array($permission, [PermissionEnum::RolesManage, PermissionEnum::SettingsManage, PermissionEnum::JobsManage], true),
            )),
            self::Reader => [PermissionEnum::ProjectsView],
        };
    }
}

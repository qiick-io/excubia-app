<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Activity-log event names offered by the activity log filter: Spatie's model events plus the
 * manual events written for auth, settings and role, member and permission syncs.
 */
enum ActivityEventEnum: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
    case Login = 'login';
    case Logout = 'logout';
    case Failed = 'failed';
    case SettingsUpdated = 'settings_updated';
    case PermissionsSynced = 'permissions_synced';
    case RolesSynced = 'roles_synced';
    case MembersSynced = 'members_synced';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

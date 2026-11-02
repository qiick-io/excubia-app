<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Excubia's permissions (`resource.action`), seeded by PermissionSeeder and shared with Inertia.
 */
enum PermissionEnum: string
{
    case ProjectsView = 'projects.view';
    case ProjectsManage = 'projects.manage';
    case ChecksRun = 'checks.run';
    case UsersManage = 'users.manage';
    case RolesManage = 'roles.manage';
    case GroupsManage = 'groups.manage';
    case SettingsManage = 'settings.manage';
    case ActivityView = 'activity.view';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

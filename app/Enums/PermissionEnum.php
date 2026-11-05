<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Excubia's permissions, seeded by PermissionSeeder and shared with Inertia. Names follow
 * qiick-io/externa-core: `can-{show|create|edit|delete}-{resource}`, plus `can-{verb}-{thing}`
 * for single actions.
 */
enum PermissionEnum: string
{
    // Projects
    case CanShowProjects = 'can-show-projects';
    case CanCreateProjects = 'can-create-projects';
    case CanEditProjects = 'can-edit-projects';
    case CanDeleteProjects = 'can-delete-projects';

    // Project data
    case CanRunChecks = 'can-run-checks';
    case CanManageExceptions = 'can-manage-exceptions';

    // Users
    case CanShowUsers = 'can-show-users';
    case CanCreateUsers = 'can-create-users';
    case CanEditUsers = 'can-edit-users';
    case CanDeleteUsers = 'can-delete-users';

    // Groups
    case CanShowGroups = 'can-show-groups';
    case CanCreateGroups = 'can-create-groups';
    case CanEditGroups = 'can-edit-groups';
    case CanDeleteGroups = 'can-delete-groups';

    // Roles
    case CanShowRoles = 'can-show-roles';
    case CanCreateRoles = 'can-create-roles';
    case CanEditRoles = 'can-edit-roles';
    case CanDeleteRoles = 'can-delete-roles';

    // Permissions
    case CanShowPermissions = 'can-show-permissions';
    case CanEditPermissions = 'can-edit-permissions';

    // Activity log
    case CanShowActivityLogs = 'can-show-activity-logs';

    // Queue jobs
    case CanShowJobs = 'can-show-jobs';
    case CanManageJobs = 'can-manage-jobs';

    // AI
    case CanUseAi = 'can-use-ai';

    // Settings
    case CanManageProjectSettings = 'can-manage-project-settings';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * The resource a permission belongs to, for the role form sections
     * (`can-create-users` → `users`, `can-manage-project-settings` → `project-settings`).
     */
    public function resource(): string
    {
        return (string) preg_replace('/^can-[a-z]+-/', '', $this->value);
    }
}

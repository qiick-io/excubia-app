<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Activity-log event names offered by the activity log filter: Spatie's model events plus the
 * manual events written for auth, settings, role, member and permission syncs, push tokens and
 * personal API keys.
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
    case TokenRotated = 'token_rotated';
    case TokenRevoked = 'token_revoked';
    case ApiKeyRotated = 'rotated';
    case ApiKeyRevoked = 'revoked';
    case ApiKeyFirstUsed = 'api_key_first_used';
    // One entry per completed user erasure (#381): the case id and the counts, no personal data.
    case ErasureCompleted = 'erasure_completed';
    case WebhookSecretCreated = 'webhook_secret_created';
    case WebhookSecretRotated = 'webhook_secret_rotated';
    // Writes that silence alerts (#431), filterable in the activity log.
    case AlertMuted = 'alert_muted';
    case AlertUnmuted = 'alert_unmuted';
    case MaintenanceWindowCreated = 'maintenance_window_created';
    case MaintenanceWindowDeleted = 'maintenance_window_deleted';
    case FindingDismissed = 'finding_dismissed';
    case FindingRestored = 'finding_restored';
    case StatusChanged = 'status_changed';
    case Assigned = 'assigned';
    case Unassigned = 'unassigned';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

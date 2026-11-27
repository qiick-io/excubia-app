<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\User;
use App\Services\EffectivePermissionResolverService;

/**
 * What a personal API key may be used for (#315, #351): `<resource>:(read|write)` only.
 * `mcp:read` reaches the MCP server and its read tools; `mcp:write` adds the write tools (#320)
 * and implies `mcp:read`. Backing values are stored in `api_keys.scopes`: never change one
 * without a data migration.
 *
 * A key never holds a scope its creator lacks, and at use time its effective access is the
 * intersection of the stored scopes with the owner's current permissions (fleet rule).
 */
enum ApiKeyScopeEnum: string
{
    case McpRead = 'mcp:read';
    case McpWrite = 'mcp:write';

    /** Fleet vocabulary: a resource noun and read or write, nothing else. `D` refuses a trailing newline. */
    public const PATTERN = '/^[a-z][a-z0-9-]*:(read|write)$/D';

    /**
     * Permissions a user must hold to issue or keep using a key with this scope. `write` needs
     * the same ceiling as `read` today: both mcp scopes are the AI surface (`can-use-ai`).
     *
     * @return list<PermissionEnum>
     */
    public function requiredCreatorPermissions(): array
    {
        return match ($this) {
            self::McpRead, self::McpWrite => [PermissionEnum::CanUseAi],
        };
    }

    /**
     * Whether every permission this scope needs is within the user's owner ceiling (assigned, or
     * any permission for a super-admin): the same ceiling the key meets on every request, so
     * issuance, rotation and use agree.
     */
    public function isHeldBy(User $user): bool
    {
        $permissionResolver = app(EffectivePermissionResolverService::class);

        foreach ($this->requiredCreatorPermissions() as $requiredPermission) {
            if (! $permissionResolver->isWithinOwnerCeiling($user, $requiredPermission->value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Scopes the creator may put on a new key: only those whose permissions they hold now.
     *
     * @return list<self>
     */
    public static function scopesCreatorMayGrant(User $creator): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $scope): bool => $scope->isHeldBy($creator),
        ));
    }

    /**
     * One scope per resource, the highest held: write drops a sibling read (`write` implies `read`).
     *
     * @param  list<self>  $scopes
     * @return list<self>
     */
    public static function normalize(array $scopes): array
    {
        $highestByResource = [];

        foreach ($scopes as $scope) {
            [$resource, $action] = explode(':', $scope->value, 2);

            if ($action === 'write' || ! isset($highestByResource[$resource])) {
                $highestByResource[$resource] = $scope;
            }
        }

        return array_values($highestByResource);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

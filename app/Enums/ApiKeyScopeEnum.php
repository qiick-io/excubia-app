<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\User;

/**
 * What a personal API key may be used for (#315, #351): `<resource>:(read|write)` only.
 * `mcp:read` reaches the MCP server and its read tools; `mcp:write` adds the write tools (#320)
 * and implies `mcp:read`. Backing values are stored in `api_keys.scopes`: never change one
 * without a data migration.
 */
enum ApiKeyScopeEnum: string
{
    case McpRead = 'mcp:read';
    case McpWrite = 'mcp:write';

    /** Fleet vocabulary: a resource noun and read or write, nothing else. `D` refuses a trailing newline. */
    public const PATTERN = '/^[a-z][a-z0-9-]*:(read|write)$/D';

    /**
     * Scopes the creator may put on a new key. Today every enum case: mcp scopes are not Spatie
     * permissions, and every signed-in verified user may reach MCP. When scopes start mapping to
     * permissions, narrow the return here — one seam instead of a rule re-derived per request.
     *
     * @return list<self>
     */
    public static function scopesCreatorMayGrant(User $creator): array
    {
        unset($creator);

        return self::cases();
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

<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a personal API key may be used for (#315), as externa-core's scopes. Only the read-only
 * MCP server exists today; `mcp:write` and REST scopes are added here when those surfaces exist.
 * Backing values are stored in `api_keys.scopes`: never change one without a data migration.
 */
enum ApiKeyScopeEnum: string
{
    case McpRead = 'mcp:read';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

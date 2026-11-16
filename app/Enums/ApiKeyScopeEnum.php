<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a personal API key may be used for (#315), as externa-core's scopes: `mcp:read` reaches the
 * MCP server and its read tools, `mcp:write` adds the write tools (#320). REST scopes are added here
 * when that surface exists. Backing values are stored in `api_keys.scopes`: never change one
 * without a data migration.
 */
enum ApiKeyScopeEnum: string
{
    case McpRead = 'mcp:read';
    case McpWrite = 'mcp:write';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

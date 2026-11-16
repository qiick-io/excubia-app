<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ApiKeyScopeEnum;
use App\Models\ApiKey;

/**
 * Whether the write tools may run (#320), as externa-core's `AiToolWriteGateService` under the
 * qiick rule "AI writes are on by default" (app-ui `docs/ai-backend.md`):
 * - `EXCUBIA_AI_WRITE_ENABLED` (default on) is the switch for every surface;
 * - over MCP, `EXCUBIA_MCP_WRITE_ENABLED` (default on) and the key's `mcp:write` scope must
 *   allow it too, since creating a key with `mcp:write` is the user's consent and the MCP
 *   client asks before each call;
 * - Settings → AI → Tools turns single tools off (SettingsRepositoryService::aiEnabledTools()).
 *
 * The acting user's permissions are checked by each tool, exactly as in the UI.
 */
class AiToolWriteGateService
{
    public function isWriteEnabled(): bool
    {
        return (bool) config('excubia.ai_write_enabled');
    }

    /**
     * Whether a request made with this API key may see and call the write tools.
     */
    public function isMcpWriteAllowed(?ApiKey $apiKey): bool
    {
        return $this->isWriteEnabled()
            && (bool) config('excubia.mcp_write_enabled')
            && $apiKey?->hasScope(ApiKeyScopeEnum::McpWrite) === true;
    }
}

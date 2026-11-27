<?php

declare(strict_types=1);

/**
 * Excubia's MCP server (#234), streamable HTTP at `POST /mcp`, as externa-core's.
 *
 * Off by default (`EXCUBIA_MCP_ENABLED`). A client sends a personal API key with the `mcp:read`
 * scope as `Authorization: Bearer <key>`; the key acts as its owner. The write tools (#320) need
 * the `mcp:write` scope too (AssistantMcpToolAdapter::shouldRegister()). Outside the `web` group:
 * no session and no CSRF, so a signed-in browser cannot drive the tools. The API key
 * middleware limits failed attempts per IP and requests per key.
 */

use App\Enums\ApiKeyScopeEnum;
use App\Http\Middleware\AuthenticateApiKeyMiddleware;
use App\Http\Middleware\EnsureMcpEnabledMiddleware;
use App\Mcp\Servers\ExcubiaServer;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Middleware\AddWwwAuthenticateHeader;

Mcp::web('/mcp', ExcubiaServer::class)
    // qiick/laravel-api-keys answers 401s with the RFC 6750 challenge (no error code when no
    // credentials were sent); Laravel MCP's own header would overwrite it with invalid_token.
    ->withoutMiddleware(AddWwwAuthenticateHeader::class)
    ->middleware([
        EnsureMcpEnabledMiddleware::class,
        AuthenticateApiKeyMiddleware::class.':'.ApiKeyScopeEnum::McpRead->value,
    ])
    ->name('mcp');

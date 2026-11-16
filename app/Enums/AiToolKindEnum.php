<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What an assistant or MCP tool does to data (#320), as externa-core's `AiToolKindEnum` and the
 * qiick AI rule (app-ui `docs/ai-backend.md`). A `write` tool changes data in a way that can be
 * undone (a status, an assignment, a dismissal, a mute); it runs only while the write switch is on,
 * after the user's approval in the assistant and with an `mcp:write` key over MCP. Excubia has no
 * destructive or security tools (deleting projects, tokens, users, roles, settings), so the
 * `destructive` kind of the rule is not needed here.
 */
enum AiToolKindEnum: string
{
    case Read = 'read';
    case Write = 'write';
}

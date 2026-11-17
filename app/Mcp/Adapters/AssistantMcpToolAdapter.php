<?php

declare(strict_types=1);

namespace App\Mcp\Adapters;

use App\Ai\Agents\ExcubiaAssistantAgent;
use App\Ai\Tools\AssistantTool;
use App\Enums\AiToolKindEnum;
use App\Models\ApiKey;
use App\Models\User;
use App\Services\AiToolWriteGateService;
use App\Services\SettingsRepositoryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request as AssistantToolRequest;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * One Assistant tool served over MCP (#234), as externa-core's `RegistryMcpToolAdapter` bridges its
 * AI tool registry: the name, description and input schema come from the Assistant tool, and a call
 * runs that same tool as the user the API key belongs to. So the Assistant and MCP share one
 * implementation, the same permission checks (`can-show-projects`, `Project::visibleTo()`, the
 * ProjectPolicy abilities of the write tools) and the same field allowlists.
 *
 * Only the tools enabled in Settings → AI are listed. A write tool (#320) is listed and callable
 * only for a key with the `mcp:write` scope while `EXCUBIA_AI_WRITE_ENABLED` and
 * `EXCUBIA_MCP_WRITE_ENABLED` are on (AiToolWriteGateService); creating such a key is the user's
 * consent and the client asks before each call, so there is no in-app approval. The annotations
 * follow the tool's kind. The tool writes every call to the `ai` activity log with `surface: mcp`.
 */
final class AssistantMcpToolAdapter extends Tool
{
    /**
     * @param  string  $toolName  the key in ExcubiaAssistantAgent::TOOLS, also the MCP tool name
     * @param  class-string<AssistantTool>  $toolClass
     */
    public function __construct(private readonly string $toolName, private readonly string $toolClass) {}

    /**
     * Every Assistant tool, wrapped.
     *
     * @return list<self>
     */
    public static function all(): array
    {
        return array_map(
            fn (string $toolName, string $toolClass): self => new self($toolName, $toolClass),
            array_keys(ExcubiaAssistantAgent::TOOLS),
            array_values(ExcubiaAssistantAgent::TOOLS),
        );
    }

    public function name(): string
    {
        return $this->toolName;
    }

    /**
     * The tool's own title ("Get Latest Report"): the default title comes from the class name,
     * which would give every wrapped tool the same "Assistant Mcp Tool Adapter" in clients.
     */
    public function title(): string
    {
        return Str::headline($this->toolName);
    }

    public function description(): string
    {
        return (string) $this->assistantTool(new User)->description();
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return $this->assistantTool(new User)->schema($schema);
    }

    /**
     * The MCP hints of the tool's kind, as externa-core's adapter sets them: a read tool is
     * read-only and idempotent; a write tool changes data but deletes nothing.
     *
     * @return array<string, bool>
     */
    public function annotations(): array
    {
        return $this->toolClass::kind() === AiToolKindEnum::Read
            ? ['readOnlyHint' => true, 'idempotentHint' => true]
            : ['readOnlyHint' => false, 'destructiveHint' => false];
    }

    public function shouldRegister(SettingsRepositoryService $settings, AiToolWriteGateService $writeGate, HttpRequest $httpRequest): bool
    {
        if (! in_array($this->toolName, $settings->aiEnabledTools(), true)) {
            return false;
        }

        $apiKey = $httpRequest->attributes->get(ApiKey::class);

        return $this->toolClass::kind() === AiToolKindEnum::Read
            || $writeGate->isMcpWriteAllowed($apiKey instanceof ApiKey ? $apiKey : null);
    }

    public function handle(Request $request): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return Response::error('Not authenticated.');
        }

        $httpRequest = request();
        $apiKey = $httpRequest->attributes->get(ApiKey::class);
        $apiKeyId = $apiKey instanceof ApiKey ? $apiKey->id : null;

        $result = (string) $this->assistantTool($user, $apiKeyId)->handle(new AssistantToolRequest($request->all()));

        return array_key_exists('error', (array) json_decode($result, true)) ? Response::error($result) : Response::text($result);
    }

    private function assistantTool(User $user, ?int $apiKeyId = null): AssistantTool
    {
        return new ($this->toolClass)($user, 'mcp', $apiKeyId);
    }
}

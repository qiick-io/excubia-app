<?php

declare(strict_types=1);

namespace App\Mcp\Adapters;

use App\Ai\Agents\ExcubiaAssistantAgent;
use App\Ai\Tools\AssistantTool;
use App\Models\User;
use App\Services\SettingsRepositoryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request as AssistantToolRequest;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * One read-only Assistant tool served over MCP (#234), as externa-core's
 * `RegistryMcpToolAdapter` bridges its AI tool registry: the name, description and input
 * schema come from the Assistant tool, and a call runs that same tool as the user the API key
 * belongs to. So the Assistant and MCP share one implementation, the same permission check
 * (`can-show-projects` and `Project::visibleTo()`) and the same field allowlists.
 *
 * Only the tools enabled in Settings → AI are listed. Every call is written to the `ai`
 * activity log (tool, arguments, outcome), never the result.
 */
#[IsReadOnly]
#[IsIdempotent]
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

    public function shouldRegister(SettingsRepositoryService $settings): bool
    {
        return in_array($this->toolName, $settings->aiEnabledTools(), true);
    }

    public function handle(Request $request): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return Response::error('Not authenticated.');
        }

        $result = (string) $this->assistantTool($user)->handle(new AssistantToolRequest($request->all()));
        $isError = array_key_exists('error', (array) json_decode($result, true));

        activity()
            ->useLog('ai')
            ->causedBy($user)
            ->event('ai_tool')
            ->withProperties(['surface' => 'mcp', 'tool' => $this->toolName, 'arguments' => $request->all(), 'status' => $isError ? 'error' : 'ok'])
            ->log("MCP tool {$this->toolName}");

        return $isError ? Response::error($result) : Response::text($result);
    }

    private function assistantTool(User $user): AssistantTool
    {
        return new ($this->toolClass)($user);
    }
}

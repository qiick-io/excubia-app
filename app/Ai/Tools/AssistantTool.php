<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Ai\Agents\ExcubiaAssistantAgent;
use App\Enums\AiToolKindEnum;
use App\Models\Project;
use App\Models\User;
use App\Services\AssistantQueryService;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Base of the assistant tools, served in the Assistant and over MCP (#17, #234): checks
 * `can-show-projects` for the user who asked, returns the tool's data as JSON, and writes every
 * call to the `ai` activity log (surface, tool, arguments, outcome; never the result). A missing
 * permission or project comes back as an `error` the model can explain to the user.
 *
 * Read tools implement read(); write tools (#320) extend AssistantWriteTool.
 */
abstract class AssistantTool implements Tool
{
    /**
     * @param  'assistant'|'mcp'  $surface  where the call comes from, for the activity log
     * @param  string|null  $apiKeyId  public UUID of the issuing key when the surface is MCP (#351 attribution)
     */
    public function __construct(
        protected User $user,
        protected string $surface = 'assistant',
        protected ?string $apiKeyId = null,
    ) {}

    /**
     * What the tool does to data: read tools never change anything.
     */
    public static function kind(): AiToolKindEnum
    {
        return AiToolKindEnum::Read;
    }

    /**
     * The tool's key in ExcubiaAssistantAgent::TOOLS, also its MCP name and its name in the settings.
     */
    public static function toolName(): string
    {
        return (string) array_search(static::class, ExcubiaAssistantAgent::TOOLS, true);
    }

    public function handle(Request $request): Stringable|string
    {
        $result = $this->run($request);
        $isError = array_key_exists('error', $result);

        $properties = [
            'surface' => $this->surface,
            'tool' => static::toolName(),
            'kind' => static::kind()->value,
            'arguments' => $request->all(),
            'status' => $isError ? 'error' : 'ok',
        ];

        if ($this->apiKeyId !== null) {
            $properties['api_key_id'] = $this->apiKeyId;
        }

        activity()
            ->useLog('ai')
            ->causedBy($this->user)
            ->event('ai_tool')
            ->withProperties($properties)
            ->log(($this->surface === 'mcp' ? 'MCP' : 'Assistant').' tool '.static::toolName());

        return (string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /**
     * @return array<mixed> the tool's data, or `['error' => …]`
     */
    protected function run(Request $request): array
    {
        if (! $this->user->can('viewAny', Project::class)) {
            return ['error' => 'The user lacks the can-show-projects permission.'];
        }

        return $this->read(app(AssistantQueryService::class)->forUser($this->user), $request)
            ?? ['error' => 'No project or report matches these arguments. Call ListProjects for the slugs.'];
    }

    /**
     * @return array<string, mixed>|list<array<string, mixed>>|null null when nothing matches
     */
    protected function read(AssistantQueryService $query, Request $request): ?array
    {
        return null;
    }
}

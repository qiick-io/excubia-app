<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Models\Project;
use App\Models\User;
use App\Services\AssistantQueryService;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Base of the read-only assistant tools: checks `projects.view` for the user who asked, then
 * returns the tool's data as JSON. A missing permission or project comes back as an `error`
 * the model can explain to the user.
 */
abstract class AssistantTool implements Tool
{
    public function __construct(protected User $user) {}

    public function handle(Request $request): Stringable|string
    {
        if (! $this->user->can('viewAny', Project::class)) {
            return $this->json(['error' => 'The user lacks the projects.view permission.']);
        }

        $data = $this->read(app(AssistantQueryService::class)->forUser($this->user), $request);

        return $this->json($data ?? ['error' => 'No project or report matches these arguments. Call ListProjects for the slugs.']);
    }

    /**
     * @return array<string, mixed>|list<array<string, mixed>>|null null when nothing matches
     */
    abstract protected function read(AssistantQueryService $query, Request $request): ?array;

    /**
     * @param  array<mixed>  $data
     */
    private function json(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}

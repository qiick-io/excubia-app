<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Enums\AiToolKindEnum;
use App\Models\Project;
use App\Services\AiToolWriteGateService;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Tools\Request;

/**
 * Base of the reversible write tools (#320). Each one calls the same Action as the UI, after the
 * same permission check (ProjectPolicy) on a project the user can see, so the AI never does more
 * than the person it acts for. Nothing destructive and nothing about security is a tool.
 *
 * - In the Assistant every call waits for the user's approval (laravel/ai `Approvable`); the
 *   agent drops the approval for tools the user approved "always" in the conversation.
 * - Over MCP the adapter lists these only for an `mcp:write` key; there is no in-app approval.
 * - `EXCUBIA_AI_WRITE_ENABLED=false` refuses every call here too, even one approved earlier.
 *
 * Arguments are validated here, at the trust boundary; a failed rule comes back as an `error`.
 */
abstract class AssistantWriteTool extends AssistantTool implements Approvable
{
    use InteractsWithApprovals;

    public static function kind(): AiToolKindEnum
    {
        return AiToolKindEnum::Write;
    }

    /**
     * Changes the data and says what changed, without user data (names, emails) or paths.
     *
     * @return array<string, mixed>
     */
    abstract protected function write(Project $project, Request $request): array;

    /**
     * The ProjectPolicy ability the user needs on the project, as the matching UI request checks.
     */
    abstract protected function ability(Request $request): string;

    /**
     * @return array<mixed>
     */
    protected function run(Request $request): array
    {
        if (! app(AiToolWriteGateService::class)->isWriteEnabled()) {
            return ['error' => 'AI write tools are turned off on this Excubia (EXCUBIA_AI_WRITE_ENABLED=false). The user can do this in the app.'];
        }

        try {
            $request->validate(['project' => ['required', 'string'], ...$this->rules($request)]);
        } catch (ValidationException $exception) {
            return ['error' => $exception->validator->errors()->first()];
        }

        $project = Project::query()->visibleTo($this->user)->where('slug', (string) $request['project'])->first();

        if ($project === null) {
            return ['error' => 'No project matches this slug. Call ListProjects for the slugs.'];
        }

        if (! $this->user->can($this->ability($request), $project)) {
            return ['error' => 'The user lacks the permission for this action on this project.'];
        }

        return $this->write($project, $request);
    }

    /**
     * Every call needs the user's approval in the Assistant, unless the agent marked the tool
     * approved for the conversation (withoutApproval()).
     */
    protected function needsApproval(Request $request): Approval|bool
    {
        return Approval::required(static::toolName().' changes data.');
    }
}

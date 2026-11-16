<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\Agents\ExcubiaAssistantAgent;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Enums\MessageStatus;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\Responses\AgentResponse;

/**
 * The approval of the Assistant's write calls (#320), with laravel/ai's own flow as externa-core's
 * chat uses it (`AiPendingApprovals`, `Decisions`): a turn that calls a write tool pauses, its
 * assistant message keeps the pending calls, and the user's decision resumes the turn, which
 * runs or skips the calls.
 *
 * "Always" (the qiick AI rule, app-ui `docs/ai-backend.md`) approves the calls and stops asking
 * for those tools in the same conversation. The kill switch and the permission checks still apply
 * to every call (AssistantWriteTool).
 */
class AssistantApprovalService
{
    private const ALWAYS_APPROVED_CACHE_PREFIX = 'assistant-always-approved:';

    /**
     * The calls the conversation's latest turn waits on, as externa-core's
     * `AiPendingApprovals::fromMessage()`, with the tool's TOOLS key as its name.
     *
     * @return list<array{id: string, tool: string, arguments: array<string, mixed>}>
     */
    public function pendingApprovals(string $conversationId): array
    {
        $message = ConversationMessage::query()
            ->where('conversation_id', $conversationId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        return $message === null ? [] : $this->fromMessage($message);
    }

    /**
     * @return list<array{id: string, tool: string, arguments: array<string, mixed>}>
     */
    public function fromMessage(ConversationMessage $message): array
    {
        if ($message->getAttribute('role') !== 'assistant' || $message->getAttribute('status') !== MessageStatus::Paused) {
            return [];
        }

        $pending = [];

        foreach ($message->tool_calls as $toolCall) {
            if (! is_array($toolCall) || ! PendingApproval::isPending($toolCall)) {
                continue;
            }

            $pending[] = [
                'id' => (string) ($toolCall['id'] ?? ''),
                'tool' => $this->toolName((string) ($toolCall['name'] ?? '')),
                'arguments' => is_array($toolCall['arguments'] ?? null) ? $toolCall['arguments'] : [],
            ];
        }

        return $pending;
    }

    /**
     * The TOOLS keys the user approved "always" in this conversation.
     *
     * ponytail: kept in the cache (database by default), not in a table; a cleared cache only
     * makes the Assistant ask again. A table would be the upgrade if this must survive a flush.
     *
     * @return list<string>
     */
    public function alwaysApprovedTools(string $conversationId): array
    {
        $names = Cache::get(self::ALWAYS_APPROVED_CACHE_PREFIX.$conversationId, []);

        return is_array($names) ? array_values(array_filter($names, is_string(...))) : [];
    }

    /**
     * Applies the user's decision to every call the turn waits on and resumes the turn: approved
     * calls run, rejected ones are skipped and the model is told. Logs one `ai_tool_approval`
     * activity per call.
     *
     * @return AgentResponse|null null when the conversation waits on no call
     */
    public function resolve(User $user, string $conversationId, bool $isApproved, bool $isAlways): ?AgentResponse
    {
        $pending = $this->pendingApprovals($conversationId);

        if ($pending === []) {
            return null;
        }

        if ($isApproved && $isAlways) {
            Cache::forever(self::ALWAYS_APPROVED_CACHE_PREFIX.$conversationId, array_values(array_unique([
                ...$this->alwaysApprovedTools($conversationId),
                ...array_column($pending, 'tool'),
            ])));
        }

        foreach ($pending as $call) {
            activity()
                ->useLog('ai')
                ->causedBy($user)
                ->event('ai_tool_approval')
                ->withProperties(['surface' => 'assistant', 'tool' => $call['tool'], 'arguments' => $call['arguments'], 'decision' => $isApproved ? 'approved' : 'rejected', 'isAlways' => $isApproved && $isAlways])
                ->log(($isApproved ? 'Approved' : 'Rejected').' assistant tool '.$call['tool']);
        }

        return (new ExcubiaAssistantAgent($user, $this->alwaysApprovedTools($conversationId)))
            ->continue($conversationId, as: $user)
            ->prompt(Decisions::from(array_fill_keys(array_column($pending, 'id'), $isApproved)));
    }

    /**
     * Drops the "always" approvals of deleted conversations.
     *
     * @param  list<string>  $conversationIds
     */
    public function forget(array $conversationIds): void
    {
        foreach ($conversationIds as $conversationId) {
            Cache::forget(self::ALWAYS_APPROVED_CACHE_PREFIX.$conversationId);
        }
    }

    /**
     * The TOOLS key of a laravel/ai tool name (the class basename, such as `MuteAlertTool`).
     */
    private function toolName(string $classBasename): string
    {
        foreach (ExcubiaAssistantAgent::TOOLS as $name => $class) {
            if (class_basename($class) === $classBasename) {
                return $name;
            }
        }

        return $classBasename;
    }
}

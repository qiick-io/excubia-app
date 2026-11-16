<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Carbon\CarbonInterface;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;

/**
 * Reads a user's assistant conversations, stored by `laravel/ai` in `agent_conversations` and
 * `agent_conversation_messages`. A user only ever sees their own.
 */
class AssistantConversationService
{
    public function __construct(private readonly AssistantApprovalService $approvals) {}

    /**
     * @return list<array{id: string, title: string, updated_at: string|null}>
     */
    public function conversationsFor(User $user): array
    {
        return array_values(Conversation::query()
            ->where('participant_type', Conversation::participantType($user))
            ->where('participant_id', Conversation::participantKey($user))
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get(['id', 'title', 'updated_at'])
            ->map(fn (Conversation $conversation): array => [
                'id' => (string) $conversation->getKey(),
                'title' => (string) $conversation->getAttribute('title'),
                'updated_at' => $this->iso($conversation->getAttribute('updated_at')),
            ])
            ->all());
    }

    public function belongsTo(string $conversationId, User $user): bool
    {
        return Conversation::query()
            ->whereKey($conversationId)
            ->where('participant_type', Conversation::participantType($user))
            ->where('participant_id', Conversation::participantKey($user))
            ->exists();
    }

    /**
     * The user and assistant messages of a conversation, oldest first. Tool calls stay on the
     * server, except the write calls the latest turn waits on (#320): the user approves or rejects
     * them under that message (`pending_approvals`).
     *
     * @return list<array{id: string, role: string, content: string, is_failed: bool, created_at: string|null, pending_approvals: list<array{id: string, tool: string, arguments: array<string, mixed>}>}>
     */
    public function messages(string $conversationId): array
    {
        $messages = ConversationMessage::query()
            ->where('conversation_id', $conversationId)
            ->whereIn('role', ['user', 'assistant'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
        $latest = $messages->last();

        return array_values($messages
            ->map(fn (ConversationMessage $message): array => [
                'id' => (string) $message->getKey(),
                'role' => (string) $message->getAttribute('role'),
                'content' => (string) $message->getAttribute('content'),
                'is_failed' => $message->getAttribute('status')?->value === 'failed',
                'created_at' => $this->iso($message->getAttribute('created_at')),
                'pending_approvals' => $message === $latest ? $this->approvals->fromMessage($message) : [],
            ])
            ->all());
    }

    private function iso(mixed $value): ?string
    {
        return $value instanceof CarbonInterface ? $value->toIso8601String() : null;
    }
}

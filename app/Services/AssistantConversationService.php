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
     * The user and assistant messages of a conversation, oldest first. Tool calls stay on the server.
     *
     * @return list<array{id: string, role: string, content: string, is_failed: bool, created_at: string|null}>
     */
    public function messages(string $conversationId): array
    {
        return array_values(ConversationMessage::query()
            ->where('conversation_id', $conversationId)
            ->whereIn('role', ['user', 'assistant'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (ConversationMessage $message): array => [
                'id' => (string) $message->getKey(),
                'role' => (string) $message->getAttribute('role'),
                'content' => (string) $message->getAttribute('content'),
                'is_failed' => $message->getAttribute('status')?->value === 'failed',
                'created_at' => $this->iso($message->getAttribute('created_at')),
            ])
            ->all());
    }

    private function iso(mixed $value): ?string
    {
        return $value instanceof CarbonInterface ? $value->toIso8601String() : null;
    }
}

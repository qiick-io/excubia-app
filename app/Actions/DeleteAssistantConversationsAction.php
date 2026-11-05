<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;

/**
 * Deletes conversations of the user and their messages. Ids that are not the user's own are
 * skipped, so nobody can delete someone else's chats.
 */
class DeleteAssistantConversationsAction
{
    /**
     * @param  list<string>  $conversationIds
     * @return int the number of conversations deleted
     */
    public function execute(User $user, array $conversationIds): int
    {
        return DB::transaction(function () use ($user, $conversationIds): int {
            $ids = Conversation::query()
                ->whereKey($conversationIds)
                ->where('participant_type', Conversation::participantType($user))
                ->where('participant_id', Conversation::participantKey($user))
                ->pluck((new Conversation)->getKeyName())
                ->all();

            ConversationMessage::query()->whereIn('conversation_id', $ids)->delete();

            return Conversation::query()->whereKey($ids)->delete();
        });
    }
}

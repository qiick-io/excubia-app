<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Date;

/**
 * Sets the read state of a user's in-app notifications (#296). Every query goes through the
 * user's own `notifications()`, so ids of another user's rows change nothing.
 */
class MarkNotificationsAction
{
    /**
     * @param  list<string>|null  $notificationIds  null: every notification of the user
     */
    public function execute(User $user, ?array $notificationIds, bool $isRead): void
    {
        $notifications = $isRead ? $user->unreadNotifications() : $user->notifications();

        if ($notificationIds !== null) {
            $notifications->whereIn('id', $notificationIds);
        }

        $notifications->update(['read_at' => $isRead ? Date::now() : null]);
    }
}

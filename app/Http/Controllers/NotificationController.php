<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\MarkNotificationsAction;
use App\Http\Requests\MarkNotificationsReadRequest;
use App\Http\Requests\MarkNotificationsUnreadRequest;
use App\Models\User;
use App\Services\UserNotificationsService;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/**
 * The notification centre's JSON endpoints (#296, app-ui `docs/notifications.md`): the signed-in
 * user's notifications, their unread count, and marking them read or unread. Only the user's own
 * rows are ever read or changed.
 */
class NotificationController extends Controller
{
    public function index(#[CurrentUser] User $user, UserNotificationsService $notifications): JsonResponse
    {
        return response()->json($notifications->page($user));
    }

    public function unreadCount(#[CurrentUser] User $user, UserNotificationsService $notifications): JsonResponse
    {
        return response()->json(['count' => $notifications->unreadCount($user)]);
    }

    public function markRead(MarkNotificationsReadRequest $request, #[CurrentUser] User $user, MarkNotificationsAction $mark, UserNotificationsService $notifications): JsonResponse
    {
        $mark->execute($user, $request->notificationIds(), true);

        return response()->json(['unread_count' => $notifications->unreadCount($user)]);
    }

    public function markUnread(MarkNotificationsUnreadRequest $request, #[CurrentUser] User $user, MarkNotificationsAction $mark, UserNotificationsService $notifications): JsonResponse
    {
        $mark->execute($user, $request->notificationIds(), false);

        return response()->json(['unread_count' => $notifications->unreadCount($user)]);
    }
}

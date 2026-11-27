<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Reads a user's in-app notifications (#296) in the shape of the app-ui notification centre's
 * backend contract (`docs/notifications.md`): rows with ISO 8601 times, newest first.
 */
class UserNotificationsService
{
    /** Notifications per page, as the contract fixes it. */
    public const PER_PAGE = 20;

    /**
     * @return array{data: list<array{id: string, type: string, data: array<string, mixed>, read_at: string|null, created_at: string|null}>, current_page: int, last_page: int, per_page: int, total: int}
     */
    public function page(User $user): array
    {
        $paginator = $user->notifications()->paginate(self::PER_PAGE);

        return [
            'data' => array_values($paginator->getCollection()->map(fn (DatabaseNotification $notification): array => [
                'id' => (string) $notification->id,
                'type' => $notification->type,
                'data' => $notification->data,
                'read_at' => $notification->read_at?->toIso8601String(),
                'created_at' => $notification->created_at?->toIso8601String(),
            ])->all()),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }

    public function unreadCount(User $user): int
    {
        return $user->unreadNotifications()->count();
    }
}

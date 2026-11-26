<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ActivityEventEnum;
use App\Models\AlertLog;
use App\Models\AlertMute;
use App\Models\User;
use Illuminate\Support\Facades\Date;

/**
 * Mutes the rule (the event on the project) or the issue (the event and its subject) of an alert
 * from the history (#114), for 1 h, 24 h, 7 d or forever.
 */
class MuteAlertAction
{
    /** Mute lengths in hours; `forever` is null. */
    public const DURATIONS = ['1h' => 1, '24h' => 24, '7d' => 168, 'forever' => null];

    public function execute(AlertLog $alert, bool $isIssue, string $duration, User $user): AlertMute
    {
        $hours = self::DURATIONS[$duration] ?? null;

        $mute = AlertMute::query()->create([
            'project_id' => $alert->project_id,
            'event' => $alert->event,
            'subject' => $isIssue ? $alert->subject : null,
            'label' => $isIssue ? mb_substr($alert->summary, 0, 255) : null,
            'until' => $hours === null ? null : Date::now()->addHours($hours),
            'user_id' => $user->id,
        ]);

        // Silencing alerts is audited like the other domain writes (#431), from the UI and the AI tools.
        activity()
            ->performedOn($mute)
            ->causedBy($user)
            ->event(ActivityEventEnum::AlertMuted->value)
            ->withProperties(['project_id' => $mute->project_id, 'event' => $mute->event, 'subject' => $mute->subject, 'until' => $mute->until?->toIso8601String()])
            ->log('Alert muted');

        return $mute;
    }
}

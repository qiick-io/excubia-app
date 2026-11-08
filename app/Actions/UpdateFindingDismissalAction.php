<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\FindingEventEnum;
use App\Models\Finding;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Dismisses a finding with a reason and an optional last day (#18), with the fields of an
 * excubia.json ignore rule, or removes its dismissal (`$reason` null). A dismissed finding keeps
 * its lifecycle (it still resolves and reopens) but raises no finding alerts and leaves the worst
 * severity and the digest. Records a `finding_events` row with the user.
 */
class UpdateFindingDismissalAction
{
    public function execute(Finding $finding, User $actor, ?string $reason, ?CarbonInterface $until = null): Finding
    {
        return DB::transaction(function () use ($finding, $actor, $reason, $until): Finding {
            $now = Date::now();
            $isDismissal = $reason !== null;

            $finding->forceFill([
                'dismissed_at' => $isDismissal ? $now : null,
                'dismissed_until' => $isDismissal ? $until?->toDateString() : null,
                'dismissed_reason' => $reason,
                'dismissed_by_user_id' => $isDismissal ? $actor->id : null,
            ])->save();

            $finding->events()->create([
                'event' => $isDismissal ? FindingEventEnum::Dismissed : FindingEventEnum::Undismissed,
                'user_id' => $actor->id,
                'reason' => $reason,
                'until' => $isDismissal ? $until?->toDateString() : null,
                'occurred_at' => $now,
            ]);

            return $finding;
        });
    }
}

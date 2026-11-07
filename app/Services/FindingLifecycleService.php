<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FindingEventEnum;
use App\Enums\FindingStatusEnum;
use App\Enums\SeverityEnum;
use App\Models\Finding;
use App\Models\FindingEvent;
use App\Models\Project;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;

/**
 * Reads of the finding lifecycle (#18): whether a dismissal is active, the worst open severity,
 * the rows of the Findings tab and the findings a week brought (digest).
 */
class FindingLifecycleService
{
    /** Resolved findings on the Findings tab, newest first, so a long history stays light. */
    public const RESOLVED_ROWS = 200;

    public function __construct(private ReportFindingsService $findings) {}

    /**
     * A dismissal is active from `dismissed_at` through `dismissed_until` (inclusive, as an
     * excubia.json ignore rule's `until`), or for good without one.
     */
    public function isDismissed(Finding $finding): bool
    {
        return $finding->dismissed_at !== null
            && ($finding->dismissed_until === null || $finding->dismissed_until->toDateString() >= Date::today()->toDateString());
    }

    /**
     * The worst severity of the open findings without an active dismissal.
     *
     * @param  iterable<Finding>  $findings
     */
    public function worstSeverity(iterable $findings): ?SeverityEnum
    {
        $worst = null;

        foreach ($findings as $finding) {
            $severity = SeverityEnum::tryFrom($finding->severity);

            if ($severity === null || $finding->status !== FindingStatusEnum::Open || $this->isDismissed($finding)) {
                continue;
            }

            if ($worst === null || $severity->rank() < $worst->rank()) {
                $worst = $severity;
            }
        }

        return $worst;
    }

    /**
     * The Findings tab: every open finding (also those whose check did not run in the latest
     * report) and the latest resolved ones, in page order, each the last report row with its
     * check group and a `lifecycle` block (state, dates, dismissal, history).
     *
     * @return list<array<string, mixed>>
     */
    public function forPage(Project $project): array
    {
        $open = $project->findings()->where('status', FindingStatusEnum::Open->value)->get();
        $resolved = $project->findings()->where('status', FindingStatusEnum::Resolved->value)
            ->orderByDesc('resolved_at')->orderByDesc('id')->limit(self::RESOLVED_ROWS)->get();
        $all = $open->concat($resolved);
        $all->load(['dismisser:id,first_name,last_name', 'events.healthReport:id,release', 'events.user:id,first_name,last_name']);

        return $this->findings->prioritize(array_values($all->map(fn (Finding $finding): array => [
            ...$finding->finding,
            'lifecycle' => $this->lifecycle($finding),
        ])->all()));
    }

    /**
     * Findings that opened or came back since `$since` and are still open and not dismissed, in
     * the order they first appeared (weekly digest).
     *
     * @return list<array<string, mixed>>
     */
    public function newSince(Project $project, CarbonInterface $since): array
    {
        return array_values($project->findings()->active()
            ->where(fn ($query) => $query->where('first_seen_at', '>=', $since)
                ->orWhereHas('events', fn ($events) => $events->where('event', FindingEventEnum::Reopened->value)->where('occurred_at', '>=', $since)))
            ->orderBy('first_seen_at')
            ->orderBy('id')
            ->get()
            ->map(fn (Finding $finding): array => $finding->finding)
            ->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function lifecycle(Finding $finding): array
    {
        $isDismissed = $this->isDismissed($finding);

        return [
            'id' => $finding->id,
            // A resolved finding stays resolved on the tab; its dismissal still shows.
            'status' => $finding->status === FindingStatusEnum::Resolved ? 'resolved' : ($isDismissed ? 'dismissed' : 'open'),
            'firstSeenAt' => $finding->first_seen_at->toIso8601String(),
            'lastSeenAt' => $finding->last_seen_at->toIso8601String(),
            'resolvedAt' => $finding->resolved_at?->toIso8601String(),
            'reopenedCount' => $finding->reopened_count,
            'dismissal' => $isDismissed ? [
                'reason' => $finding->dismissed_reason,
                'until' => $finding->dismissed_until?->toDateString(),
                'by' => $finding->dismisser?->name,
                'at' => $finding->dismissed_at?->toIso8601String(),
            ] : null,
            'history' => $finding->events->map(fn (FindingEvent $event): array => [
                'event' => $event->event->value,
                'occurredAt' => $event->occurred_at->toIso8601String(),
                'release' => $event->healthReport?->release,
                'by' => $event->user?->name,
                'reason' => $event->reason,
                'until' => $event->until?->toDateString(),
            ])->values()->all(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AlertEventEnum;
use App\Models\AlertLog;
use App\Models\AlertMute;
use App\Models\MaintenanceWindow;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;

/**
 * Whether an alert of a project is suppressed (#114), and why. AlertDispatcherService asks it
 * for every alert, so a mute or a maintenance window holds for every event and channel:
 * - `muted`: an active mute of the event (rule) or of the event and its subject (issue);
 * - `maintenance_window`: a one-off or weekly maintenance window of the project is in force;
 * - `maintenance_mode`: the latest report has the core `laravel-maintenance-mode` finding;
 * - `duplicate` (#143): an `infrastructure.failed` and a `health.failed` of the same check and
 *   fingerprint within DUPLICATE_MINUTES: the first one goes out, the other is kept as history.
 * `project.token_changed` is a security event: only an explicit mute silences it. Schedule
 * events ignore maintenance mode: the schedule evaluation (#110) already pauses the tasks that
 * do not run then, so a task that runs in maintenance mode and misses still alerts.
 */
class AlertSuppressionService
{
    public const MUTED = 'muted';

    public const MAINTENANCE_WINDOW = 'maintenance_window';

    public const MAINTENANCE_MODE = 'maintenance_mode';

    public const DUPLICATE = 'duplicate';

    /** How far back a report failure and a health failure of one check count as one alert. */
    public const DUPLICATE_MINUTES = 15;

    public function __construct(private ReportFindingsService $findings) {}

    /**
     * The suppression reason, or null when the alert goes out.
     */
    public function reason(Project $project, AlertEventEnum $event, ?string $subject = null): ?string
    {
        $isMuted = AlertMute::query()
            ->active()
            ->where('project_id', $project->id)
            ->where('event', $event->value)
            ->where(fn ($query) => $query->whereNull('subject')->when($subject !== null, fn ($bySubject) => $bySubject->orWhere('subject', $subject)))
            ->exists();

        if ($isMuted) {
            return self::MUTED;
        }

        if ($event === AlertEventEnum::ProjectTokenChanged) {
            return null;
        }

        if ($this->isInMaintenanceWindow($project)) {
            return self::MAINTENANCE_WINDOW;
        }

        if (in_array($event, [AlertEventEnum::ScheduleMissed, AlertEventEnum::ScheduleFailed, AlertEventEnum::SchedulerDown], true)) {
            return null;
        }

        // ponytail: reads the latest report once per alert; keep a maintenance flag on the
        // report row if a big report raises many alerts at once.
        return $this->findings->isInMaintenanceMode($project) ? self::MAINTENANCE_MODE : null;
    }

    /**
     * Whether the other of `infrastructure.failed` / `health.failed` already went out (or found
     * no channel) for the same check and one of the fingerprints within DUPLICATE_MINUTES. An
     * `infrastructure.failed` subject is a finding identity (`checkId|subject|advisoryId`), a
     * `health.failed` subject the check id. A suppressed alert reached no one: it does not count.
     *
     * @param  list<string>  $fingerprints
     */
    public function isDuplicate(Project $project, AlertEventEnum $event, ?string $subject, array $fingerprints): bool
    {
        $other = match ($event) {
            AlertEventEnum::InfrastructureFailed => AlertEventEnum::HealthFailed,
            AlertEventEnum::HealthFailed => AlertEventEnum::InfrastructureFailed,
            default => null,
        };

        if ($other === null || $subject === null || $fingerprints === []) {
            return false;
        }

        $checkId = Str::before($subject, '|');

        return AlertLog::query()
            ->where('project_id', $project->id)
            ->where('event', $other->value)
            ->where('status', '!=', AlertLog::SUPPRESSED)
            ->where('created_at', '>=', Date::now()->subMinutes(self::DUPLICATE_MINUTES))
            ->whereNotNull('fingerprints')
            ->get(['subject', 'fingerprints'])
            ->contains(fn (AlertLog $alert): bool => Str::before((string) $alert->subject, '|') === $checkId
                && array_intersect($alert->fingerprints ?? [], $fingerprints) !== []);
    }

    public function isInMaintenanceWindow(Project $project, ?CarbonInterface $at = null): bool
    {
        return MaintenanceWindow::query()
            ->where('project_id', $project->id)
            ->get()
            ->contains(fn (MaintenanceWindow $window): bool => $this->isWindowActive($window, $at));
    }

    /**
     * Whether the window covers `$at` (now by default). A weekly window starts on one of its
     * weekdays at `start_time` in its own timezone, so it follows that zone's DST changes, and
     * may run past midnight into the next days.
     */
    public function isWindowActive(MaintenanceWindow $window, ?CarbonInterface $at = null): bool
    {
        $at = CarbonImmutable::instance($at ?? Date::now());

        if ($window->recurrence === MaintenanceWindow::ONCE) {
            return $window->starts_at !== null && $window->ends_at !== null
                && $at->greaterThanOrEqualTo($window->starts_at) && $at->lessThan($window->ends_at);
        }

        if ($window->start_time === null || ! $window->duration_minutes) {
            return false;
        }

        $local = $at->setTimezone($window->timezone);
        $weekdays = array_map('intval', $window->weekdays ?? []);

        // Starts on today or on one of the days before that still reach `$at`.
        for ($daysBack = 0; $daysBack <= intdiv($window->duration_minutes, 1440) + 1; $daysBack++) {
            $day = $local->subDays($daysBack);

            if (! in_array($day->isoWeekday(), $weekdays, true)) {
                continue;
            }

            $start = CarbonImmutable::parse($day->toDateString().' '.$window->start_time, $window->timezone);
            $end = $start->addMinutes($window->duration_minutes);

            if ($at->greaterThanOrEqualTo($start) && $at->lessThan($end)) {
                return true;
            }
        }

        return false;
    }
}

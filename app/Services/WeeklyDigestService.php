<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AlertEventEnum;
use App\Enums\ExceptionStatusEnum;
use App\Enums\ScheduledTaskStatusEnum;
use App\Models\AlertLog;
use App\Models\ExceptionGroup;
use App\Models\HealthReport;
use App\Models\Project;
use App\Models\ScheduledTask;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;

/**
 * The weekly email digest of one user (#114), over the projects they may see and the last 7
 * days: new findings (opened or back this week and still open, not dismissed: the finding
 * lifecycle, #18), regressions (the week's `project.worsened` alerts, which follow the lifecycle), EOL findings of the latest report, the most frequent open
 * exceptions seen this week (class, where and count, never the message) and scheduled tasks
 * with a problem (#110), and the estimated AI spend of the 7 days against the 7 before (#129,
 * from the daily usage and the price table). Projects with nothing to say are left out; null
 * when none has.
 *
 * @phpstan-type AiCost array{week: float, previousWeek: float, change: int|null, hasUnpriced: bool}
 */
class WeeklyDigestService
{
    /** Rows per section and project, so the mail stays short. */
    public const ROWS = 5;

    private const EOL_CHECKS = ['php-eol', 'laravel-eol', 'node-eol'];

    private const SCHEDULE_PROBLEMS = [ScheduledTaskStatusEnum::Late, ScheduledTaskStatusEnum::Missed, ScheduledTaskStatusEnum::Failed, ScheduledTaskStatusEnum::SchedulerDown];

    public function __construct(
        private ReportFindingsService $findings,
        private FindingLifecycleService $lifecycle,
        private AiPriceService $prices,
    ) {}

    /**
     * @return list<array{name: string, url: string, newFindings: list<array{severity: string, text: string}>, newFindingsCount: int, regressions: list<string>, eol: list<string>, exceptions: list<array{class: string, where: string, count: int}>, schedule: list<array{task: string, status: string}>, aiCost: AiCost|null}>|null
     */
    public function forUser(User $user, ?CarbonInterface $now = null): ?array
    {
        if (! $user->can('viewAny', Project::class)) {
            return null;
        }

        $now = ($now ?? Date::now())->copy();
        $since = $now->copy()->subDays(7);
        $projects = [];

        foreach (Project::query()->visibleTo($user)->where('is_enabled', true)->orderBy('name')->get() as $project) {
            $digest = $this->forProject($project, $since, $now);

            if ($digest !== null) {
                $projects[] = $digest;
            }
        }

        return $projects === [] ? null : $projects;
    }

    /**
     * @return array{name: string, url: string, newFindings: list<array{severity: string, text: string}>, newFindingsCount: int, regressions: list<string>, eol: list<string>, exceptions: list<array{class: string, where: string, count: int}>, schedule: list<array{task: string, status: string}>, aiCost: AiCost|null}|null
     */
    private function forProject(Project $project, CarbonInterface $since, CarbonInterface $now): ?array
    {
        $latest = $this->latestReport($project, null);
        // The first report of a project brings everything at once: as alerts, it is no news.
        $hasBaseline = $this->latestReport($project, $since) !== null;
        $new = $hasBaseline ? $this->lifecycle->newSince($project, $since) : [];

        $digest = [
            'name' => $project->name,
            'url' => route('projects.show', $project->slug),
            'newFindings' => array_map(fn (array $finding): array => [
                'severity' => (string) ($finding['severity'] ?? ''),
                'text' => trim(($finding['subject'] ?? '').': '.($finding['message'] ?? ''), ': '),
            ], array_slice($new, 0, self::ROWS)),
            'newFindingsCount' => count($new),
            'regressions' => array_values(AlertLog::query()
                ->where('project_id', $project->id)
                ->where('event', AlertEventEnum::ProjectWorsened->value)
                ->where('created_at', '>=', $since)
                ->orderByDesc('created_at')
                ->limit(self::ROWS)
                ->pluck('summary')
                ->map(fn (mixed $summary): string => (string) $summary)
                ->all()),
            'eol' => $latest === null ? [] : array_values(array_map(
                fn (array $finding): string => (string) ($finding['message'] ?? ''),
                array_filter($this->findings->issues($latest), fn (array $finding): bool => in_array($finding['checkId'] ?? null, self::EOL_CHECKS, true)),
            )),
            'exceptions' => array_values(ExceptionGroup::query()
                ->where('project_id', $project->id)
                ->where('status', ExceptionStatusEnum::Open->value)
                ->where('last_seen', '>=', $since)
                ->orderByDesc('count')
                ->limit(self::ROWS)
                ->get()
                ->map(fn (ExceptionGroup $group): array => ['class' => $group->class, 'where' => $group->context_name, 'count' => $group->count])
                ->all()),
            'schedule' => array_values(ScheduledTask::query()
                ->where('project_id', $project->id)
                ->whereIn('status', array_map(fn (ScheduledTaskStatusEnum $status): string => $status->value, self::SCHEDULE_PROBLEMS))
                ->orderBy('position')
                ->limit(self::ROWS)
                ->get()
                ->map(fn (ScheduledTask $task): array => ['task' => $task->task, 'status' => $task->status->value])
                ->all()),
            'aiCost' => $this->aiCost($project, $now),
        ];

        $hasContent = $digest['newFindingsCount'] > 0 || $digest['regressions'] !== [] || $digest['eol'] !== [] || $digest['exceptions'] !== [] || $digest['schedule'] !== [] || $digest['aiCost'] !== null;

        return $hasContent ? $digest : null;
    }

    /**
     * The estimated AI spend of the 7 whole days before today and of the 7 before those (UTC);
     * null when the project made no AI call in either. `change` is the percent change.
     *
     * @return AiCost|null
     */
    private function aiCost(Project $project, CarbonInterface $now): ?array
    {
        $today = CarbonImmutable::instance($now)->utc()->startOfDay();
        $week = $this->prices->costOfDays($project, $today->subDays(7), $today->subDay());
        $previous = $this->prices->costOfDays($project, $today->subDays(14), $today->subDays(8));

        if ($week['calls'] <= 0 && $previous['calls'] <= 0) {
            return null;
        }

        return [
            'week' => round($week['cost'], 2),
            'previousWeek' => round($previous['cost'], 2),
            // Percent change against the week before; null without spend that week.
            'change' => $previous['cost'] > 0 ? (int) round(($week['cost'] - $previous['cost']) * 100 / $previous['cost']) : null,
            'hasUnpriced' => $week['hasUnpriced'] || $previous['hasUnpriced'],
        ];
    }

    /**
     * The latest report, or the latest one received before `$before`.
     */
    private function latestReport(Project $project, ?CarbonInterface $before): ?HealthReport
    {
        return $project->healthReports()
            ->when($before !== null, fn ($query) => $query->where('received_at', '<', $before))
            ->latest('received_at')
            ->latest('id')
            ->first();
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AlertEventEnum;
use App\Enums\RuntimeTypeEnum;
use App\Models\AlertLog;
use App\Models\Project;
use App\Models\RuntimeAggregate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

/**
 * The AI usage alert rules (#129), each off until set, through AlertDispatcherService:
 * - `ai.budget_warning` / `ai.budget_exceeded`: the estimated spend of the calendar month (UTC)
 *   reached 80 % / 100 % of the project's monthly budget, once per month and threshold (only
 *   the highest one crossed is sent);
 * - `ai.spend_spike`: today's estimated spend (UTC) reached the percentage set in Settings →
 *   Integrations of the average day of the 7 days before, at least MIN_SPIKE_SPEND, once a day;
 * - `ai.error_rate`: the failed share of the AI calls of the last hour is above the threshold,
 *   with at least MIN_CALLS calls, at most once an hour.
 * Spend comes from the daily usage and the price table (AiPriceService); unpriced models count
 * as zero. The alert history is the cooldown.
 *
 * ponytail: the budget and spike cooldowns read the alert history, which is kept for the longer
 * of the runtime and exceptions retention; with both under a month, a budget alert can repeat
 * within the month. Keep the alerted month on the project if that matters.
 */
class AiAlertsService
{
    /** Budget thresholds (percent), highest first, with their event. */
    public const BUDGET_THRESHOLDS = [100 => AlertEventEnum::AiBudgetExceeded, 80 => AlertEventEnum::AiBudgetWarning];

    /** Spend (USD) below which a day never counts as a spike. */
    public const MIN_SPIKE_SPEND = 1.0;

    /** AI calls in the hour below which the error rate never alerts. */
    public const MIN_CALLS = 20;

    public function __construct(
        private SettingsRepositoryService $settings,
        private AlertDispatcherService $alerts,
        private AiPriceService $prices,
    ) {}

    /**
     * Whether a rule is on anywhere, so the scheduled command can skip the projects.
     */
    public function hasRules(): bool
    {
        return $this->settings->alertAiSpendSpikePercent() !== null
            || $this->settings->alertAiErrorRateAbove() !== null
            || Project::query()->whereNotNull('ai_monthly_budget')->where('ai_monthly_budget', '>', 0)->exists();
    }

    /**
     * Sends the alerts of the rules that are on; returns how many went to the dispatcher.
     */
    public function evaluate(Project $project): int
    {
        $now = CarbonImmutable::instance(Date::now())->utc();

        return $this->budget($project, $now) + $this->spike($project, $now) + $this->errorRate($project, $now);
    }

    private function budget(Project $project, CarbonImmutable $now): int
    {
        $budget = (float) $project->ai_monthly_budget;

        if ($budget <= 0) {
            return 0;
        }

        $month = $now->format('Y-m');
        $spend = $this->prices->costOfDays($project, $now->startOfMonth(), $now)['cost'];

        foreach (self::BUDGET_THRESHOLDS as $percent => $event) {
            if ($spend < $budget * $percent / 100) {
                continue;
            }

            // A higher threshold already sent this month covers the lower ones.
            $subjects = array_map(fn (int $threshold): string => "ai-budget:{$month}:{$threshold}", array_keys(array_filter(self::BUDGET_THRESHOLDS, fn (int $threshold): bool => $threshold >= $percent, ARRAY_FILTER_USE_KEY)));

            if ($this->hasAlerted($project, $subjects, null)) {
                return 0;
            }

            $this->alerts->dispatch($event, $project, sprintf('estimated AI spend this month: $%.2f, %d%% of the $%.2f budget', $spend, (int) floor($spend * 100 / $budget), $budget), [
                'month' => $month, 'threshold' => $percent, 'spend' => round($spend, 2), 'budget' => round($budget, 2),
            ], subject: "ai-budget:{$month}:{$percent}");

            return 1;
        }

        return 0;
    }

    private function spike(Project $project, CarbonImmutable $now): int
    {
        $percent = $this->settings->alertAiSpendSpikePercent();

        if ($percent === null) {
            return 0;
        }

        $today = $now->startOfDay();
        $spend = $this->prices->costOfDays($project, $today, $today)['cost'];
        $average = $this->prices->costOfDays($project, $today->subDays(7), $today->subDay())['cost'] / 7;
        $subject = 'ai-spike:'.$today->toDateString();

        if ($average <= 0 || $spend < self::MIN_SPIKE_SPEND || $spend * 100 < $average * $percent || $this->hasAlerted($project, [$subject], null)) {
            return 0;
        }

        $this->alerts->dispatch(AlertEventEnum::AiSpendSpike, $project, sprintf('estimated AI spend today: $%.2f, %d%% of the 7-day average ($%.2f a day)', $spend, (int) floor($spend * 100 / $average), $average), [
            'spend' => round($spend, 2), 'average' => round($average, 2), 'percent' => (int) floor($spend * 100 / $average),
        ], subject: $subject);

        return 1;
    }

    private function errorRate(Project $project, CarbonImmutable $now): int
    {
        $threshold = $this->settings->alertAiErrorRateAbove();

        if ($threshold === null) {
            return 0;
        }

        $rows = RuntimeAggregate::query()
            ->where('project_id', $project->id)
            ->where('type', RuntimeTypeEnum::Ai->value)
            ->where('bucket', '>=', $now->subHour())
            ->get(['count', 'error_count', 'sample_rate']);
        $calls = $rows->sum(fn (RuntimeAggregate $row): float => $row->count / max($row->sample_rate, 0.0001));
        $errors = $rows->sum(fn (RuntimeAggregate $row): float => $row->error_count / max($row->sample_rate, 0.0001));
        $rate = $calls > 0 ? $errors * 100 / $calls : 0.0;

        if ($calls < self::MIN_CALLS || $rate <= $threshold || $this->hasAlerted($project, ['ai-errors'], $now->subHour())) {
            return 0;
        }

        $this->alerts->dispatch(AlertEventEnum::AiErrorRate, $project, sprintf('AI calls: %.1f%% failed in the last hour (%d of %d)', $rate, (int) round($errors), (int) round($calls)), [
            'errorRate' => round($rate, 1), 'errors' => (int) round($errors), 'calls' => (int) round($calls),
        ], subject: 'ai-errors');

        return 1;
    }

    /**
     * Whether the project already alerted about one of the subjects (since a time, or ever).
     *
     * @param  list<string>  $subjects
     */
    private function hasAlerted(Project $project, array $subjects, ?CarbonImmutable $since): bool
    {
        return AlertLog::query()
            ->where('project_id', $project->id)
            ->whereIn('subject', $subjects)
            ->when($since !== null, fn ($query) => $query->where('created_at', '>', $since))
            ->exists();
    }
}

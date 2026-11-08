<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AlertEventEnum;
use App\Enums\RuntimeTypeEnum;
use App\Models\AlertLog;
use App\Models\Project;
use App\Models\RuntimeAggregate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;

/**
 * The optional runtime alert rules (#107, #114), over the project's last hour of runtime
 * aggregates, each off until set in Settings → Integrations:
 * - `runtime.cache_hit_ratio_low`: a cache store's hit ratio is under the threshold, with at
 *   least MIN_CACHE_LOOKUPS lookups;
 * - `runtime.delivery_failed`: a mailable or notification failed;
 * - `runtime.log_spike`: a log channel wrote at least the threshold of error-level entries;
 * - `billing.webhook_failures` (#178): a billing provider's webhooks failed (5xx, the provider
 *   retries them) at least the threshold of times;
 * - `billing.payment_failed` (#178): a billing provider reported failed payments;
 * - `auth.failed_login_spike` (#180): an auth guard saw at least the threshold of failed logins;
 * - `scout.index_failures` (#184): a searchable model's Scout index jobs failed at least the
 *   threshold of times (the search index drifts from the database).
 * Counts are estimated (`count / sample_rate`). Each subject alerts at most once per hour: the
 * alert history is the cooldown.
 *
 * ponytail: a fixed threshold per hour, not a baseline; compare with the previous days if
 * fixed thresholds prove noisy.
 */
class RuntimeAlertsService
{
    public const MIN_CACHE_LOOKUPS = 100;

    private const ERROR_LOG_LEVELS = ['emergency', 'alert', 'critical', 'error'];

    public function __construct(
        private SettingsRepositoryService $settings,
        private AlertDispatcherService $alerts,
    ) {}

    /**
     * Whether at least one rule is on, so the scheduled command can skip the projects.
     */
    public function hasRules(): bool
    {
        return $this->settings->alertCacheHitRatioBelow() !== null
            || $this->settings->shouldAlertDeliveryFailures()
            || $this->settings->alertLogSpikePerHour() !== null
            || $this->settings->alertBillingWebhookFailuresPerHour() !== null
            || $this->settings->shouldAlertBillingPaymentFailures()
            || $this->settings->alertAuthFailedLoginsPerHour() !== null
            || $this->settings->alertScoutIndexFailuresPerHour() !== null;
    }

    /**
     * Sends the alerts of the rules that are on; returns how many went to the dispatcher.
     */
    public function evaluate(Project $project): int
    {
        $series = RuntimeAggregate::query()
            ->where('project_id', $project->id)
            ->whereIn('type', [RuntimeTypeEnum::Cache->value, RuntimeTypeEnum::Mail->value, RuntimeTypeEnum::Notification->value, RuntimeTypeEnum::LogLevel->value, RuntimeTypeEnum::BillingWebhook->value, RuntimeTypeEnum::Auth->value, RuntimeTypeEnum::Scout->value])
            ->where('bucket', '>=', Date::now()->subHour())
            ->get()
            ->groupBy(fn (RuntimeAggregate $aggregate): string => $aggregate->type->value);
        $sent = 0;

        $ratio = $this->settings->alertCacheHitRatioBelow();

        if ($ratio !== null) {
            foreach ($series->get(RuntimeTypeEnum::Cache->value, collect())->groupBy(fn (RuntimeAggregate $aggregate): string => (string) ($aggregate->dimensions['store'] ?? '')) as $store => $rows) {
                $hits = $this->estimate($rows, fn (RuntimeAggregate $aggregate): float => (float) ($aggregate->metrics['hits'] ?? 0));
                $lookups = $hits + $this->estimate($rows, fn (RuntimeAggregate $aggregate): float => (float) ($aggregate->metrics['misses'] ?? 0));
                $percent = $lookups > 0 ? (int) floor($hits * 100 / $lookups) : 100;

                if ($lookups >= self::MIN_CACHE_LOOKUPS && $percent < $ratio) {
                    $sent += $this->send($project, AlertEventEnum::CacheHitRatioLow, "cache:{$store}", "cache store {$store}: hit ratio {$percent}% in the last hour", ['store' => $store, 'hitRatio' => $percent, 'lookups' => $lookups]);
                }
            }
        }

        if ($this->settings->shouldAlertDeliveryFailures()) {
            foreach ([RuntimeTypeEnum::Mail->value => 'mailable', RuntimeTypeEnum::Notification->value => 'notification'] as $type => $dimension) {
                foreach ($series->get($type, collect())->groupBy(fn (RuntimeAggregate $aggregate): string => (string) ($aggregate->dimensions[$dimension] ?? '')) as $name => $rows) {
                    $failed = $this->estimate($rows, fn (RuntimeAggregate $aggregate): float => $aggregate->error_count);

                    if ($failed > 0) {
                        $sent += $this->send($project, AlertEventEnum::DeliveryFailed, "{$type}:{$name}", "{$type} {$name}: {$failed} failed in the last hour", ['type' => $type, 'name' => $name, 'failed' => $failed]);
                    }
                }
            }
        }

        $spike = $this->settings->alertLogSpikePerHour();

        if ($spike !== null) {
            $errors = $series->get(RuntimeTypeEnum::LogLevel->value, collect())
                ->filter(fn (RuntimeAggregate $aggregate): bool => in_array($aggregate->dimensions['level'] ?? null, self::ERROR_LOG_LEVELS, true));

            foreach ($errors->groupBy(fn (RuntimeAggregate $aggregate): string => (string) ($aggregate->dimensions['channel'] ?? 'default')) as $channel => $rows) {
                $count = $this->estimate($rows, fn (RuntimeAggregate $aggregate): float => $aggregate->count);

                if ($count >= $spike) {
                    $sent += $this->send($project, AlertEventEnum::LogSpike, "log:{$channel}", "log channel {$channel}: {$count} error entries in the last hour", ['channel' => $channel, 'count' => $count]);
                }
            }
        }

        return $sent
            + $this->billing($project, $series->get(RuntimeTypeEnum::BillingWebhook->value, collect()))
            + $this->failedLogins($project, $series->get(RuntimeTypeEnum::Auth->value, collect()))
            + $this->scoutIndexFailures($project, $series->get(RuntimeTypeEnum::Scout->value, collect()));
    }

    /**
     * The Scout index failure rule (#184), per searchable model over the last hour: failed
     * `index` and `remove` jobs (a `search` row never has errors), across engines.
     *
     * @param  Collection<int, RuntimeAggregate>  $series
     */
    private function scoutIndexFailures(Project $project, Collection $series): int
    {
        $threshold = $this->settings->alertScoutIndexFailuresPerHour();

        if ($threshold === null) {
            return 0;
        }

        $sent = 0;

        foreach ($series->groupBy(fn (RuntimeAggregate $aggregate): string => (string) ($aggregate->dimensions['model'] ?? '')) as $model => $rows) {
            $failed = $this->estimate($rows, fn (RuntimeAggregate $aggregate): float => $aggregate->error_count);

            if ($failed >= $threshold) {
                $sent += $this->send($project, AlertEventEnum::ScoutIndexFailures, "scout:{$model}", "{$model}: {$failed} failed Scout index jobs in the last hour", ['model' => $model, 'failedJobs' => $failed]);
            }
        }

        return $sent;
    }

    /**
     * The failed-login rule (#180), per auth guard over the last hour: a brute-force or
     * credential-stuffing signal. Lockouts are not counted: they already show on the card.
     *
     * @param  Collection<int, RuntimeAggregate>  $series
     */
    private function failedLogins(Project $project, Collection $series): int
    {
        $threshold = $this->settings->alertAuthFailedLoginsPerHour();

        if ($threshold === null) {
            return 0;
        }

        $sent = 0;

        foreach ($series->groupBy(fn (RuntimeAggregate $aggregate): string => (string) ($aggregate->dimensions['guard'] ?? '')) as $guard => $rows) {
            $failed = $this->estimate($rows, fn (RuntimeAggregate $aggregate): float => $aggregate->error_count);

            if ($failed >= $threshold) {
                $sent += $this->send($project, AlertEventEnum::AuthFailedLoginSpike, "auth:{$guard}", "guard {$guard}: {$failed} failed logins in the last hour", ['guard' => $guard, 'failedLogins' => $failed]);
            }
        }

        return $sent;
    }

    /**
     * The billing webhook rules (#178), per provider over the last hour.
     *
     * @param  Collection<int, RuntimeAggregate>  $series
     */
    private function billing(Project $project, Collection $series): int
    {
        $threshold = $this->settings->alertBillingWebhookFailuresPerHour();
        $shouldAlertPayments = $this->settings->shouldAlertBillingPaymentFailures();
        $sent = 0;

        foreach ($series->groupBy(fn (RuntimeAggregate $aggregate): string => (string) ($aggregate->dimensions['provider'] ?? '')) as $provider => $rows) {
            $failed = $this->estimate($rows, fn (RuntimeAggregate $aggregate): float => $aggregate->error_count);
            $payments = $this->estimate($rows, fn (RuntimeAggregate $aggregate): float => (float) ($aggregate->metrics['paymentFailedCount'] ?? 0));

            if ($threshold !== null && $failed >= $threshold) {
                $sent += $this->send($project, AlertEventEnum::BillingWebhookFailures, "billing-webhooks:{$provider}", "{$provider} webhooks: {$failed} failed in the last hour", ['provider' => $provider, 'failed' => $failed]);
            }

            if ($shouldAlertPayments && $payments > 0) {
                $sent += $this->send($project, AlertEventEnum::BillingPaymentFailed, "billing-payments:{$provider}", "{$provider}: {$payments} failed payments in the last hour", ['provider' => $provider, 'failedPayments' => $payments]);
            }
        }

        return $sent;
    }

    /**
     * @param  Collection<int, RuntimeAggregate>  $rows
     * @param  callable(RuntimeAggregate): float  $value
     */
    private function estimate(Collection $rows, callable $value): int
    {
        return (int) round($rows->sum(fn (RuntimeAggregate $aggregate): float => $value($aggregate) / max($aggregate->sample_rate, 0.0001)));
    }

    /**
     * Dispatches the alert unless the same subject alerted in the last hour.
     *
     * @param  array<string, mixed>  $data
     */
    private function send(Project $project, AlertEventEnum $event, string $subject, string $summary, array $data): int
    {
        $isCoolingDown = AlertLog::query()
            ->where('project_id', $project->id)
            ->where('event', $event->value)
            ->where('subject', $subject)
            ->where('created_at', '>', Date::now()->subHour())
            ->exists();

        if ($isCoolingDown) {
            return 0;
        }

        $this->alerts->dispatch($event, $project, $summary, $data, subject: $subject);

        return 1;
    }
}

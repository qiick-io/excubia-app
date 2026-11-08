<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Runtime aggregate types of excubia-core (`docs/runtime-schema.json`, vendored in
 * `tests/Fixtures/core/`), with the fields that identify one series of each type and the
 * type-specific metrics with their merge rule. A type the app does not list here is skipped on
 * ingest, never rejected, so a newer core can push to this app (#111).
 */
enum RuntimeTypeEnum: string
{
    case Request = 'request';
    case SlowQuery = 'slowQuery';
    case Job = 'job';
    case ScheduledTask = 'scheduledTask';
    case OutgoingHttp = 'outgoingHttp';
    case RequestMemory = 'requestMemory';
    case JobMemory = 'jobMemory';
    case QueueWait = 'queueWait';
    case Cache = 'cache';
    case Mail = 'mail';
    case Notification = 'notification';
    case LogLevel = 'logLevel';
    case Deprecation = 'deprecation';
    case DuplicateQuery = 'duplicateQuery';
    case Server = 'server';
    case Command = 'command';
    case Health = 'health';
    // Laravel AI SDK operations and agent tool calls (#129, core #198).
    case Ai = 'ai';
    case AiTool = 'aiTool';
    // Laravel Octane workers (#168, core #213).
    case Octane = 'octane';
    // Laravel Cashier webhooks (#178, core #217).
    case BillingWebhook = 'billingWebhook';

    /** Counts per latency bucket of the optional `histogram` (core `$defs.histogram`). */
    public const HISTOGRAM_LENGTH = 12;

    /**
     * Health statuses (#115), worst first: the merge keeps the first. Deliberately not the report
     * rollup order: a `skipped` sample has no reading, so it never hides a pass or a failure.
     */
    public const HEALTH_STATUSES = ['fail', 'warn', 'pass', 'skipped'];

    /** Fingerprints a health entry keeps after a merge (core `$defs.health`). */
    public const HEALTH_FINGERPRINTS = 20;

    /**
     * Usage counts of `ai` (#129) as the provider reports them: null when it does not, and a
     * null-safe sum on merge. `inputTokens` includes the cached input.
     */
    public const AI_USAGE = ['inputTokens', 'outputTokens', 'cacheReadInputTokens', 'cacheWriteInputTokens', 'reasoningTokens', 'imageInputTokens', 'imageOutputTokens', 'audioSeconds', 'searchUnits'];

    /**
     * Counters of `octane` (#168): they add up. `maxRequestsAtStop`, `maxMb` and `maxGrowthMb`
     * keep the larger value (core `$defs.octane`).
     */
    public const OCTANE_COUNTERS = ['workerStarts', 'workerStops', 'requests', 'tasks', 'ticks', 'totalRequestsAtStop'];

    /**
     * Counters of `billingWebhook` (#178) beyond count and errorCount: webhooks Cashier's handler
     * finished and the three payment signals; they add up (core `$defs.billingWebhook`).
     */
    public const BILLING_COUNTERS = ['handledCount', 'paymentFailedCount', 'paymentActionRequiredCount', 'subscriptionCanceledCount'];

    /** Server gauges: readings that keep the larger value on merge (null only when all are null). */
    private const SERVER_GAUGES = ['loadAverage1', 'loadAverage5', 'loadAverage15', 'cpuCores', 'cpuLimit', 'memoryUsedMb', 'memoryTotalMb', 'diskUsedGb', 'diskTotalGb'];

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * The dimensions every entry of the type carries.
     *
     * @return list<string>
     */
    public function dimensions(): array
    {
        return match ($this) {
            self::Request => ['route', 'method', 'statusClass'],
            self::SlowQuery => ['sql', 'connection'],
            self::Job => ['job', 'queue', 'connection', 'outcome'],
            self::ScheduledTask => ['task', 'outcome'],
            self::OutgoingHttp => ['host', 'statusClass'],
            self::RequestMemory => ['route', 'method'],
            self::JobMemory, self::QueueWait => ['job', 'queue'],
            self::Cache => ['store', 'keyPrefix'],
            self::Mail => ['mailable', 'mailer'],
            self::Notification => ['notification', 'channel'],
            self::LogLevel => ['level'],
            self::Deprecation => ['source', 'message', 'phpVersion'],
            self::DuplicateQuery => ['origin', 'name', 'sql', 'connection'],
            self::Server => ['server'],
            self::Command => ['command', 'exitCodeClass'],
            self::Health => ['server', 'checkId'],
            self::Ai => ['operation', 'provider', 'model'],
            self::AiTool => ['agent', 'tool'],
            self::Octane => ['server', 'octaneServer', 'workerType'],
            self::BillingWebhook => ['provider', 'eventType'],
        };
    }

    /**
     * Additive dimensions older cores do not send (`taskId` #146, log `channel` #174), and the
     * `agent` of an `ai` entry, sent only for `operation` = agent (#129). They join the series
     * identity only when present, so an entry without one is its own series.
     *
     * @return list<string>
     */
    public function optionalDimensions(): array
    {
        return match ($this) {
            self::ScheduledTask => ['taskId'],
            self::LogLevel => ['channel'],
            self::Ai => ['agent'],
            default => [],
        };
    }

    /**
     * Whether entries carry `totalMs` and `maxMs` and may carry a latency `histogram`.
     */
    public function isTimed(): bool
    {
        return in_array($this, [self::Request, self::SlowQuery, self::Job, self::ScheduledTask, self::OutgoingHttp, self::QueueWait, self::Command, self::Ai, self::AiTool, self::BillingWebhook], true);
    }

    /**
     * Type-specific metrics beyond count, errorCount, totalMs and maxMs, with how two entries of
     * the same series merge: `sum` adds up (null only when both are null, so the `ai` usage
     * counts stay null when no entry reported them), `max` keeps the larger value (null only when both are
     * null), `or` is true when either is true (missing counts as false); for health (#115)
     * `status` keeps the worst of HEALTH_STATUSES, `severity` the most serious and `union` the
     * sorted union of both lists, cut to HEALTH_FINGERPRINTS.
     *
     * @return array<string, 'sum'|'max'|'or'|'status'|'severity'|'union'>
     */
    public function metrics(): array
    {
        return match ($this) {
            self::RequestMemory, self::JobMemory => ['totalMb' => 'sum', 'maxMb' => 'max'],
            self::Cache => ['hits' => 'sum', 'misses' => 'sum', 'writes' => 'sum', 'deletes' => 'sum'],
            self::DuplicateQuery => ['totalRepeats' => 'sum', 'maxRepeats' => 'max'],
            self::Server => [...array_fill_keys(self::SERVER_GAUGES, 'max'), 'isContainer' => 'or'],
            self::Health => ['status' => 'status', 'severity' => 'severity', 'findingCount' => 'max', 'fingerprints' => 'union'],
            self::Ai => ['failoverCount' => 'sum', ...array_fill_keys(self::AI_USAGE, 'sum')],
            self::Octane => [...array_fill_keys(self::OCTANE_COUNTERS, 'sum'), 'maxRequestsAtStop' => 'max', 'maxMb' => 'max', 'maxGrowthMb' => 'max'],
            self::BillingWebhook => array_fill_keys(self::BILLING_COUNTERS, 'sum'),
            default => [],
        };
    }

    /**
     * Merges two entries' type-specific metrics by the rules of metrics(): `sum` adds up, `max`
     * keeps the larger number, both null only when neither side has a number; `or` is true when
     * either side is true; `status`, `severity` and `union` as described there (a missing side
     * is ignored).
     *
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     * @return array<string, mixed>
     */
    public function mergeMetrics(array $left, array $right): array
    {
        $merged = [];

        foreach ($this->metrics() as $metric => $merge) {
            $first = $left[$metric] ?? null;
            $second = $right[$metric] ?? null;
            $numbers = array_values(array_filter([$first, $second], fn (mixed $value): bool => is_int($value) || is_float($value)));

            $merged[$metric] = match (true) {
                $merge === 'or' => (bool) $first || (bool) $second,
                $merge === 'status' => self::worstOf(self::HEALTH_STATUSES, [$first, $second]),
                $merge === 'severity' => self::worstOf(SeverityEnum::values(), [$first, $second]),
                $merge === 'union' => self::union($first, $second),
                $numbers === [] => null,
                $merge === 'sum' => array_sum($numbers),
                default => max($numbers),
            };
        }

        return $merged;
    }

    /**
     * The value of `$ranking` ranked first (worst) among the candidates; null when none is known.
     *
     * @param  list<string>  $ranking  worst first
     * @param  list<mixed>  $candidates
     */
    public static function worstOf(array $ranking, array $candidates): ?string
    {
        foreach ($ranking as $value) {
            if (in_array($value, $candidates, true)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Sorted union of two fingerprint lists, at most HEALTH_FINGERPRINTS.
     *
     * @return list<string>
     */
    private static function union(mixed $left, mixed $right): array
    {
        $union = array_values(array_unique(array_filter([...(array) $left, ...(array) $right], 'is_string')));
        sort($union);

        return array_slice($union, 0, self::HEALTH_FINGERPRINTS);
    }
}

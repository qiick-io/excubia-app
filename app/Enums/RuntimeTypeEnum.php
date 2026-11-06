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

    /** Counts per latency bucket of the optional `histogram` (core `$defs.histogram`). */
    public const HISTOGRAM_LENGTH = 12;

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
        };
    }

    /**
     * Additive dimensions older cores do not send (`taskId` #146, log `channel` #174). They join
     * the series identity only when present, so an entry without one is its own series.
     *
     * @return list<string>
     */
    public function optionalDimensions(): array
    {
        return match ($this) {
            self::ScheduledTask => ['taskId'],
            self::LogLevel => ['channel'],
            default => [],
        };
    }

    /**
     * Whether entries carry `totalMs` and `maxMs` and may carry a latency `histogram`.
     */
    public function isTimed(): bool
    {
        return in_array($this, [self::Request, self::SlowQuery, self::Job, self::ScheduledTask, self::OutgoingHttp, self::QueueWait, self::Command], true);
    }

    /**
     * Type-specific metrics beyond count, errorCount, totalMs and maxMs, with how two entries of
     * the same series merge: `sum` adds up, `max` keeps the larger value (null only when both are
     * null), `or` is true when either is true (missing counts as false).
     *
     * @return array<string, 'sum'|'max'|'or'>
     */
    public function metrics(): array
    {
        return match ($this) {
            self::RequestMemory, self::JobMemory => ['totalMb' => 'sum', 'maxMb' => 'max'],
            self::Cache => ['hits' => 'sum', 'misses' => 'sum', 'writes' => 'sum', 'deletes' => 'sum'],
            self::DuplicateQuery => ['totalRepeats' => 'sum', 'maxRepeats' => 'max'],
            self::Server => [...array_fill_keys(self::SERVER_GAUGES, 'max'), 'isContainer' => 'or'],
            default => [],
        };
    }
}

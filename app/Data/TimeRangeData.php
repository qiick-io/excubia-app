<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\TimeRangeEnum;
use Carbon\CarbonImmutable;

/**
 * The window of the runtime, AI, exceptions and schedule views (#113, #247): a preset ending now
 * (`?range=24h`) or a custom span (`?from=…&to=…`, ISO 8601). Charts cut it into buckets aligned
 * on the Unix epoch; `from` is the start of the first bucket, `until` the last moment included.
 *
 * A custom span is valid when it ends after it starts, lasts at most 14 days (the longest preset)
 * and starts inside the runtime retention; its end is clamped to now. Its bucket follows the
 * length like the presets (1 minute up to 2 hours … 1 day beyond 8 days), and is at least an hour
 * once the span reaches back past the per-minute window, where only hourly rows are left
 * (DownsampleRuntimeAction).
 */
final readonly class TimeRangeData
{
    /** The longest custom span, as the longest preset. */
    public const MAXIMUM_SECONDS = 14 * 86400;

    /**
     * Bucket seconds by the longest span (seconds) they serve, shortest first.
     *
     * @var array<int, 60|600|3600|21600|86400>
     */
    private const BUCKETS_BY_SPAN = [
        2 * 3600 => 60,
        12 * 3600 => 600,
        2 * 86400 => 3600,
        8 * 86400 => 6 * 3600,
        self::MAXIMUM_SECONDS => 86400,
    ];

    /**
     * @param  60|600|3600|21600|86400  $bucketSeconds
     */
    public function __construct(
        public ?TimeRangeEnum $preset,
        public CarbonImmutable $from,
        public CarbonImmutable $until,
        public int $bucketSeconds,
    ) {}

    public static function fromPreset(TimeRangeEnum $preset, CarbonImmutable $now): self
    {
        return new self($preset, $preset->start($now), $now, $preset->bucketSeconds());
    }

    /**
     * The range of a page's query: a valid custom span (`from` and `to`), else the preset of
     * `range`, else the default preset.
     */
    public static function fromQuery(?string $range, ?string $from, ?string $to, CarbonImmutable $now, int $retentionDays, int $minuteHours): self
    {
        $custom = $from !== null && $to !== null ? self::custom($from, $to, $now, $retentionDays, $minuteHours) : null;

        return $custom ?? self::fromPreset(TimeRangeEnum::tryFrom((string) $range) ?? TimeRangeEnum::DEFAULT, $now);
    }

    /**
     * A custom span, or null when it is not valid (the caller falls back to the default preset).
     *
     * @param  int  $retentionDays  how far back runtime data is kept
     * @param  int  $minuteHours  how far back runtime rows are still per minute
     */
    public static function custom(string $from, string $to, CarbonImmutable $now, int $retentionDays, int $minuteHours): ?self
    {
        $start = self::parse($from);
        $end = self::parse($to);

        if ($start === null || $end === null) {
            return null;
        }

        $end = $end->min($now);
        $seconds = $end->getTimestamp() - $start->getTimestamp();

        if ($seconds <= 0 || $seconds > self::MAXIMUM_SECONDS || $start->lessThan($now->subDays($retentionDays))) {
            return null;
        }

        $bucketSeconds = 86400;

        foreach (self::BUCKETS_BY_SPAN as $span => $bucket) {
            if ($seconds <= $span) {
                $bucketSeconds = $bucket;

                break;
            }
        }

        if ($start->lessThan($now->subHours($minuteHours))) {
            $bucketSeconds = max($bucketSeconds, 3600);
        }

        $startTimestamp = $start->getTimestamp();

        // `to` itself is excluded: the window ends one second before it.
        return new self(null, CarbonImmutable::createFromTimestampUTC($startTimestamp - $startTimestamp % $bucketSeconds), $end->subSecond(), $bucketSeconds);
    }

    /** The length the range stands for in seconds: the preset's, or the custom span's. */
    public function seconds(): int
    {
        return $this->preset?->seconds() ?? $this->until->getTimestamp() + 1 - $this->from->getTimestamp();
    }

    /** The `?range=` value: the preset, or `custom`. */
    public function value(): string
    {
        return $this->preset->value ?? 'custom';
    }

    /** The start of the bucket a moment falls in (UTC). */
    public function bucketOf(CarbonImmutable $at): CarbonImmutable
    {
        $timestamp = $at->getTimestamp();

        return CarbonImmutable::createFromTimestampUTC($timestamp - $timestamp % $this->bucketSeconds);
    }

    /**
     * The window for the page: what the picker and the chart texts read.
     *
     * @return array{value: string, from: string, to: string, bucketSeconds: int}
     */
    public function toArray(): array
    {
        return [
            'value' => $this->value(),
            'from' => $this->from->toIso8601ZuluString(),
            'to' => $this->preset === null ? $this->until->addSecond()->toIso8601ZuluString() : $this->until->toIso8601ZuluString(),
            'bucketSeconds' => $this->bucketSeconds,
        ];
    }

    private static function parse(string $value): ?CarbonImmutable
    {
        try {
            return $value === '' ? null : CarbonImmutable::parse($value)->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}

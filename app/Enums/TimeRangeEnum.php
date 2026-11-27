<?php

declare(strict_types=1);

namespace App\Enums;

use Carbon\CarbonImmutable;

/**
 * The time ranges of the runtime, exceptions and schedule views (#113), kept in the URL as
 * `?range=`. Each range has its chart bucket, so a chart keeps between 14 and 60 bars: minutes
 * for 1 h, 10 minutes for 6 h, hours for 24 h, 6 hours for 7 d and days for 14 d. Buckets are
 * aligned on the Unix epoch, so days start at midnight UTC.
 */
enum TimeRangeEnum: string
{
    case OneHour = '1h';
    case SixHours = '6h';
    case Day = '24h';
    case Week = '7d';
    case TwoWeeks = '14d';

    /** The range when the URL names none (or an unknown one). */
    public const DEFAULT = self::Day;

    public function seconds(): int
    {
        return match ($this) {
            self::OneHour => 3600,
            self::SixHours => 6 * 3600,
            self::Day => 24 * 3600,
            self::Week => 7 * 86400,
            self::TwoWeeks => 14 * 86400,
        };
    }

    /**
     * Seconds per chart bucket.
     *
     * @return 60|600|3600|21600|86400
     */
    public function bucketSeconds(): int
    {
        return match ($this) {
            self::OneHour => 60,
            self::SixHours => 600,
            self::Day => 3600,
            self::Week => 6 * 3600,
            self::TwoWeeks => 86400,
        };
    }

    /** The start of the bucket a moment falls in (UTC). */
    public function bucketOf(CarbonImmutable $at): CarbonImmutable
    {
        $timestamp = $at->getTimestamp();

        return CarbonImmutable::createFromTimestampUTC($timestamp - $timestamp % $this->bucketSeconds());
    }

    /**
     * The start of the window: the start of the bucket the range began in, so the first bar is
     * whole and the last one is the bucket of now.
     */
    public function start(CarbonImmutable $now): CarbonImmutable
    {
        return $this->bucketOf($now->subSeconds($this->seconds()));
    }
}

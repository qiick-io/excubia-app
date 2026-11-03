<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Finding severities of the excubia-core report (`docs/report-schema.json`), worst first.
 */
enum SeverityEnum: string
{
    case Critical = 'critical';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
    case Info = 'info';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Lower is worse: `critical` is 0, `info` is 4.
     */
    public function rank(): int
    {
        return (int) array_search($this, self::cases(), true);
    }
}

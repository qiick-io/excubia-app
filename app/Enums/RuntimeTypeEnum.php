<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Runtime aggregate types of excubia-core (`docs/runtime-schema.json`), with the fields that
 * identify one series of each type.
 */
enum RuntimeTypeEnum: string
{
    case Request = 'request';
    case SlowQuery = 'slowQuery';
    case Job = 'job';
    case ScheduledTask = 'scheduledTask';
    case OutgoingHttp = 'outgoingHttp';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
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
        };
    }
}

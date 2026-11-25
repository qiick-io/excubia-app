<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use Illuminate\Support\Facades\Date;
use Qiick\ApiKeys\Contracts\Clock;

/**
 * The application clock for qiick/laravel-api-keys, so expiry, rotation grace and notice leases
 * use the same `now()` as the rest of Excubia (and `Date::setTestNow()` in tests) instead of the
 * package's raw system clock.
 */
class ApiKeyClockService implements Clock
{
    public function now(): DateTimeImmutable
    {
        return Date::now()->toDateTimeImmutable();
    }
}

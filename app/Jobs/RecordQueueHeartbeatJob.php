<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;

/**
 * Queued by the scheduler every minute (#117): a worker running it stores the time, so the
 * System page can tell the queue worker is alive. With the `sync` connection it runs inline.
 */
class RecordQueueHeartbeatJob implements ShouldQueue
{
    use Queueable;

    public const CACHE_KEY = 'excubia:queue-heartbeat';

    public function handle(): void
    {
        Cache::forever(self::CACHE_KEY, Date::now()->getTimestamp());
    }
}

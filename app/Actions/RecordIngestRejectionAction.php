<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\IngestRejection;
use Illuminate\Support\Facades\Date;

/**
 * Counts one rejected push (#117) in its hour, atomically, on SQLite, MySQL and PostgreSQL. Only
 * the endpoint, the status and a reason are stored: never the body, the token or the project.
 */
class RecordIngestRejectionAction
{
    public function execute(string $endpoint, int $status, string $reason): void
    {
        IngestRejection::query()->toBase()->upsert(
            [[
                'endpoint' => $endpoint,
                'status' => $status,
                'reason' => mb_substr($reason, 0, 100),
                'bucket' => Date::now()->utc()->startOfHour(),
                'count' => 1,
            ]],
            ['endpoint', 'status', 'reason', 'bucket'],
            ['count' => (new IngestRejection)->getConnection()->raw('ingest_rejections.count + 1')],
        );
    }
}

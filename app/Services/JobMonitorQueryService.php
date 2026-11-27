<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Read model for the queue monitor: a sample of pending jobs and the latest failed jobs,
 * read from the `database` queue tables. Copied from qiick-io/externa-core.
 */
class JobMonitorQueryService
{
    private const int PENDING_SAMPLE_SIZE = 25;

    private const int FAILED_LIMIT = 50;

    private const int EXCEPTION_PREVIEW_LENGTH = 500;

    /**
     * @return array{
     *     pending_count: int,
     *     pending: list<array{id: int, queue: string, display_name: string, attempts: int, available_at: int, created_at: int}>,
     *     failed: list<array{id: int, uuid: string, queue: string, display_name: string, exception: string, failed_at: string}>
     * }
     */
    public function snapshot(): array
    {
        $pending = array_values(DB::table('jobs')
            ->orderByDesc('id')
            ->limit(self::PENDING_SAMPLE_SIZE)
            ->get(['id', 'queue', 'payload', 'attempts', 'available_at', 'created_at'])
            ->map(fn (object $job): array => [
                'id' => (int) $job->id,
                'queue' => (string) $job->queue,
                'display_name' => $this->displayName((string) $job->payload),
                'attempts' => (int) $job->attempts,
                'available_at' => (int) $job->available_at,
                'created_at' => (int) $job->created_at,
            ])
            ->all());

        $failed = array_values(DB::table('failed_jobs')
            ->orderByDesc('id')
            ->limit(self::FAILED_LIMIT)
            ->get(['id', 'uuid', 'queue', 'payload', 'exception', 'failed_at'])
            ->map(fn (object $job): array => [
                'id' => (int) $job->id,
                'uuid' => (string) $job->uuid,
                'queue' => (string) $job->queue,
                'display_name' => $this->displayName((string) $job->payload),
                'exception' => $this->exceptionPreview((string) $job->exception),
                'failed_at' => (string) $job->failed_at,
            ])
            ->all());

        return [
            'pending_count' => DB::table('jobs')->count(),
            'pending' => $pending,
            'failed' => $failed,
        ];
    }

    /**
     * The job class Laravel stores as `displayName` in the payload, or "Job".
     */
    private function displayName(string $payload): string
    {
        $decoded = json_decode($payload, true);
        $displayName = is_array($decoded) ? ($decoded['displayName'] ?? null) : null;

        return is_string($displayName) ? $displayName : 'Job';
    }

    private function exceptionPreview(string $exception): string
    {
        if (mb_strlen($exception) <= self::EXCEPTION_PREVIEW_LENGTH) {
            return $exception;
        }

        return mb_substr($exception, 0, self::EXCEPTION_PREVIEW_LENGTH).'…';
    }
}

<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\RuntimeHourlyRollup;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Connection;

/**
 * Adds runtime minutes to their hourly rollups (#137): per project, type, hour and sample rate,
 * the counts, errors and total durations add up, as they do on the minute aggregates. The
 * minutes are summed in PHP first, so one upsert holds each hour once (PostgreSQL refuses a row
 * twice in one statement), and the database adds them to the stored hour atomically, on SQLite,
 * MySQL and PostgreSQL alike. Used at ingest and by the one-off backfill.
 *
 * @phpstan-type Minute array{project_id: int, type: string, bucket: DateTimeInterface|string, sample_rate: float, count: int, error_count: int, total_ms: float|null}
 */
class StoreRuntimeRollupsAction
{
    /** Hours per statement, so one stays below the bound-parameter limits. */
    private const CHUNK = 500;

    /** The summed columns. */
    private const SUMS = ['count', 'error_count', 'total_ms'];

    /**
     * @param  iterable<Minute>  $minutes
     */
    public function execute(iterable $minutes): void
    {
        $hours = [];

        foreach ($minutes as $minute) {
            $bucket = CarbonImmutable::parse($minute['bucket'])->utc()->startOfHour();
            $sampleRate = round((float) $minute['sample_rate'], 4);
            $key = implode('|', [$minute['project_id'], $minute['type'], $bucket->getTimestamp(), $sampleRate]);
            $hour = $hours[$key] ?? ['project_id' => $minute['project_id'], 'type' => $minute['type'], 'bucket' => $bucket, 'sample_rate' => $sampleRate, 'count' => 0, 'error_count' => 0, 'total_ms' => null];
            $hour['count'] += $minute['count'];
            $hour['error_count'] += $minute['error_count'];
            $hour['total_ms'] = $minute['total_ms'] === null ? $hour['total_ms'] : (float) $hour['total_ms'] + $minute['total_ms'];
            $hours[$key] = $hour;
        }

        if ($hours === []) {
            return;
        }

        $connection = (new RuntimeHourlyRollup)->getConnection();
        $update = array_map(fn (string $column): Expression => $this->sum($connection, $column), array_combine(self::SUMS, self::SUMS));

        foreach (array_chunk(array_values($hours), self::CHUNK) as $chunk) {
            RuntimeHourlyRollup::query()->toBase()->upsert($chunk, ['project_id', 'type', 'bucket', 'sample_rate'], $update);
        }
    }

    /**
     * The stored value plus the incoming one, as the upsert's update expression of a column. A
     * null side (types that are not timed) is read as zero only when the other side has a value.
     *
     * @param  'count'|'error_count'|'total_ms'  $column
     */
    private function sum(Connection $connection, string $column): Expression
    {
        $stored = "runtime_hourly_rollups.{$column}";
        $incoming = match ($connection->getDriverName()) {
            'mysql', 'mariadb' => $connection->getConfig('use_upsert_alias') ? "laravel_upsert_alias.{$column}" : "values({$column})",
            default => "excluded.{$column}",
        };

        return $connection->raw($column === 'total_ms'
            ? "case when {$stored} is null and {$incoming} is null then null else coalesce({$stored}, 0) + coalesce({$incoming}, 0) end"
            : "{$stored} + {$incoming}");
    }
}

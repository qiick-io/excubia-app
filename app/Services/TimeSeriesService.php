<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TimeRangeEnum;
use App\Models\HealthReport;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;

/**
 * Charts over a time range (#113): sums per chart bucket computed in SQL (SQLite, MySQL and
 * PostgreSQL), so PHP only fills the empty buckets, and the deploys of the range for the chart
 * markers (#106). A point's `at` is the ISO 8601 UTC start of its bucket; a deploy marker's
 * `bucket` is the `at` of the point it falls in.
 */
class TimeSeriesService
{
    /**
     * Sums per bucket of the range, one point per bucket from the start of the range to now.
     * With `$groupColumn` the sums are per value of that column too, keyed by it: the values
     * that have rows and every one of `$groups`.
     *
     * @param  Builder  $query  already limited to the rows of the range
     * @param  literal-string  $column  the timestamp column to bucket
     * @param  literal-string|null  $groupColumn
     * @param  list<int|string>  $groups  values of `$groupColumn` to return even without rows
     * @param  array<literal-string, literal-string>  $sums  point key => SQL expression to sum, e.g. `count / sample_rate`
     * @return ($groupColumn is null ? list<array<string, int|string>> : array<int|string, list<array<string, int|string>>>)
     */
    public function points(Builder $query, string $column, array $sums, TimeRangeEnum $range, CarbonImmutable $now, ?string $groupColumn = null, array $groups = []): array
    {
        $step = $range->bucketSeconds();
        $selects = [$this->bucketSql($query, $column, $range).' as bucket_at'];

        foreach ($sums as $key => $expression) {
            $selects[] = "sum({$expression}) as sum_{$key}";
        }

        $rows = $query
            ->select(array_map(fn (string $select): Expression => new Expression($select), $selects))
            ->when($groupColumn !== null, fn (Builder $grouped) => $grouped->addSelect((string) $groupColumn)->groupBy((string) $groupColumn))
            ->groupBy('bucket_at')
            ->get();

        $byGroup = array_fill_keys($groups, []);

        foreach ($rows as $row) {
            $values = array_change_key_case((array) $row);
            $group = $groupColumn === null ? '' : $values[$groupColumn];

            foreach (array_keys($sums) as $key) {
                $byGroup[$group][(int) $values['bucket_at']][$key] = (int) round((float) $values['sum_'.strtolower($key)]);
            }
        }

        $fill = function (array $buckets) use ($sums, $range, $now, $step): array {
            $points = [];

            for ($at = $range->start($now)->getTimestamp(); $at <= $now->getTimestamp(); $at += $step) {
                $points[] = ['at' => CarbonImmutable::createFromTimestampUTC($at)->toIso8601ZuluString(), ...array_map(fn (string $expression): int => 0, $sums), ...($buckets[$at] ?? [])];
            }

            return $points;
        };

        if ($groupColumn === null) {
            return $fill($byGroup[''] ?? []);
        }

        return array_map($fill, $byGroup);
    }

    /**
     * The deploys (#106) of the range, oldest first, for the chart markers.
     *
     * @return list<array{release: string|null, deployedAt: string, bucket: string}>
     */
    public function deploys(Project $project, TimeRangeEnum $range, CarbonImmutable $now): array
    {
        $from = $range->start($now);

        return array_values($project->healthReports()
            ->deploys()
            ->where('deployed_at', '>=', $from)
            ->orderBy('deployed_at')
            ->get(['release', 'deployed_at'])
            ->map(function (HealthReport $report) use ($from, $range): array {
                // Never null here (deploys() scope); the fallback only satisfies the type.
                $deployedAt = CarbonImmutable::instance($report->deployed_at ?? $from)->utc();

                return [
                    'release' => $report->release,
                    'deployedAt' => $deployedAt->toIso8601ZuluString(),
                    'bucket' => $range->bucketOf($deployedAt)->toIso8601ZuluString(),
                ];
            })
            ->all());
    }

    /**
     * The Unix time of the start of a timestamp column's bucket, as SQL. Timestamps are stored in
     * UTC without a zone: MySQL counts seconds from the epoch with timestampdiff (unix_timestamp
     * would apply the session zone), PostgreSQL's epoch of a zoneless timestamp is UTC.
     *
     * @param  literal-string  $column
     * @return literal-string
     */
    private function bucketSql(Builder $query, string $column, TimeRangeEnum $range): string
    {
        $connection = $query->getConnection();
        $epoch = match ($connection instanceof Connection ? $connection->getDriverName() : 'sqlite') {
            'mysql', 'mariadb' => "timestampdiff(second, '1970-01-01 00:00:00', {$column})",
            'pgsql' => "cast(extract(epoch from {$column}) as bigint)",
            default => "cast(strftime('%s', {$column}) as integer)",
        };

        $step = $range->bucketSeconds();

        return "(({$epoch}) - ({$epoch}) % {$step})";
    }
}

<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

/**
 * The schema review before the migration freeze (#193), kept as a guard for every new migration.
 * Runs on SQLite here and on MySQL and PostgreSQL in CI.
 */

/**
 * @return list<string>
 */
function schemaTables(): array
{
    return array_values(array_map(fn (array $table): string => $table['name'], Schema::getTables()));
}

it('deletes everything of a project with the project', function () {
    foreach (schemaTables() as $table) {
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if ($foreignKey['foreign_table'] === 'projects') {
                expect(strtolower((string) $foreignKey['on_delete']))->toBe('cascade', "{$table}.{$foreignKey['columns'][0]}");
            }
        }
    }
});

it('indexes every foreign key column, which PostgreSQL does not do by itself', function () {
    $unindexed = [];

    foreach (schemaTables() as $table) {
        $leadingColumns = array_map(fn (array $index): string => $index['columns'][0], Schema::getIndexes($table));

        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if (! in_array($foreignKey['columns'][0], $leadingColumns, true)) {
                $unindexed[] = "{$table}.{$foreignKey['columns'][0]}";
            }
        }
    }

    expect($unindexed)->toBe([]);
});

it('indexes the columns the daily retention prune deletes by', function (string $table, string $column) {
    $leadingColumns = array_map(fn (array $index): string => $index['columns'][0], Schema::getIndexes($table));

    expect($leadingColumns)->toContain($column);
})->with([
    ['runtime_aggregates', 'bucket'],
    ['runtime_hourly_rollups', 'bucket'],
    ['scheduled_task_runs', 'bucket'],
    ['exception_occurrences', 'bucket'],
    ['pushed_batches', 'received_at'],
    ['alert_logs', 'created_at'],
    ['ai_usage_days', 'day'],
    ['ingest_rejections', 'bucket'],
]);

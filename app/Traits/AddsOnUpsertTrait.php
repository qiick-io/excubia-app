<?php

declare(strict_types=1);

namespace App\Traits;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Connection;

/**
 * The update expression of an additive upsert: the stored value plus the incoming one, done by
 * the database so concurrent pushes add up atomically on SQLite, MySQL and PostgreSQL alike.
 * Shared by the hourly runtime rollups (#137) and the daily AI usage (#129).
 */
trait AddsOnUpsertTrait
{
    /**
     * @param  literal-string  $table
     * @param  literal-string  $column
     * @param  bool  $isNullable  a null side is read as zero only when the other side has a value
     */
    private function addOnUpsert(Connection $connection, string $table, string $column, bool $isNullable = false): Expression
    {
        $stored = "{$table}.{$column}";
        $incoming = match ($connection->getDriverName()) {
            'mysql', 'mariadb' => $connection->getConfig('use_upsert_alias') ? "laravel_upsert_alias.{$column}" : "values({$column})",
            default => "excluded.{$column}",
        };

        return $connection->raw($isNullable
            ? "case when {$stored} is null and {$incoming} is null then null else coalesce({$stored}, 0) + coalesce({$incoming}, 0) end"
            : "{$stored} + {$incoming}");
    }
}

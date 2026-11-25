<?php

declare(strict_types=1);

namespace Qiick\Standards\Conformance\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Thin helpers over Laravel Schema / Doctrine DBAL column metadata.
 *
 * Engine-dependent assertions (UUID vs char, TIMESTAMP vs DATETIME, case-sensitive
 * unique) must run on PostgreSQL and MySQL; SQLite is not sufficient for those.
 */
final class SchemaInspector
{
    public function __construct(
        private readonly ?string $connectionName = null,
    ) {}

    private function schema(): \Illuminate\Database\Schema\Builder
    {
        return $this->connectionName === null
            ? Schema::connection(config('database.default'))
            : Schema::connection($this->connectionName);
    }

    public function driver(): string
    {
        return $this->schema()->getConnection()->getDriverName();
    }

    public function isEngineDependentDriver(): bool
    {
        return in_array($this->driver(), ['pgsql', 'mysql', 'mariadb'], true);
    }

    public function hasTable(string $table): bool
    {
        return $this->schema()->hasTable($table);
    }

    public function hasColumn(string $table, string $column): bool
    {
        return $this->schema()->hasColumn($table, $column);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function columns(string $table): array
    {
        return $this->schema()->getColumns($table);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function column(string $table, string $column): ?array
    {
        foreach ($this->columns($table) as $definition) {
            if (($definition['name'] ?? null) === $column) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function indexes(string $table): array
    {
        return $this->schema()->getIndexes($table);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function foreignKeys(string $table): array
    {
        return $this->schema()->getForeignKeys($table);
    }

    public function hasUniqueIndexCovering(string $table, string ...$columns): bool
    {
        $wanted = array_values($columns);

        foreach ($this->indexes($table) as $index) {
            if (! ($index['unique'] ?? false)) {
                continue;
            }

            $indexed = array_values($index['columns'] ?? []);

            if ($indexed === $wanted) {
                return true;
            }
        }

        return false;
    }

    public function columnTypeContains(string $table, string $column, string $needle): bool
    {
        $definition = $this->column($table, $column);

        if ($definition === null) {
            return false;
        }

        $type = strtolower((string) ($definition['type_name'] ?? $definition['type'] ?? ''));

        return str_contains($type, strtolower($needle));
    }

    public function isNullable(string $table, string $column): bool
    {
        $definition = $this->column($table, $column);

        return (bool) ($definition['nullable'] ?? false);
    }

    public function defaultEquals(string $table, string $column, string $expected): bool
    {
        $definition = $this->column($table, $column);
        $default = $definition['default'] ?? null;

        if ($default === null) {
            return false;
        }

        // Engines wrap defaults differently: 'UTC', "UTC", 'UTC'::character varying.
        $normalized = strtolower((string) $default);
        $normalized = preg_replace("/::[a-z _]+$/", '', $normalized) ?? $normalized;
        $normalized = trim($normalized, " '\"");

        return strcasecmp($normalized, $expected) === 0;
    }

    /**
     * True when the column is a calendar dateTime (not MySQL TIMESTAMP).
     */
    public function isDateTimeNotTimestamp(string $table, string $column): bool
    {
        $definition = $this->column($table, $column);

        if ($definition === null) {
            return false;
        }

        $typeName = strtolower((string) ($definition['type_name'] ?? ''));
        $type = strtolower((string) ($definition['type'] ?? ''));

        if (str_contains($typeName, 'timestamp') || str_contains($type, 'timestamp')) {
            // PostgreSQL timestamps are fine (no 2038); MySQL TIMESTAMP is not.
            if ($this->driver() === 'pgsql') {
                return true;
            }

            return false;
        }

        return str_contains($typeName, 'datetime')
            || str_contains($type, 'datetime')
            || str_contains($typeName, 'timestamp without');
    }

    /**
     * Foreign-key on-delete action for a column, lower-cased (cascade|set null|restrict|no action|…).
     */
    public function foreignKeyOnDelete(string $table, string $column): ?string
    {
        foreach ($this->foreignKeys($table) as $foreignKey) {
            $columns = $foreignKey['columns'] ?? [];

            if ($columns === [$column] || in_array($column, $columns, true)) {
                return strtolower((string) ($foreignKey['on_delete'] ?? ''));
            }
        }

        return null;
    }

    public function assertTableExists(string $table): void
    {
        if (! $this->hasTable($table)) {
            throw new \RuntimeException("Expected table \"{$table}\" to exist.");
        }
    }

    public function assertColumnExists(string $table, string $column): void
    {
        if (! $this->hasColumn($table, $column)) {
            throw new \RuntimeException("Expected {$table}.{$column} to exist.");
        }
    }

    public function assertColumnMissing(string $table, string $column): void
    {
        if ($this->hasColumn($table, $column)) {
            throw new \RuntimeException("Expected {$table}.{$column} to be absent.");
        }
    }

    /**
     * Run a raw statement on the connection (insert probes, etc.).
     */
    public function statement(string $sql, array $bindings = []): void
    {
        $name = $this->connectionName ?? (string) config('database.default');
        DB::connection($name)->statement($sql, $bindings);
    }
}

<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * SQLite settings for concurrent pushes (#195). With the framework defaults (rollback journal,
 * deferred transactions) two pushes at once failed with "database is locked": a transaction that
 * read first could not take the write lock later and SQLite gives up at once instead of waiting.
 * The default connection now uses WAL and takes the write lock when the transaction begins, so
 * the second push waits up to the busy timeout. Checked on a database file, as installs run it
 * (the test suite itself uses `:memory:`).
 */
it('opens SQLite files in WAL mode with a busy timeout and transactions that lock on begin', function () {
    $file = tempnam(sys_get_temp_dir(), 'excubia-sqlite-');
    config(['database.connections.sqlite_file' => [...config('database.connections.sqlite'), 'database' => $file]]);
    $connection = DB::connection('sqlite_file');

    expect($connection->scalar('pragma journal_mode'))->toBe('wal')
        ->and($connection->scalar('pragma busy_timeout'))->toBe(10000);

    // A second client without a busy timeout cannot start writing while the first transaction
    // is open, although that transaction has not written yet.
    $other = new PDO("sqlite:{$file}", options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 0]);

    $connection->transaction(function () use ($other): void {
        expect(fn () => $other->exec('BEGIN IMMEDIATE'))->toThrow(PDOException::class, 'database is locked');
    });

    expect($other->exec('BEGIN IMMEDIATE'))->toBe(0);

    $other->exec('ROLLBACK');
    DB::purge('sqlite_file');
    array_map(unlink(...), glob("{$file}*") ?: []);
});

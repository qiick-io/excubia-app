<?php

declare(strict_types=1);

/**
 * Lives under Unit so RefreshDatabase does not wrap these runs: the installer
 * switches to a file SQLite DB and must not share the Feature suite PDO.
 */

use App\Console\Commands\InstallCommand;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\PendingCommand;

it('registers excubia:install', function () {
    expect(Artisan::all())->toHaveKey('excubia:install');
});

it('refuses production-looking env without --force', function () {
    $path = base_path('.env');
    $backup = File::exists($path) ? File::get($path) : null;
    File::put($path, "APP_ENV=production\nAPP_KEY=\n");

    try {
        $this->artisan(InstallCommand::class)
            ->expectsOutputToContain('Refusing: APP_ENV looks like production')
            ->assertFailed();
    } finally {
        if ($backup === null) {
            File::delete($path);
        } else {
            File::put($path, $backup);
        }
    }
});

it('preserves a valid in-memory APP_KEY into the rewritten .env', function () {
    [$envPath, $envBackup, $sqlite, $sqliteBackup, $originalDefault] = beginInstallFixtures();

    $existingKey = 'base64:'.base64_encode(Encrypter::generateKey(config('app.cipher')));
    config(['app.key' => $existingKey, 'cache.default' => 'redis', 'queue.default' => 'redis']);
    $_ENV['APP_KEY'] = $existingKey;
    $_SERVER['APP_KEY'] = $existingKey;
    putenv('APP_KEY='.$existingKey);

    File::put($envPath, "APP_ENV=local\nAPP_KEY=\nCACHE_STORE=redis\nQUEUE_CONNECTION=redis\n");

    try {
        expectInstallQuestions()->assertSuccessful();

        $written = File::get($envPath);
        expect($written)->toContain('APP_KEY='.$existingKey)
            ->and($written)->toMatch('/^CACHE_STORE=database$/m')
            ->and(config('app.key'))->toBe($existingKey)
            ->and(config('database.default'))->toBe('sqlite')
            ->and(Schema::connection('sqlite')->hasTable('migrations'))->toBeTrue();
    } finally {
        restoreInstallFixtures($envPath, $envBackup, $sqlite, $sqliteBackup, $originalDefault);
    }
});

it('generates APP_KEY when none is present in .env or config', function () {
    [$envPath, $envBackup, $sqlite, $sqliteBackup, $originalDefault] = beginInstallFixtures();

    config(['app.key' => '']);
    putenv('APP_KEY');
    unset($_ENV['APP_KEY'], $_SERVER['APP_KEY']);

    File::delete($envPath);

    try {
        expectInstallQuestions()->assertSuccessful();

        expect(File::get($envPath))->toMatch('/^APP_KEY=base64:.+/m')
            ->and(config('app.key'))->toStartWith('base64:');
    } finally {
        restoreInstallFixtures($envPath, $envBackup, $sqlite, $sqliteBackup, $originalDefault);
    }
});

it('installs without questions under --no-interaction, seeding permissions and the system project', function () {
    [$envPath, $envBackup, $sqlite, $sqliteBackup, $originalDefault] = beginInstallFixtures();

    try {
        // A real run, as `php artisan excubia:install -n …` (the test harness would mock the prompts).
        $exitCode = Artisan::call('excubia:install', [
            '--force' => true,
            '--no-npm' => true,
            '--no-interaction' => true,
            '--name' => 'Acme Excubia',
            '--url' => 'https://excubia.example.com',
        ]);

        expect($exitCode)->toBe(0)
            ->and(Artisan::output())->toContain('/setup to create the first admin');

        $env = File::get($envPath);

        expect($env)->toContain('APP_NAME="Acme Excubia"')
            ->and($env)->toContain('APP_URL=https://excubia.example.com')
            ->and($env)->toContain('DB_CONNECTION=sqlite')
            ->and(Schema::connection('sqlite')->hasTable('permissions'))->toBeTrue()
            ->and(DB::connection('sqlite')->table('permissions')->count())->toBeGreaterThan(0)
            ->and(DB::connection('sqlite')->table('projects')->where('is_system', true)->count())->toBe(1)
            ->and(DB::connection('sqlite')->table('users')->count())->toBe(0);
    } finally {
        restoreInstallFixtures($envPath, $envBackup, $sqlite, $sqliteBackup, $originalDefault);
    }
});

it('rejects an unknown database driver', function () {
    $this->artisan(InstallCommand::class, ['--force' => true, '--no-interaction' => true, '--db' => 'oracle'])
        ->expectsOutputToContain('Unknown --db=oracle')
        ->assertFailed();
});

it('is safely re-runnable with --force', function () {
    [$envPath, $envBackup, $sqlite, $sqliteBackup, $originalDefault] = beginInstallFixtures();

    try {
        expectInstallQuestions()->assertSuccessful();

        $keyAfterFirst = config('app.key');

        expectInstallQuestions()
            ->expectsOutputToContain('Install complete.')
            ->assertSuccessful();

        expect(config('app.key'))->toBe($keyAfterFirst)
            ->and(File::get($envPath))->toContain('APP_KEY='.$keyAfterFirst)
            ->and(Schema::connection('sqlite')->hasTable('permissions'))->toBeTrue();
    } finally {
        restoreInstallFixtures($envPath, $envBackup, $sqlite, $sqliteBackup, $originalDefault);
    }
});

/**
 * @return array{0: string, 1: string|null, 2: string, 3: string|null, 4: string}
 */
function beginInstallFixtures(): array
{
    $envPath = base_path('.env');
    $sqlite = database_path('database.sqlite');

    // Fresh empty SQLite file for each install test (installer always uses this path).
    File::put($sqlite, '');
    config([
        'database.connections.sqlite.database' => $sqlite,
        'cache.default' => 'array',
        'permission.cache.store' => 'array',
    ]);
    DB::purge('sqlite');

    return [
        $envPath,
        File::exists($envPath) ? File::get($envPath) : null,
        $sqlite,
        null,
        (string) config('database.default'),
    ];
}

function expectInstallQuestions(): PendingCommand
{
    $databaseOptions = [
        'sqlite' => 'SQLite (local default)',
        'pgsql' => 'PostgreSQL',
        'mysql' => 'MySQL 8+',
        'mariadb' => 'MariaDB',
    ];

    return test()->artisan(InstallCommand::class, [
        '--force' => true,
        '--no-npm' => true,
        '--no-seed' => true,
    ])
        ->expectsQuestion('APP_NAME', 'Excubia')
        ->expectsQuestion('APP_URL', 'http://localhost')
        ->expectsChoice('Database', 'sqlite', $databaseOptions);
}

function restoreInstallFixtures(
    string $envPath,
    ?string $envBackup,
    string $sqlite,
    ?string $sqliteBackup,
    string $originalDefault,
): void {
    config(['database.default' => $originalDefault]);
    DB::purge('sqlite');

    if ($envBackup === null) {
        File::delete($envPath);
    } else {
        File::put($envPath, $envBackup);
    }

    if (File::exists($sqlite)) {
        File::delete($sqlite);
    }

    putenv('APP_KEY');
    unset($_ENV['APP_KEY'], $_SERVER['APP_KEY']);
}

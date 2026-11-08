<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\ManagesDotEnvTrait;
use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * First-run installer for a fresh Excubia tree, as externa-core's `externa:install`: writes
 * .env from .env.example, migrates, seeds the permissions and the system project, links
 * storage and builds the frontend. Interactive by default; with `--no-interaction` every
 * answer comes from its option (or its default), for CI. The first admin is created in the
 * browser by the /setup wizard, so no default password is ever written.
 */
#[AsCommand(name: 'excubia:install')]
class InstallCommand extends Command
{
    use ManagesDotEnvTrait;

    protected $signature = 'excubia:install
        {--force : Allow overwriting an existing .env / running when APP_ENV looks like production}
        {--no-npm : Skip npm install / build}
        {--no-seed : Skip seeding the permissions and the system project}
        {--name=Excubia : APP_NAME (the default answer; used as is with --no-interaction)}
        {--url=http://localhost : APP_URL}
        {--db=sqlite : Database driver: sqlite, pgsql, mysql or mariadb}
        {--db-host=127.0.0.1 : DB_HOST (not for sqlite)}
        {--db-port= : DB_PORT (default 5432 for pgsql, 3306 otherwise)}
        {--db-database=excubia : DB_DATABASE (not for sqlite)}
        {--db-username=root : DB_USERNAME (not for sqlite)}
        {--db-password= : DB_PASSWORD (not for sqlite)}';

    protected $description = 'First-run setup: write .env, migrate, seed, npm build (interactive, or --no-interaction for CI)';

    /** Database drivers the installer offers. */
    private const DATABASES = [
        'sqlite' => 'SQLite (local default)',
        'pgsql' => 'PostgreSQL',
        'mysql' => 'MySQL 8+',
        'mariadb' => 'MariaDB',
    ];

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        if ($this->looksLikeProduction() && ! $force) {
            $this->error('Refusing: APP_ENV looks like production (or production .env present). Pass --force if you really mean it.');

            return self::FAILURE;
        }

        $this->info('Excubia installer');
        $this->newLine();

        $db = (string) $this->option('db');

        if (! array_key_exists($db, self::DATABASES)) {
            $this->error("Unknown --db={$db}. Use sqlite, pgsql, mysql or mariadb.");

            return self::FAILURE;
        }

        // Prompts return their default under --no-interaction, so the options are the answers.
        $appName = text('APP_NAME', default: (string) $this->option('name'), required: true);
        $appUrl = text('APP_URL', default: (string) $this->option('url'), required: true);
        $db = (string) select('Database', self::DATABASES, default: $db);
        $seed = ! $this->option('no-seed') && confirm('Seed the permissions and the system project?', default: true);

        $envPath = base_path('.env');
        if (File::exists($envPath) && ! $force) {
            if (! confirm('.env already exists. Overwrite?', default: false)) {
                $this->warn('Aborted — left existing .env untouched.');

                return self::FAILURE;
            }
        }

        if (! File::exists(base_path('.env.example'))) {
            $this->error('.env.example missing.');

            return self::FAILURE;
        }

        $preservedAppKey = $this->resolvePreservedAppKey($envPath);

        $env = File::get(base_path('.env.example'));
        $env = $this->setEnv($env, 'APP_NAME', $appName);
        $env = $this->setEnv($env, 'APP_URL', $appUrl);
        $env = $this->applyDatabase($env, $db);

        if ($preservedAppKey !== null) {
            $env = $this->setEnv($env, 'APP_KEY', $preservedAppKey);
        }

        File::put($envPath, $env);
        $this->info('Wrote .env');

        if ($db === 'sqlite') {
            $sqlite = database_path('database.sqlite');
            if (! File::exists($sqlite)) {
                File::put($sqlite, '');
                $this->line('Created database/database.sqlite');
            }
        }

        $this->ensureApplicationKey($envPath, $preservedAppKey);
        $this->applyRuntimeConfig($db);

        $migrate = $this->runMigrate($db, $force);
        if ($migrate !== self::SUCCESS) {
            return $migrate;
        }

        // Permissions and the system project only: the first admin comes from the /setup wizard.
        if ($seed) {
            foreach (['PermissionSeeder', 'SystemProjectSeeder'] as $seeder) {
                $seedCode = $this->runArtisanProcess($db, ['db:seed', '--class='.$seeder, '--force', '--no-interaction', '--database='.$db]);
                if ($seedCode !== self::SUCCESS) {
                    return $seedCode;
                }
            }
        }

        $this->call('storage:link');

        if (! $this->option('no-npm')) {
            $this->info('npm install…');
            $install = Process::path(base_path())->timeout(600)->run(['npm', 'install']);
            if (! $install->successful()) {
                $this->error($install->errorOutput() ?: $install->output());

                return self::FAILURE;
            }

            Process::path(base_path())->timeout(120)->run([
                'php', 'artisan', 'wayfinder:generate', '--with-form', '--no-interaction',
            ]);

            $this->info('npm run build…');
            $build = Process::path(base_path())->timeout(600)->run(['npm', 'run', 'build']);
            if (! $build->successful()) {
                $this->error($build->errorOutput() ?: $build->output());

                return self::FAILURE;
            }
        }

        $this->newLine();
        $this->info('Install complete.');
        $this->line('Next: open '.rtrim($appUrl, '/').'/setup to create the first admin and project.');
        $this->line('Run the scheduler and a queue worker: docs/self-hosting.md');

        return self::SUCCESS;
    }

    private function applyDatabase(string $env, string $db): string
    {
        $env = $this->setEnv($env, 'DB_CONNECTION', $db);

        if ($db === 'sqlite') {
            return $env;
        }

        $port = (string) $this->option('db-port');
        $host = text('DB_HOST', default: (string) $this->option('db-host'), required: true);
        $port = text('DB_PORT', default: $port !== '' ? $port : ($db === 'pgsql' ? '5432' : '3306'), required: true);
        $database = text('DB_DATABASE', default: (string) $this->option('db-database'), required: true);
        $username = text('DB_USERNAME', default: (string) $this->option('db-username'), required: true);
        $password = text('DB_PASSWORD', default: (string) $this->option('db-password'));

        $env = $this->setEnv($env, 'DB_HOST', $host);
        $env = $this->setEnv($env, 'DB_PORT', $port);
        $env = $this->setEnv($env, 'DB_DATABASE', $database);
        $env = $this->setEnv($env, 'DB_USERNAME', $username);

        return $this->setEnv($env, 'DB_PASSWORD', $password);
    }

    /**
     * Prefer an existing valid APP_KEY from the current .env (or boot config) so
     * rewriting from .env.example does not blank a key already present in the process.
     */
    private function resolvePreservedAppKey(string $envPath): ?string
    {
        if (File::exists($envPath)) {
            $existing = File::get($envPath);
            if (preg_match('/^APP_KEY=(.*)$/m', $existing, $matches) === 1) {
                $fromFile = $this->unwrapEnvValue(trim($matches[1]));
                if ($this->isValidAppKey($fromFile)) {
                    return $fromFile;
                }
            }
        }

        $fromConfig = (string) config('app.key');
        if ($this->isValidAppKey($fromConfig)) {
            return $fromConfig;
        }

        return null;
    }

    private function unwrapEnvValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            return stripcslashes(substr($value, 1, -1));
        }

        return $value;
    }

    private function isValidAppKey(string $key): bool
    {
        return $key !== '' && str_starts_with($key, 'base64:');
    }

    /**
     * Write a fresh APP_KEY when missing. Avoids key:generate failing when the
     * in-memory key no longer matches the rewritten .env file.
     */
    private function ensureApplicationKey(string $envPath, ?string $preservedAppKey): void
    {
        if ($preservedAppKey !== null) {
            config(['app.key' => $preservedAppKey]);
            $this->line('Preserved existing APP_KEY');

            return;
        }

        $key = 'base64:'.base64_encode(
            Encrypter::generateKey((string) config('app.cipher'))
        );

        $env = File::get($envPath);
        File::put($envPath, $this->setEnv($env, 'APP_KEY', $key));
        config(['app.key' => $key]);
        $this->info('Application key set successfully.');
    }

    /**
     * Apply the selected database to the already-booted config so migrate/seed
     * do not keep .env.example defaults.
     */
    private function applyRuntimeConfig(string $db): void
    {
        config(['database.default' => $db]);

        if ($db === 'sqlite') {
            config([
                'database.connections.sqlite.database' => database_path('database.sqlite'),
            ]);
        }

        // Drop boot-time drivers that may still point at Redis / the previous DB.
        if ($this->laravel->bound('cache')) {
            $this->laravel->make('cache')->forgetDriver();
        }

        // Re-resolve only the selected connection so migrate does not reuse a
        // boot-time PDO (e.g. still on Redis-backed defaults or another driver).
        DB::purge($db);
    }

    private function runMigrate(string $db, bool $force): int
    {
        $code = $this->runArtisanProcess($db, [
            'migrate',
            '--force',
            '--no-interaction',
            '--database='.$db,
        ]);

        if ($code === self::SUCCESS) {
            return self::SUCCESS;
        }

        if ($db !== 'sqlite' || ! $force) {
            return $code;
        }

        // Clear reset path for a half-applied SQLite install (DDL is not transactional).
        $this->warn('Migrate failed; resetting SQLite database and retrying…');
        $sqlite = database_path('database.sqlite');
        File::put($sqlite, '');
        config(['database.connections.sqlite.database' => $sqlite]);
        DB::purge('sqlite');

        return $this->runArtisanProcess('sqlite', [
            'migrate',
            '--force',
            '--no-interaction',
            '--database=sqlite',
        ]);
    }

    /**
     * Run artisan in a subprocess so parent process env (e.g. PHPUnit/CI DB_*)
     * cannot override the connection selected by this installer.
     *
     * @param  list<string>  $artisanArgs
     */
    private function runArtisanProcess(string $connection, array $artisanArgs): int
    {
        $result = Process::path(base_path())
            ->timeout(600)
            ->env($this->databaseProcessEnvironment($connection))
            ->run([PHP_BINARY, 'artisan', ...$artisanArgs]);

        if ($result->output() !== '') {
            $this->output->write($result->output());
        }

        if (! $result->successful()) {
            $this->error($result->errorOutput() !== '' ? $result->errorOutput() : $result->output());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Explicit DB_* / cache env for installer subprocesses.
     *
     * @return array<string, string>
     */
    private function databaseProcessEnvironment(string $connection): array
    {
        $config = config('database.connections.'.$connection, []);
        $appKey = (string) config('app.key');

        $env = [
            'DB_CONNECTION' => $connection,
            'DB_URL' => '',
            'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => (string) config('queue.default', 'database'),
        ];

        if ($appKey !== '') {
            $env['APP_KEY'] = $appKey;
        }

        if ($connection === 'sqlite') {
            $env['DB_DATABASE'] = (string) ($config['database'] ?? database_path('database.sqlite'));

            return $env;
        }

        $env['DB_HOST'] = (string) ($config['host'] ?? '127.0.0.1');
        $env['DB_PORT'] = (string) ($config['port'] ?? '');
        $env['DB_DATABASE'] = (string) ($config['database'] ?? '');
        $env['DB_USERNAME'] = (string) ($config['username'] ?? '');
        $env['DB_PASSWORD'] = (string) ($config['password'] ?? '');

        return $env;
    }
}

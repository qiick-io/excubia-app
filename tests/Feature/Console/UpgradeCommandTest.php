<?php

declare(strict_types=1);

use App\Console\Commands\UpgradeCommand;
use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Models\Project;
use App\Models\User;
use App\Services\Upgrade\ConfigFingerprintService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Spatie\Permission\Models\Permission;

it('registers excubia:upgrade', function () {
    expect(Artisan::all())->toHaveKey('excubia:upgrade');
});

it('refuses production without --force', function () {
    $this->app['env'] = 'production';

    $this->artisan(UpgradeCommand::class)
        ->expectsOutputToContain('Refusing to run in production without --force.')
        ->assertFailed();
});

it('runs on production with --force', function () {
    $this->app['env'] = 'production';
    // Production uses a shared cache; outbound:check refuses the array store outside tests.
    config(['cache.default' => 'database']);

    $this->artisan(UpgradeCommand::class, [
        '--force' => true,
        '--no-migrate' => true,
        '--no-permissions' => true,
    ])
        ->expectsOutputToContain('Automated subset done')
        ->assertSuccessful();
});

it('syncs the permissions and the system project, and restarts the workers', function () {
    Permission::query()->delete();

    $this->artisan(UpgradeCommand::class, ['--force' => true, '--no-migrate' => true])
        ->expectsOutputToContain('db:seed --class=PermissionSeeder')
        ->assertSuccessful();

    expect(Permission::query()->count())->toBe(count(PermissionEnum::cases()))
        ->and(Project::query()->where('is_system', true)->count())->toBe(1)
        ->and(Cache::get('illuminate:queue:restart'))->not->toBeNull();
});

it('runs the qiick package checks', function () {
    $this->artisan(UpgradeCommand::class, ['--force' => true, '--no-migrate' => true, '--no-permissions' => true])
        ->expectsOutputToContain('Running access:check')
        ->expectsOutputToContain('Running api-keys:check')
        ->expectsOutputToContain('Running outbound:check')
        ->expectsOutputToContain('Running http-basics:check')
        ->expectsOutputToContain('Running audit:check')
        ->expectsOutputToContain('Running settings:check')
        ->assertSuccessful();
});

it('warns when an https site trusts no proxy, so HSTS would never be sent', function () {
    config(['app.url' => 'https://excubia.example', 'excubia.trusted_proxies' => null, 'http-basics.security.hsts.max_age' => 31536000]);

    $this->artisan(UpgradeCommand::class, ['--force' => true, '--no-migrate' => true, '--no-permissions' => true])
        ->expectsOutputToContain('TRUSTED_PROXIES is empty')
        ->assertSuccessful();

    config(['excubia.trusted_proxies' => '10.0.0.0/8']);

    $this->artisan(UpgradeCommand::class, ['--force' => true, '--no-migrate' => true, '--no-permissions' => true])
        ->doesntExpectOutputToContain('TRUSTED_PROXIES is empty')
        ->assertSuccessful();
});

/**
 * As when the process booted from bootstrap/cache/config.php, with `optimize` stubbed so the test
 * never writes the real caches. Returns the recorded `optimize` runs.
 */
function bootedFromCachedConfiguration(string $cachedFingerprint): ArrayObject
{
    app()->instance('config_loaded_from_cache', true);
    config(['excubia.config_fingerprint' => $cachedFingerprint]);
    $optimizeRuns = new ArrayObject;
    Artisan::command('optimize', fn () => $optimizeRuns->append('optimize'));

    return $optimizeRuns;
}

it('keeps a configuration this release cached, and caches again at the end', function () {
    $optimizeRuns = bootedFromCachedConfiguration(ConfigFingerprintService::current());

    $this->artisan(UpgradeCommand::class, ['--force' => true, '--no-migrate' => true, '--no-permissions' => true])
        ->doesntExpectOutputToContain('cached by a previous release')
        ->expectsOutputToContain('Running access:check')
        ->assertSuccessful();

    expect($optimizeRuns->getArrayCopy())->toBe(['optimize']);
});

it('clears a configuration a previous release cached and runs the upgrade again on this release\'s', function () {
    $optimizeRuns = bootedFromCachedConfiguration('fingerprint-of-the-previous-release');
    $commands = new ArrayObject;
    Process::preventStrayProcesses();
    Process::fake(function (PendingProcess $process) use ($commands) {
        $commands->append(implode(' ', array_slice((array) $process->command, 1)));

        return Process::result('Automated subset done.');
    });

    $this->artisan(UpgradeCommand::class, ['--force' => true, '--no-permissions' => true])
        ->expectsOutputToContain('cached by a previous release')
        ->assertSuccessful();

    // A new process boots from the files with every package's defaults, then the caches return.
    $artisanCommands = array_values(array_filter($commands->getArrayCopy(), fn (string $command): bool => str_starts_with($command, 'artisan ')));

    expect($artisanCommands)->toBe(['artisan excubia:upgrade --no-interaction --force --no-permissions'])
        ->and($optimizeRuns->getArrayCopy())->toBe(['optimize']);
});

it('reports a failed rerun as a failed upgrade, and caches nothing', function () {
    $optimizeRuns = bootedFromCachedConfiguration('fingerprint-of-the-previous-release');
    Process::preventStrayProcesses();
    Process::fake(fn () => Process::result('access:check failed', exitCode: 1));

    $this->artisan(UpgradeCommand::class, ['--force' => true])->assertFailed();

    expect($optimizeRuns->getArrayCopy())->toBe([]);
});

it('neither clears nor caches a configuration that was never cached', function () {
    $optimizeRuns = new ArrayObject;
    Artisan::command('optimize', fn () => $optimizeRuns->append('optimize'));

    $this->artisan(UpgradeCommand::class, ['--force' => true, '--no-migrate' => true, '--no-permissions' => true])
        ->doesntExpectOutputToContain('cached by a previous release')
        ->assertSuccessful();

    expect($optimizeRuns->getArrayCopy())->toBe([]);
});

it('fingerprints the configuration sources by content', function () {
    expect(ConfigFingerprintService::current())->toBe(ConfigFingerprintService::current())
        ->and(config('excubia.config_fingerprint'))->toBe(ConfigFingerprintService::current());
});

it('stops when a qiick package check fails', function () {
    // A mixed super-admin is one of the states access:check refuses.
    $this->seed(PermissionSeeder::class);
    User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value, RoleEnum::Reader->value);

    $this->artisan(UpgradeCommand::class, ['--force' => true, '--no-migrate' => true, '--no-permissions' => true])
        ->doesntExpectOutputToContain('Automated subset done')
        ->assertFailed();
});

it('refuses --dry-run without --sync-upstream', function () {
    $this->artisan(UpgradeCommand::class, [
        '--dry-run' => true,
        '--no-migrate' => true,
        '--no-permissions' => true,
    ])
        ->expectsOutputToContain('--dry-run requires --sync-upstream')
        ->assertFailed();
});

it('refuses --sync-upstream when not a git repository', function () {
    fakeGit([
        'rev-parse --is-inside-work-tree' => Process::result(output: "false\n", exitCode: 128),
    ]);

    $this->artisan(UpgradeCommand::class, [
        '--sync-upstream' => 'v1.0.0',
        '--dry-run' => true,
        '--no-migrate' => true,
        '--no-permissions' => true,
    ])
        ->expectsOutputToContain('not a git repository')
        ->assertFailed();
});

it('refuses --sync-upstream on dirty tree without --force', function () {
    fakeGit([
        'rev-parse --is-inside-work-tree' => Process::result("true\n"),
        'status --porcelain' => Process::result(" M app/Foo.php\n"),
    ]);

    $this->artisan(UpgradeCommand::class, [
        '--sync-upstream' => 'v1.0.0',
        '--dry-run' => true,
        '--no-migrate' => true,
        '--no-permissions' => true,
    ])
        ->expectsOutputToContain('Git working tree is dirty')
        ->assertFailed();
});

it('dry-runs --sync-upstream and lists changing files without merging', function () {
    fakeGit([
        'rev-parse --is-inside-work-tree' => Process::result("true\n"),
        'status --porcelain' => Process::result(''),
        'remote' => Process::result("origin\nupstream\n"),
        'fetch --tags upstream' => Process::result(''),
        'rev-parse --verify refs/tags/v1.0.0' => Process::result("abc123\n"),
        'merge-base HEAD v1.0.0' => Process::result("def456\n"),
        'diff --name-status HEAD v1.0.0' => Process::result("M\tREADME.md\nA\tapp/New.php\n"),
    ]);

    $this->artisan(UpgradeCommand::class, [
        '--sync-upstream' => 'v1.0.0',
        '--dry-run' => true,
        '--no-migrate' => true,
        '--no-permissions' => true,
    ])
        ->expectsOutputToContain('2 file(s) would change')
        ->expectsOutputToContain('Dry-run only')
        ->assertSuccessful();

    Process::assertRanTimes(fn (PendingProcess $process) => implode(' ', gitArgs($process)) === 'merge --no-edit v1.0.0', 0);
});

it('applies --sync-upstream merge with --yes then continues upgrade subset', function () {
    fakeGit([
        'rev-parse --is-inside-work-tree' => Process::result("true\n"),
        'status --porcelain' => Process::result(''),
        'remote' => Process::result("origin\n"),
        'remote add upstream https://github.com/qiick-io/excubia-app.git' => Process::result(''),
        'fetch --tags upstream' => Process::result(''),
        'rev-parse --verify refs/tags/v1.0.0' => Process::result("abc123\n"),
        'merge-base HEAD v1.0.0' => Process::result("def456\n"),
        'diff --name-status HEAD v1.0.0' => Process::result("M\tREADME.md\n"),
        'merge --no-edit v1.0.0' => Process::result("Merge made\n"),
    ]);

    $this->artisan(UpgradeCommand::class, [
        '--sync-upstream' => 'v1.0.0',
        '--yes' => true,
        '--no-migrate' => true,
        '--no-permissions' => true,
    ])
        ->expectsOutputToContain('Synced from upstream tag v1.0.0')
        ->expectsOutputToContain('Automated subset done')
        ->doesntExpectOutputToContain('shares no history with upstream')
        ->assertSuccessful();

    // Histories already linked (a clone, or a second sync): no -s ours merge.
    Process::assertRanTimes(fn (PendingProcess $process) => in_array('ours', gitArgs($process), true), 0);
});

it('aborts apply when confirm is declined', function () {
    fakeGit([
        'rev-parse --is-inside-work-tree' => Process::result("true\n"),
        'status --porcelain' => Process::result(''),
        'remote' => Process::result("origin\nupstream\n"),
        'fetch --tags upstream' => Process::result(''),
        'rev-parse --verify refs/tags/v1.0.0' => Process::result("abc123\n"),
        'merge-base HEAD v1.0.0' => Process::result("def456\n"),
        'diff --name-status HEAD v1.0.0' => Process::result("M\tREADME.md\n"),
    ]);

    $this->artisan(UpgradeCommand::class, [
        '--sync-upstream' => 'v1.0.0',
        '--no-migrate' => true,
        '--no-permissions' => true,
    ])
        ->expectsConfirmation('1 files will be overwritten / changed — proceed with caution?', 'no')
        ->expectsOutputToContain('Aborted')
        ->assertFailed();

    Process::assertRanTimes(fn (PendingProcess $process) => implode(' ', gitArgs($process)) === 'merge --no-edit v1.0.0', 0);
});

it('fails clearly when merge conflicts', function () {
    fakeGit([
        'rev-parse --is-inside-work-tree' => Process::result("true\n"),
        'status --porcelain' => Process::result(''),
        'remote' => Process::result("origin\nupstream\n"),
        'fetch --tags upstream' => Process::result(''),
        'rev-parse --verify refs/tags/v1.0.0' => Process::result("abc123\n"),
        'merge-base HEAD v1.0.0' => Process::result("def456\n"),
        'diff --name-status HEAD v1.0.0' => Process::result("M\tREADME.md\n"),
        'merge --no-edit v1.0.0' => Process::result(
            output: "CONFLICT (content): Merge conflict in README.md\n",
            errorOutput: "Automatic merge failed; fix conflicts and then commit the result.\n",
            exitCode: 1,
        ),
    ]);

    $this->artisan(UpgradeCommand::class, [
        '--sync-upstream' => 'v1.0.0',
        '--yes' => true,
        '--no-migrate' => true,
        '--no-permissions' => true,
    ])
        ->expectsOutputToContain('Merge failed')
        ->assertFailed();
});

/**
 * The git calls of a first sync from v1.0.0 to v1.1.0 on a create-project install: no merge base.
 *
 * @return array<string, ProcessResult>
 */
function unrelatedHistoryGit(): array
{
    return [
        'rev-parse --is-inside-work-tree' => Process::result("true\n"),
        'status --porcelain' => Process::result(''),
        'remote' => Process::result("origin\nupstream\n"),
        'fetch --tags upstream' => Process::result(''),
        'rev-parse --verify refs/tags/v1.1.0' => Process::result("abc123\n"),
        'merge-base HEAD v1.1.0' => Process::result(exitCode: 1),
        'rev-parse --verify --quiet refs/tags/v1.0.0' => Process::result("fed789\n"),
        'diff --name-status v1.0.0 v1.1.0' => Process::result("M\tREADME.md\nA\tapp/New.php\n"),
        'merge -s ours --allow-unrelated-histories -m chore: link upstream history at v1.0.0 v1.0.0' => Process::result(''),
        'merge --no-edit v1.1.0' => Process::result("Merge made\n"),
    ];
}

it('links the histories at the installed release on the first sync of a create-project install, then merges', function () {
    installedVersion('1.0.0');
    $calls = fakeGit(unrelatedHistoryGit());

    $this->artisan(UpgradeCommand::class, [
        '--sync-upstream' => 'v1.1.0',
        '--yes' => true,
        '--no-migrate' => true,
        '--no-permissions' => true,
    ])
        ->expectsOutputToContain('This repository shares no history with upstream (typical after composer create-project).')
        ->expectsOutputToContain('First linking the histories at the installed version, without changing any file:')
        ->expectsOutputToContain('git merge -s ours --allow-unrelated-histories -m "chore: link upstream history at v1.0.0" v1.0.0')
        ->expectsOutputToContain('2 file(s) would change relative to v1.1.0')
        ->expectsOutputToContain('Linked upstream history at v1.0.0.')
        ->expectsOutputToContain('Synced from upstream tag v1.1.0')
        ->assertSuccessful();

    $merges = array_values(array_filter($calls->getArrayCopy(), fn (string $call) => str_starts_with($call, 'merge ')));

    expect($merges)->toBe([
        'merge -s ours --allow-unrelated-histories -m chore: link upstream history at v1.0.0 v1.0.0',
        'merge --no-edit v1.1.0',
    ]);
});

it('dry-runs the first sync: shows the link step and the files the merge changes, runs neither', function () {
    installedVersion('1.0.0');
    fakeGit(unrelatedHistoryGit());

    $this->artisan(UpgradeCommand::class, [
        '--sync-upstream' => 'v1.1.0',
        '--dry-run' => true,
        '--no-migrate' => true,
        '--no-permissions' => true,
    ])
        ->expectsOutputToContain('Would first link the histories at the installed version, without changing any file:')
        ->expectsOutputToContain('git merge -s ours --allow-unrelated-histories -m "chore: link upstream history at v1.0.0" v1.0.0')
        ->expectsOutputToContain('2 file(s) would change relative to v1.1.0')
        ->expectsOutputToContain('Dry-run only — no merge, no migrate.')
        ->assertSuccessful();

    Process::assertRanTimes(fn (PendingProcess $process) => (gitArgs($process)[0] ?? null) === 'merge', 0);
});

it('stops before merging when the installed version has no upstream tag', function () {
    installedVersion('1.0.0');
    fakeGit(array_merge(unrelatedHistoryGit(), [
        'rev-parse --verify --quiet refs/tags/v1.0.0' => Process::result(exitCode: 1),
    ]));

    $this->artisan(UpgradeCommand::class, [
        '--sync-upstream' => 'v1.1.0',
        '--yes' => true,
        '--no-migrate' => true,
        '--no-permissions' => true,
    ])
        ->expectsOutputToContain('This repository shares no history with upstream, and the installed version (1.0.0) has no matching tag v1.0.0.')
        ->expectsOutputToContain('git merge -s ours --allow-unrelated-histories -m "chore: link upstream history at vA.B.C" vA.B.C')
        ->expectsOutputToContain('Or start from a clone of https://github.com/qiick-io/excubia-app.git at a release tag.')
        ->doesntExpectOutputToContain('Automated subset done')
        ->assertFailed();

    Process::assertRanTimes(fn (PendingProcess $process) => in_array(gitArgs($process)[0] ?? null, ['merge', 'diff'], true), 0);
});

it('stops before merging when the installed version cannot be read', function () {
    installedVersion(null);
    fakeGit(array_merge(unrelatedHistoryGit(), [
        'rev-parse --verify --quiet refs/tags/vdev' => Process::result(exitCode: 1),
    ]));

    $this->artisan(UpgradeCommand::class, [
        '--sync-upstream' => 'v1.1.0',
        '--dry-run' => true,
        '--no-migrate' => true,
        '--no-permissions' => true,
    ])
        ->expectsOutputToContain('the installed version (dev) has no matching tag vdev')
        ->expectsOutputToContain('git merge -s ours --allow-unrelated-histories -m "chore: link upstream history at vA.B.C" vA.B.C')
        ->expectsOutputToContain('Or start from a clone of')
        ->assertFailed();

    Process::assertRanTimes(fn (PendingProcess $process) => in_array(gitArgs($process)[0] ?? null, ['merge', 'diff'], true), 0);
});

it('stops on a merge-base error other than "no shared history"', function () {
    installedVersion('1.0.0');
    fakeGit(array_merge(unrelatedHistoryGit(), [
        'merge-base HEAD v1.1.0' => Process::result(errorOutput: "fatal: bad object HEAD\n", exitCode: 128),
    ]));

    $this->artisan(UpgradeCommand::class, [
        '--sync-upstream' => 'v1.1.0',
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('Could not compute the merge base of HEAD and v1.1.0: fatal: bad object HEAD')
        ->assertFailed();

    Process::assertRanTimes(fn (PendingProcess $process) => in_array(gitArgs($process)[0] ?? null, ['merge', 'diff'], true), 0);
});

it('stops when linking the histories fails', function () {
    installedVersion('1.0.0');
    fakeGit(array_merge(unrelatedHistoryGit(), [
        'merge -s ours --allow-unrelated-histories -m chore: link upstream history at v1.0.0 v1.0.0' => Process::result(errorOutput: "fatal: boom\n", exitCode: 128),
    ]));

    $this->artisan(UpgradeCommand::class, [
        '--sync-upstream' => 'v1.1.0',
        '--yes' => true,
        '--no-migrate' => true,
        '--no-permissions' => true,
    ])
        ->expectsOutputToContain('Could not link the upstream history at v1.0.0: fatal: boom')
        ->assertFailed();

    Process::assertRanTimes(fn (PendingProcess $process) => implode(' ', gitArgs($process)) === 'merge --no-edit v1.1.0', 0);
});

/**
 * Points the app at a copy of composer.json with this `version` (null: none, as on `develop`),
 * the source of the installed version.
 */
function installedVersion(?string $version): void
{
    $directory = sys_get_temp_dir().'/excubia-upgrade-'.bin2hex(random_bytes(6));
    mkdir($directory);
    $composer = ['name' => 'qiick/excubia-app'] + ($version === null ? [] : ['version' => $version]);
    file_put_contents($directory.'/composer.json', json_encode($composer));
    app()->setBasePath($directory);

    test()->beforeApplicationDestroyed(fn () => File::deleteDirectory($directory));
}

/**
 * @param  array<string, ProcessResult>  $map
 * @return ArrayObject<int, string> the git calls, in order
 */
function fakeGit(array $map): ArrayObject
{
    $calls = new ArrayObject;

    Process::preventStrayProcesses();
    Process::fake(function (PendingProcess $process) use ($map, $calls) {
        $key = implode(' ', gitArgs($process));
        $calls[] = $key;

        if (! array_key_exists($key, $map)) {
            throw new RuntimeException('Unexpected git invocation: git '.$key);
        }

        return $map[$key];
    });

    return $calls;
}

/**
 * @return list<string>
 */
function gitArgs(PendingProcess $process): array
{
    $command = $process->command;

    if (is_string($command)) {
        $parts = preg_split('/\s+/', trim($command)) ?: [];

        return array_values(array_slice($parts, 1));
    }

    expect($command[0] ?? null)->toBe('git');

    return array_values(array_slice($command, 1));
}

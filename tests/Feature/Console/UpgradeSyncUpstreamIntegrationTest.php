<?php

declare(strict_types=1);

/*
 * Integration test (#323): runs the real `git` binary on throwaway repositories in the system
 * temp directory, local only (no network: `upstream` is a local path). The rest of the suite
 * fakes `Process`, so this group is excluded by default (phpunit.xml) and runs on demand:
 * vendor/bin/pest --group=integration
 */

use App\Console\Commands\UpgradeCommand;
use App\Services\Upgrade\UpgradeGitService;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\ExecutableFinder;

beforeEach(function () {
    if ((new ExecutableFinder)->find('git') === null) {
        $this->markTestSkipped('git is not installed.');
    }

    $this->root = sys_get_temp_dir().'/excubia-sync-'.bin2hex(random_bytes(6));
    $this->upstream = $this->root.'/upstream';
    $this->install = $this->root.'/install';
    mkdir($this->upstream, recursive: true);
    mkdir($this->install);

    // Upstream releases v1.0.0, v1.1.0 and v1.2.0; each tree carries its version in composer.json.
    syncTestRepo($this->upstream);
    syncTestRelease($this->upstream, '1.0.0', ['app.txt' => "one\ntwo\nthree\nfour\nfive\n"]);
    syncTestRelease($this->upstream, '1.1.0', ['app.txt' => "one\ntwo\nthree\nfour\nfive upstream\n", 'new.txt' => "added in 1.1.0\n"]);
    syncTestRelease($this->upstream, '1.2.0', ['later.txt' => "added in 1.2.0\n"]);
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

/**
 * A create-project install of $version: the release files as a new root commit, plus a local
 * change to a file upstream also changes (an add/add conflict without a merge base).
 */
function syncTestCreateProject(string $upstream, string $install, string $version): void
{
    syncTestRepo($install);
    syncTestGit($install, ['remote', 'add', UpgradeCommand::UPSTREAM_REMOTE, $upstream]);
    syncTestGit($install, ['fetch', '--tags', UpgradeCommand::UPSTREAM_REMOTE]);
    syncTestGit($install, ['checkout', 'v1.0.0', '--', '.']);
    file_put_contents($install.'/composer.json', json_encode(['name' => 'qiick/excubia-app', 'version' => $version]));
    syncTestGit($install, ['add', '-A']);
    syncTestGit($install, ['commit', '-m', 'Install Excubia']);

    file_put_contents($install.'/app.txt', "one local\ntwo\nthree\nfour\nfive\n");
    syncTestGit($install, ['commit', '-am', 'Local change']);

    expect(syncTestGit($install, ['merge-base', 'HEAD', 'v1.0.0'], mustSucceed: false)->successful())->toBeFalse();
    // The installed version is read from the install's composer.json; git runs in the install.
    app()->setBasePath($install);
    app()->instance(UpgradeGitService::class, new UpgradeGitService($install));
}

it('links the histories on the first sync of a create-project install, keeps local changes and merges cleanly', function () {
    syncTestCreateProject($this->upstream, $this->install, '1.0.0');

    $this->artisan(UpgradeCommand::class, ['--sync-upstream' => 'v1.1.0', '--yes' => true, '--no-migrate' => true, '--no-permissions' => true])
        ->expectsOutputToContain('shares no history with upstream')
        ->expectsOutputToContain('Linked upstream history at v1.0.0')
        ->expectsOutputToContain('Synced from upstream tag v1.1.0')
        ->assertSuccessful();

    expect(file_get_contents($this->install.'/app.txt'))->toBe("one local\ntwo\nthree\nfour\nfive upstream\n")
        ->and(file_get_contents($this->install.'/new.txt'))->toBe("added in 1.1.0\n")
        ->and(json_decode((string) file_get_contents($this->install.'/composer.json'), true)['version'])->toBe('1.1.0')
        ->and(trim(syncTestGit($this->install, ['status', '--porcelain'])->output()))->toBe('')
        ->and(syncTestGit($this->install, ['log', '--format=%s'])->output())->toContain('chore: link upstream history at v1.0.0');

    // A dry-run changes nothing.
    $head = trim(syncTestGit($this->install, ['rev-parse', 'HEAD'])->output());
    $this->artisan(UpgradeCommand::class, ['--sync-upstream' => 'v1.2.0', '--dry-run' => true])
        ->expectsOutputToContain('file(s) would change relative to v1.2.0')
        ->doesntExpectOutputToContain('shares no history with upstream')
        ->assertSuccessful();
    expect(trim(syncTestGit($this->install, ['rev-parse', 'HEAD'])->output()))->toBe($head)
        ->and(trim(syncTestGit($this->install, ['status', '--porcelain'])->output()))->toBe('');

    // The second sync merges normally, without linking again.
    $this->artisan(UpgradeCommand::class, ['--sync-upstream' => 'v1.2.0', '--yes' => true, '--no-migrate' => true, '--no-permissions' => true])
        ->doesntExpectOutputToContain('shares no history with upstream')
        ->expectsOutputToContain('Synced from upstream tag v1.2.0')
        ->assertSuccessful();

    expect(file_get_contents($this->install.'/later.txt'))->toBe("added in 1.2.0\n")
        ->and(file_get_contents($this->install.'/app.txt'))->toBe("one local\ntwo\nthree\nfour\nfive upstream\n")
        ->and(substr_count(syncTestGit($this->install, ['log', '--format=%s'])->output(), 'chore: link upstream history'))->toBe(1);
})->group('integration');

it('stops without touching the tree when the installed version has no upstream tag', function () {
    syncTestCreateProject($this->upstream, $this->install, '0.9.0');
    $head = trim(syncTestGit($this->install, ['rev-parse', 'HEAD'])->output());

    $this->artisan(UpgradeCommand::class, ['--sync-upstream' => 'v1.1.0', '--yes' => true, '--no-migrate' => true, '--no-permissions' => true])
        ->expectsOutputToContain('the installed version (0.9.0) has no matching tag v0.9.0.')
        ->expectsOutputToContain('git merge -s ours --allow-unrelated-histories -m "chore: link upstream history at vA.B.C" vA.B.C')
        ->assertFailed();

    expect(trim(syncTestGit($this->install, ['rev-parse', 'HEAD'])->output()))->toBe($head)
        ->and(trim(syncTestGit($this->install, ['status', '--porcelain'])->output()))->toBe('');
})->group('integration');

/**
 * A repository isolated from the user's git config (identity, signing, hooks).
 */
function syncTestRepo(string $path): void
{
    syncTestGit($path, ['init', '--quiet']);
    foreach (['user.name' => 'Excubia Test', 'user.email' => 'test@example.com', 'commit.gpgsign' => 'false', 'core.hooksPath' => '/dev/null'] as $key => $value) {
        syncTestGit($path, ['config', $key, $value]);
    }
}

/**
 * @param  array<string, string>  $files
 */
function syncTestRelease(string $path, string $version, array $files): void
{
    $files['composer.json'] = (string) json_encode(['name' => 'qiick/excubia-app', 'version' => $version]);
    foreach ($files as $name => $contents) {
        file_put_contents($path.'/'.$name, $contents);
    }

    syncTestGit($path, ['add', '-A']);
    syncTestGit($path, ['commit', '-m', "Release v{$version}"]);
    syncTestGit($path, ['tag', "v{$version}"]);
}

/**
 * @param  list<string>  $args
 */
function syncTestGit(string $path, array $args, bool $mustSucceed = true): ProcessResult
{
    $result = Process::path($path)->run(array_merge(['git'], $args));

    if ($mustSucceed) {
        $result->throw();
    }

    return $result;
}

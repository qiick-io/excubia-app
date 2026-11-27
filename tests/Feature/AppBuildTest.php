<?php

declare(strict_types=1);

use App\Services\SystemHealthService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * The sidebar build ID (#425): APP_BUILD first, then the commit `excubia:record-build` wrote at
 * build time, else none.
 */
beforeEach(function (): void {
    $this->recordedBuild = File::exists(SystemHealthService::buildFile()) ? File::get(SystemHealthService::buildFile()) : null;
    File::delete(SystemHealthService::buildFile());
});

afterEach(function (): void {
    $this->recordedBuild === null ? File::delete(SystemHealthService::buildFile()) : File::put(SystemHealthService::buildFile(), $this->recordedBuild);
});

it('links the short commit of the build', function () {
    expect(SystemHealthService::appBuild('c415dddc8ce55859f31ee7c1f20135d6514b2d2c'))->toBe([
        'id' => 'c415ddd',
        'url' => 'https://github.com/qiick-io/excubia-app/commit/c415dddc8ce55859f31ee7c1f20135d6514b2d2c',
    ]);
});

it('shows a build label that is not a commit as is, without a link', function () {
    expect(SystemHealthService::appBuild('2026.11.26-3'))->toBe(['id' => '2026.11.26-3', 'url' => null]);
});

it('records the commit at build time and shows it, unless APP_BUILD says otherwise', function () {
    Process::fake(['git rev-parse --show-toplevel HEAD' => Process::result(base_path()."\nc415dddc8ce55859f31ee7c1f20135d6514b2d2c\n")]);

    $this->artisan('excubia:record-build')->assertSuccessful();

    expect(SystemHealthService::appBuild()['id'] ?? null)->toBe('c415ddd');

    config(['app.build' => 'abcdef1234567']);
    expect(SystemHealthService::appBuild()['id'] ?? null)->toBe('abcdef1');
});

it('records nothing, drops a stale stamp and still succeeds without a git checkout', function () {
    File::put(SystemHealthService::buildFile(), (string) json_encode(['commit' => str_repeat('a', 40)]));
    Process::fake(['git rev-parse --show-toplevel HEAD' => Process::result('', 'fatal: not a git repository (or any of the parent directories): .git', 128)]);

    $this->artisan('excubia:record-build')->assertSuccessful();

    expect(File::exists(SystemHealthService::buildFile()))->toBeFalse()
        ->and(SystemHealthService::appBuild())->toBeNull();
});

it('does not record the commit of an enclosing repository', function () {
    Process::fake(['git rev-parse --show-toplevel HEAD' => Process::result(dirname(base_path())."\nc415dddc8ce55859f31ee7c1f20135d6514b2d2c\n")]);

    $this->artisan('excubia:record-build')->assertSuccessful();

    expect(File::exists(SystemHealthService::buildFile()))->toBeFalse();
});

it('still succeeds when git cannot run at all', function () {
    Process::fake(fn () => throw new RuntimeException('proc_open failed'));

    $this->artisan('excubia:record-build')->assertSuccessful();
});

it('keeps the stamp when git fails for another reason', function () {
    File::put(SystemHealthService::buildFile(), (string) json_encode(['commit' => str_repeat('b', 40)]));
    Process::fake(['git rev-parse --show-toplevel HEAD' => Process::result('', 'fatal: unable to read', 128)]);

    $this->artisan('excubia:record-build')->assertSuccessful();

    expect(SystemHealthService::appBuild()['id'] ?? null)->toBe('bbbbbbb');
});

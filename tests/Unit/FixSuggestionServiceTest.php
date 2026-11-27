<?php

declare(strict_types=1);

use App\Services\FixSuggestionService;

/**
 * Copyable fix commands and AI prompts per finding (#78), built from report data only.
 */
function fixFor(array $finding): array
{
    return (new FixSuggestionService)->forFinding($finding);
}

it('builds a composer update and a prompt for a Composer advisory', function () {
    $fix = fixFor([
        'checkId' => 'composer-advisories', 'checkName' => 'Known vulnerabilities (Composer)', 'severity' => 'high',
        'subject' => 'guzzlehttp/guzzle 7.4.0', 'message' => 'Known vulnerability', 'fixHint' => 'Update guzzle', 'advisoryId' => 'GHSA-25mq',
        'evidenceUrl' => 'https://github.com/advisories/GHSA-25mq',
        'details' => ['package' => 'guzzlehttp/guzzle', 'lockedVersion' => 'v7.4.0', 'fixedVersions' => ['7.4.5', '7.5.0']],
    ]);

    expect($fix['command'])->toBe('composer update guzzlehttp/guzzle --with-dependencies')
        ->and($fix['prompt'])->toContain('Known vulnerabilities (Composer), severity high')
        ->toContain('`guzzlehttp/guzzle` (installed 7.4.0)')
        ->toContain('GHSA-25mq (fixed in 7.4.5, 7.5.0)')
        ->toContain('Details: https://github.com/advisories/GHSA-25mq')
        ->toContain('run the test suite');
});

it('pins the npm fix version and suggests replacements, runtime upgrades and posture fixes', function () {
    expect(fixFor(['checkId' => 'npm-advisories', 'details' => ['package' => '@vitejs/plugin-react', 'fixedVersions' => ['4.3.2']]])['command'])
        ->toBe('npm install @vitejs/plugin-react@4.3.2')
        ->and(fixFor(['checkId' => 'composer-abandoned', 'details' => ['package' => 'fzaninotto/faker', 'replacement' => 'fakerphp/faker']])['prompt'])
        ->toContain('Replace it with `fakerphp/faker`')
        ->and(fixFor(['checkId' => 'laravel-next-blocker', 'details' => ['package' => 'laravel/ai', 'targetMajor' => 14]])['prompt'])
        ->toContain('blocks the upgrade to Laravel')
        ->and(fixFor(['checkId' => 'php-eol', 'details' => ['cycle' => '8.1', 'securitySupportEndsOn' => '2025-12-31']])['prompt'])
        ->toContain('Upgrade PHP from 8.1 to a supported version (security support ends on 2025-12-31)')
        ->and(fixFor(['checkId' => 'laravel-pending-migrations', 'details' => ['count' => 3]])['command'])
        ->toBe('php artisan migrate --force')
        ->and(fixFor(['checkId' => 'laravel-maintenance-mode'])['command'])->toBe('php artisan up')
        ->and(fixFor(['checkId' => 'laravel-debug-mode'])['command'])->toBeNull();
});

it('never puts shell metacharacters or unsafe URLs into the copied text', function () {
    $fix = fixFor([
        'checkId' => 'composer-advisories',
        'evidenceUrl' => 'javascript:alert(1)',
        'details' => ['package' => 'evil/pkg; rm -rf ~', 'fixedVersions' => ['1.0.0']],
    ]);

    expect($fix['command'])->toBeNull()
        ->and($fix['prompt'])->not->toContain('javascript:');

    expect(fixFor(['checkId' => 'npm-advisories', 'details' => ['package' => 'lodash', 'fixedVersions' => ['$(id)']]])['command'])
        ->toBe('npm update lodash');
});

it('falls back to the core fix hint for unknown checks', function () {
    expect(fixFor(['checkId' => 'future-check', 'fixHint' => 'Do the thing', 'message' => 'Something'])['prompt'])
        ->toContain('Do the thing')
        ->and(fixFor(['checkId' => 'future-check'])['command'])->toBeNull();
});

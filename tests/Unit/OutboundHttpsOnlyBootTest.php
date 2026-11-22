<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Production must require OUTBOUND_HTTPS_ONLY to be exactly true after
 * filter_var(FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) (#400 follow-up).
 */
it('treats non-true outbound https_only values as disabled', function (mixed $value): void {
    expect(AppServiceProvider::outboundHttpsOnlyIsStrictlyEnabled($value))->toBeFalse();
})->with([
    'string zero' => '0',
    'no' => 'no',
    'off' => 'off',
    'empty string' => '',
    'null' => null,
]);

it('treats explicit true outbound https_only values as enabled', function (mixed $value): void {
    expect(AppServiceProvider::outboundHttpsOnlyIsStrictlyEnabled($value))->toBeTrue();
})->with([
    'bool true' => true,
    'string true' => 'true',
    'one' => '1',
    'yes' => 'yes',
    'on' => 'on',
]);

it('refuses to boot in production when outbound https_only is not strictly true', function (): void {
    $provider = new AppServiceProvider(app());
    $assert = new ReflectionMethod(AppServiceProvider::class, 'assertOutboundHttpsOnly');

    $previousEnv = app()->environment();
    app()['env'] = 'production';

    // Force the env() read inside assertOutboundHttpsOnly to see a non-true raw value.
    putenv('OUTBOUND_HTTPS_ONLY=0');
    $_ENV['OUTBOUND_HTTPS_ONLY'] = '0';
    $_SERVER['OUTBOUND_HTTPS_ONLY'] = '0';

    try {
        expect(fn () => $assert->invoke($provider))
            ->toThrow(RuntimeException::class, 'OUTBOUND_HTTPS_ONLY must be true in production');
    } finally {
        app()['env'] = $previousEnv;
        putenv('OUTBOUND_HTTPS_ONLY');
        unset($_ENV['OUTBOUND_HTTPS_ONLY'], $_SERVER['OUTBOUND_HTTPS_ONLY']);
    }
});

it('allows outbound https_only false outside production', function (): void {
    $provider = new AppServiceProvider(app());
    $assert = new ReflectionMethod(AppServiceProvider::class, 'assertOutboundHttpsOnly');

    expect(app()->environment())->not->toBe('production');

    putenv('OUTBOUND_HTTPS_ONLY=false');
    $_ENV['OUTBOUND_HTTPS_ONLY'] = 'false';
    $_SERVER['OUTBOUND_HTTPS_ONLY'] = 'false';

    try {
        $assert->invoke($provider);
        expect(true)->toBeTrue();
    } finally {
        putenv('OUTBOUND_HTTPS_ONLY');
        unset($_ENV['OUTBOUND_HTTPS_ONLY'], $_SERVER['OUTBOUND_HTTPS_ONLY']);
    }
});

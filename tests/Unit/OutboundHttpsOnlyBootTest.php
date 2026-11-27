<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
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
    $provider = new class(app()) extends AppServiceProvider
    {
        public function runOutboundHttpsOnlyAssert(): void
        {
            $this->assertOutboundHttpsOnly();
        }
    };

    $previousEnv = app()->environment();
    $previousHttpsOnly = config('outbound.ssrf.https_only');
    app()['env'] = 'production';
    config(['outbound.ssrf.https_only' => false]);

    try {
        expect(fn () => $provider->runOutboundHttpsOnlyAssert())
            ->toThrow(RuntimeException::class, 'OUTBOUND_HTTPS_ONLY must be true in production');
    } finally {
        app()['env'] = $previousEnv;
        config(['outbound.ssrf.https_only' => $previousHttpsOnly]);
    }
});

it('allows outbound https_only false outside production', function (): void {
    $provider = new class(app()) extends AppServiceProvider
    {
        public function runOutboundHttpsOnlyAssert(): void
        {
            $this->assertOutboundHttpsOnly();
        }
    };

    expect(app()->environment())->not->toBe('production');

    $previousHttpsOnly = config('outbound.ssrf.https_only');
    config(['outbound.ssrf.https_only' => false]);

    try {
        $provider->runOutboundHttpsOnlyAssert();
        expect(true)->toBeTrue();
    } finally {
        config(['outbound.ssrf.https_only' => $previousHttpsOnly]);
    }
});

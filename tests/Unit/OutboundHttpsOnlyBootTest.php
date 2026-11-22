<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Production must not allow OUTBOUND_HTTPS_ONLY=false (adversarial review of #399).
 */
it('refuses outbound https_only false when the environment is production', function (): void {
    $provider = new AppServiceProvider(app());
    $assert = new ReflectionMethod(AppServiceProvider::class, 'assertOutboundHttpsOnly');

    $previousEnv = app()->environment();
    app()['env'] = 'production';
    config(['outbound.ssrf.https_only' => false]);

    try {
        expect(fn () => $assert->invoke($provider))
            ->toThrow(RuntimeException::class, 'OUTBOUND_HTTPS_ONLY cannot be false in production');
    } finally {
        app()['env'] = $previousEnv;
        config(['outbound.ssrf.https_only' => true]);
    }
});

it('allows outbound https_only false outside production', function (): void {
    $provider = new AppServiceProvider(app());
    $assert = new ReflectionMethod(AppServiceProvider::class, 'assertOutboundHttpsOnly');

    expect(app()->environment())->not->toBe('production');

    config(['outbound.ssrf.https_only' => false]);

    try {
        $assert->invoke($provider);
        expect(true)->toBeTrue();
    } finally {
        config(['outbound.ssrf.https_only' => true]);
    }
});

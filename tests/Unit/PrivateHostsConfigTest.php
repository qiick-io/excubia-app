<?php

declare(strict_types=1);

use Tests\TestCase;

uses(TestCase::class);

/**
 * OUTBOUND_ALLOW_PRIVATE_HOSTS is the ceiling of every outbound SSRF check (laravel-outbound
 * 0.2.0-beta.2), OIDC_ALLOW_PRIVATE_HOSTS the IdP's: an off value or a typo a deployment template
 * renders must keep private hosts refused, as only an explicit true opens them (fail closed).
 */
it('reads the private-host opt-ins as strict booleans', function (string $value, bool $isAllowed): void {
    putenv("OUTBOUND_ALLOW_PRIVATE_HOSTS={$value}");
    putenv("OIDC_ALLOW_PRIVATE_HOSTS={$value}");

    try {
        expect((require config_path('outbound.php'))['ssrf']['allow_private_hosts'])->toBe($isAllowed)
            ->and((require config_path('oidc.php'))['http']['allow_private_hosts'])->toBe($isAllowed);
    } finally {
        putenv('OUTBOUND_ALLOW_PRIVATE_HOSTS');
        putenv('OIDC_ALLOW_PRIVATE_HOSTS');
    }
})->with([
    'off' => ['off', false],
    'no' => ['no', false],
    'typo' => ['ture', false],
    'false' => ['false', false],
    'zero' => ['0', false],
    'true' => ['true', true],
    'one' => ['1', true],
    'yes' => ['yes', true],
]);

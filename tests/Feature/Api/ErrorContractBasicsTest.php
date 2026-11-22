<?php

declare(strict_types=1);

/**
 * The two error-contract behaviours that are already safe while the backed error-code
 * enum of C20 is still tracked as a divergence (#390).
 */
beforeEach(function (): void {
    config(['app.debug' => false]);
});

it('does not leak a stack trace on api errors with APP_DEBUG disabled', function (): void {
    $response = test()->call('POST', '/api/reports', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], '{}');

    $response->assertStatus(401);

    expect($response->json())->not->toHaveKey('exception')
        ->not->toHaveKey('trace')
        ->not->toHaveKey('file')
        ->not->toHaveKey('line');
});

it('answers api routes as JSON without an Accept header', function (): void {
    $response = test()->call('GET', '/api/does-not-exist');

    expect($response->headers->get('Content-Type'))->toContain('application/json')
        ->and($response->json())->toBeArray();
});

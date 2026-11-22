<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

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

it('renders thrown exceptions as clean JSON on api and mcp paths', function (string $method, string $path): void {
    $explode = static function (): never {
        throw new RuntimeException('error-contract-secret-marker');
    };

    if ($method === 'POST') {
        Route::post($path, $explode);
    } else {
        Route::get($path, $explode);
    }

    $response = test()->call($method, $path, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ]);

    $response->assertStatus(500);

    expect($response->getContent())->not->toContain('error-contract-secret-marker')
        ->and($response->json())->not->toHaveKey('exception')
        ->not->toHaveKey('trace')
        ->not->toHaveKey('file')
        ->not->toHaveKey('line');
})->with([
    'api' => ['GET', '/api/error-contract-explode'],
    'mcp' => ['POST', '/mcp'],
]);

it('answers api routes as JSON without an Accept header', function (): void {
    $response = test()->call('GET', '/api/does-not-exist');

    expect($response->headers->get('Content-Type'))->toContain('application/json')
        ->and($response->json())->toBeArray();
});

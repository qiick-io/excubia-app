<?php

declare(strict_types=1);

use App\Models\ApiKey;
use App\Models\User;

require_once __DIR__.'/BrowserSeedHelpers.php';

/**
 * Settings → API keys (#315): a user creates a key in the dialog, sees it once, then revokes it
 * through the destructive confirmation, in light and dark, with no serious axe issue.
 */
it('creates a key, shows it once and revokes it', function (string $scheme) {
    $user = User::factory()->create();
    $this->actingAs($user);

    $page = visitA11y(route('api-keys.index', absolute: false), $scheme)
        ->assertSee('No API keys yet')
        ->click('[data-test="api-key-create-open"]')
        ->fill('#api_key_name', 'Claude Code')
        ->click('[data-test="api-key-create-submit"]')
        ->waitForText('Copy this key now. It will not be shown again.')
        // The dialog closes with an animation; axe would read its fading text.
        ->wait(1)
        ->assertPresent('[data-test="api-key-secret-value"]')
        ->assertSeeIn('tbody tr:first-child', 'Claude Code')
        ->assertSeeIn('tbody tr:first-child', 'mcp:read')
        ->assertSeeIn('tbody tr:first-child', 'Active')
        ->assertNoJavaScriptErrors()
        ->assertNoAccessibilityIssues(1);

    $apiKey = ApiKey::query()->sole();
    $secret = $page->value('[data-test="api-key-secret-value"]');
    expect($secret)->toStartWith($apiKey->key_prefix)
        ->and($secret)->toMatch(ApiKey::TOKEN_PATTERN);

    $page->click('[data-test="api-key-revoke"]')
        ->click('[data-test="confirm-destructive"]')
        ->waitForText('API key revoked.')
        ->assertSeeIn('tbody tr:first-child', 'Revoked')
        ->assertNoJavaScriptErrors();

    expect($apiKey->fresh()->revoked_at)->not->toBeNull();

    // A new visit never shows the key again.
    visit(route('api-keys.index', absolute: false))
        ->assertMissing('[data-test="api-key-secret"]')
        ->assertNoJavaScriptErrors();
})->with(['light', 'dark']);

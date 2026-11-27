<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use App\Models\ApiKey;
use App\Models\User;
use Database\Seeders\PermissionSeeder;

require_once __DIR__.'/BrowserSeedHelpers.php';

const MCP_PERMISSION_SELECT = '[data-test="api-key-create-dialog"] select';

/**
 * Settings → API keys (#315, #364), built from the app-ui api-key items: a user creates a key
 * with write access and the optional restrictions, sees it once in the strict secret dialog
 * (Done stays disabled until the acknowledgement is ticked), rotates it and revokes it, in light
 * and dark, with no serious axe issue. After acknowledge, history.state must not keep the secret
 * and Back must not reopen the dialog.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
});

/**
 * Assert history.state has no issuedApiKey flash and does not retain the full plaintext secret.
 * (The list's `key_prefix` may still appear; that is not the secret.)
 */
function historyStateHidesSecret(string $plainSecret): string
{
    $encodedSecret = json_encode($plainSecret, JSON_THROW_ON_ERROR);

    return <<<JS
        () => {
            const encoded = JSON.stringify(window.history.state ?? null);
            if (encoded.includes('"issuedApiKey"')) {
                return false;
            }
            if (encoded.includes({$encodedSecret})) {
                return false;
            }
            return true;
        }
    JS;
}

it('creates a key, shows it once, rotates and revokes it', function (string $scheme) {
    // A super-admin alone can create keys (decision 2026-11-26).
    $user = User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value);
    $this->actingAs($user);

    $page = visitA11y(route('api-keys.index', absolute: false), $scheme)
        ->assertSee('No API keys yet')
        ->click('[data-test="api-key-create-open"]')
        ->fill('#api-key-create-form-name', 'Claude Code')
        ->assertSee('Write includes Read')
        ->assertValue(MCP_PERMISSION_SELECT, 'read')
        ->keys(MCP_PERMISSION_SELECT, 'w')
        ->assertValue(MCP_PERMISSION_SELECT, 'write')
        ->fill('#api-key-create-form-ip_allowlist', '203.0.113.4, 10.0.0.0/8')
        ->fill('#api-key-create-form-rate_limit_per_minute', '120')
        ->click('[data-test="api-key-create-submit"]')
        ->waitForText('Copy the key for “Claude Code”')
        ->wait(1)
        ->assertPresent('[data-test="api-key-secret-value"]')
        ->assertDisabled('[data-test="api-key-secret-done"]')
        ->assertNoJavaScriptErrors()
        ->assertNoAccessibilityIssues(1);

    $apiKey = ApiKey::query()->sole();
    $secret = $page->value('[data-test="api-key-secret-value"]');
    expect($secret)->toStartWith($apiKey->key_prefix)
        ->and($secret)->toMatch(EXCUBIA_TOKEN_PATTERN)
        ->and($apiKey->scopes)->toBe(['mcp:write'])
        ->and($apiKey->ip_allowlist)->toBe(['203.0.113.4', '10.0.0.0/8'])
        ->and($apiKey->rate_limit_per_minute)->toBe(120);

    $page->click('[data-test="api-key-secret-acknowledge"]')
        ->click('[data-test="api-key-secret-done"]')
        ->wait(1)
        ->assertMissing('[data-test="api-key-secret-dialog"]')
        ->assertSeeIn('tbody tr:first-child', 'Claude Code')
        ->assertSeeIn('tbody tr:first-child', 'mcp:write')
        ->assertSeeIn('tbody tr:first-child', 'Active')
        ->assertScript(historyStateHidesSecret($secret), true);

    $page->click('[data-test="api-key-rotate"]')
        ->waitForText('Copy the new secret for “Claude Code”')
        ->assertSee('The previous secret keeps working until')
        ->assertNoJavaScriptErrors();

    $rotatedSecret = $page->value('[data-test="api-key-secret-value"]');
    expect($rotatedSecret)->not->toBe($secret)
        ->and($apiKey->fresh()->previous_key_valid_until)->not->toBeNull();

    $page->click('[data-test="api-key-secret-acknowledge"]')
        ->click('[data-test="api-key-secret-done"]')
        ->wait(1)
        ->assertMissing('[data-test="api-key-secret-dialog"]')
        ->assertScript(historyStateHidesSecret($rotatedSecret), true)
        ->click('[data-test="api-key-revoke"]')
        ->click('[data-test="confirm-destructive"]')
        ->waitForText('API key revoked.')
        ->assertSeeIn('tbody tr:first-child', 'Revoked')
        ->assertNoJavaScriptErrors();

    expect($apiKey->fresh()->revoked_at)->not->toBeNull();

    // A new visit never shows the key again.
    visit(route('api-keys.index', absolute: false))
        ->assertMissing('[data-test="api-key-secret-dialog"]')
        ->assertNoJavaScriptErrors();
})->with(['light', 'dark']);

it('explains why an account without can-use-ai cannot create a key', function (string $scheme) {
    $user = User::factory()->create()->assignRole(RoleEnum::Reader->value);
    $this->actingAs($user);

    visitA11y(route('api-keys.index', absolute: false), $scheme)
        ->click('[data-test="api-key-create-open"]')
        ->assertSee('your account needs the Can use AI permission')
        ->assertValue(MCP_PERMISSION_SELECT, 'none')
        ->assertDisabled(MCP_PERMISSION_SELECT)
        ->assertDisabled('[data-test="api-key-create-submit"]')
        ->assertNoJavaScriptErrors()
        ->assertNoAccessibilityIssues(1);
})->with(['light', 'dark']);

it('does not reopen the secret dialog when navigating Back after acknowledge', function () {
    $user = User::factory()->create()->assignRole(RoleEnum::Admin->value);
    $this->actingAs($user);

    $page = visit(route('api-keys.index', absolute: false))
        ->click('[data-test="api-key-create-open"]')
        ->fill('#api-key-create-form-name', 'Back Nav')
        ->assertValue(MCP_PERMISSION_SELECT, 'read')
        ->click('[data-test="api-key-create-submit"]')
        ->waitForText('Copy the key for “Back Nav”');

    $secret = $page->value('[data-test="api-key-secret-value"]');

    $page->click('[data-test="api-key-secret-acknowledge"]')
        ->click('[data-test="api-key-secret-done"]')
        ->wait(1)
        ->assertMissing('[data-test="api-key-secret-dialog"]')
        ->assertScript(historyStateHidesSecret($secret), true);

    $page->click('nav a[href="/settings/profile"][data-size="sm"]')
        ->waitForText('Profile')
        ->wait(1);

    $page->script('() => { window.history.back(); return true; }');

    $page->waitForText('API keys')
        ->wait(1)
        ->assertMissing('[data-test="api-key-secret-dialog"]')
        ->assertScript(historyStateHidesSecret($secret), true)
        ->assertNoJavaScriptErrors();
});

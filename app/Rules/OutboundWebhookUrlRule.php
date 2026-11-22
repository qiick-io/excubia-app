<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;
use Qiick\Outbound\Ssrf\RemoteUrlGuard;

/**
 * Refuse an alert webhook URL the outbound SSRF guard would not call.
 *
 * Runs at save time so an invalid host (empty DNS labels, credentials, non-HTTPS,
 * private/metadata literal) never lands in Settings; delivery still re-checks and pins.
 */
final class OutboundWebhookUrlRule implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $result = app(RemoteUrlGuard::class)->tryAuthorize($value);

        if (! $result['ok']) {
            $fail($result['reason']);
        }
    }
}

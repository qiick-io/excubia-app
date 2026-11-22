<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;
use Qiick\Outbound\Webhooks\StandardWebhookVerifier;
use Qiick\Outbound\Webhooks\WebhookException;
use Qiick\Outbound\Webhooks\WebhookSecret;

/**
 * Accepts only Standard Webhooks secrets (`whsec_` + base64 of 24–64 raw bytes).
 *
 * Excuvia is unreleased, so raw legacy secrets are refused — receivers verify with
 * {@see StandardWebhookVerifier} against this form only.
 */
final class WebhookSecretRule implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        try {
            WebhookSecret::parse($value);
        } catch (WebhookException) {
            $fail('The :attribute must be a Standard Webhooks secret (whsec_ followed by base64 of 24 to 64 bytes).');
        }
    }
}

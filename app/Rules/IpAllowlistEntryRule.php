<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;
use Qiick\ApiKeys\IpAllowlist;

/**
 * Refuse a malformed IP / CIDR in an API key `ip_allowlist` at input time, and refuse
 * any CIDR whose prefix length is 0 (`0.0.0.0/0`, `::/0`, `1.0.0.0/0`, `2001:db8::/0`, …):
 * those mean no restriction, so leave the field empty instead.
 *
 * Validates with qiick/laravel-api-keys' own IpAllowlist::isValidEntry(), the rules it matches
 * by at request time, so a value that can never match a client is never stored.
 */
class IpAllowlistEntryRule implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail(__('Each allowlist entry must be a valid IPv4 or IPv6 address or CIDR.'));

            return;
        }

        if ($this->isIpv4Mapped($value)) {
            $fail(__('Write :entry as its IPv4 form: IPv4-mapped IPv6 entries are matched as IPv4.', [
                'entry' => $value,
            ]));

            return;
        }

        if ($this->hasUnrestrictedPrefix($value)) {
            $fail(__('Leave the allowlist empty to allow every address; :entry means no restriction.', [
                'entry' => $value,
            ]));

            return;
        }

        if (! (new IpAllowlist)->isValidEntry($value)) {
            $fail(__('Each allowlist entry must be a valid IPv4 or IPv6 address or CIDR.'));
        }
    }

    /**
     * qiick/laravel-api-keys matches an IPv4-mapped entry (`::ffff:a.b.c.d[/n]`) as IPv4 with
     * prefix n−96, so `/96` would allow every IPv4 address and a prefix below 96 would deny the
     * whole list. Refusing the form keeps what the form accepts and what the matcher does equal.
     */
    private function isIpv4Mapped(string $value): bool
    {
        $address = explode('/', $value, 2)[0];
        $binary = @inet_pton($address);

        return $binary !== false
            && strlen($binary) === 16
            && strlen((string) (new IpAllowlist)->packed($address)) === 4;
    }

    /**
     * True when the entry is a CIDR whose prefix length is 0 (including `/00`).
     */
    private function hasUnrestrictedPrefix(string $value): bool
    {
        if (! str_contains($value, '/')) {
            return false;
        }

        $prefixLength = substr($value, strrpos($value, '/') + 1);

        return ctype_digit($prefixLength) && (int) $prefixLength === 0;
    }
}

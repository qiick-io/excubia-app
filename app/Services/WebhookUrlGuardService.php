<?php

declare(strict_types=1);

namespace App\Services;

use Closure;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * SSRF guard for outbound alert webhook URLs (HTTPS only, public hosts only).
 *
 * Ported from signaculum-core #185. Refuses credentials in the URL, non-HTTPS schemes,
 * and private / loopback / link-local / CGNAT / metadata addresses. Metadata hosts stay
 * blocked when a name resolves to them too.
 *
 * Every authorised destination is pinned: the guard decides which address the request
 * goes to, and hands curl exactly that address through CURLOPT_CONNECT_TO with an empty
 * source host, which applies whatever the URL's host looks like. A hostname is resolved
 * once, for A and AAAA, every answer is checked and one of them is pinned, so a DNS
 * rebinding answer after the check is never used; a host that resolves to nothing is
 * refused, because there is nothing checked to pin. An IP literal is pinned to itself.
 * CURLOPT_RESOLVE cannot do this job: it is a DNS-cache entry keyed by host name, and
 * curl never consults it for a host it parses as an address itself, such as
 * `2852039166` or `169.254.169.254.`.
 *
 * An IPv4 address written any other way than dotted decimal (`2852039166`,
 * `0251.0376.0251.0376`, `169.254.43518`, a trailing dot) is read the way curl and
 * inet_aton(3) read it, checked against the metadata list, and then refused: a webhook
 * has no reason to spell an address like that, and two parsers can disagree on it.
 *
 * An IPv6 literal arrives from `parse_url()` in URL bracket form and is unwrapped
 * and canonicalised before any check, so it is compared as an address and never
 * mistaken for a DNS name. A bracketed host that is not a valid IPv6 literal is refused
 * outright rather than handed to the resolver.
 */
final class WebhookUrlGuardService
{
    /**
     * @var list<string>
     */
    private const array BLOCKED_NETWORKS = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
        '::/128',
        '::1/128',
        'fc00::/7',
        'fe80::/10',
        'ff00::/8',
        '2001:db8::/32',
    ];

    /**
     * @var list<string>
     */
    private const array METADATA_HOSTS = [
        'metadata',
        'metadata.google.internal',
        '169.254.169.254',
        'fd00:ec2::254',
        // Alibaba and Oracle serve metadata on public-looking addresses, outside link-local.
        '100.100.100.200',
        '192.0.0.192',
    ];

    /**
     * @param  (Closure(string): list<mixed>)|null  $hostResolver
     */
    public function __construct(
        private readonly ?Closure $hostResolver = null,
    ) {}

    /**
     * @return string|null English error when the URL must not be called; null when safe
     */
    public function validate(string $url): ?string
    {
        $authorized = $this->authorizeOutbound($url);

        return $authorized['ok'] ? null : $authorized['reason'];
    }

    /**
     * Validate the URL once and return the CURLOPT_CONNECT_TO entry that pins the checked address.
     *
     * @return array{ok: true, curl_connect_to: list<string>}|array{ok: false, reason: string}
     */
    public function authorizeOutbound(string $url): array
    {
        $trimmed = trim($url);

        if ($trimmed === '') {
            return ['ok' => false, 'reason' => 'URL is required.'];
        }

        if (strlen($trimmed) > 2048) {
            return ['ok' => false, 'reason' => 'URL is too long.'];
        }

        $parts = parse_url($trimmed);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return ['ok' => false, 'reason' => 'Invalid URL.'];
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return ['ok' => false, 'reason' => 'URL must not contain credentials.'];
        }

        $scheme = strtolower((string) $parts['scheme']);

        if ($scheme !== 'https') {
            return ['ok' => false, 'reason' => 'Only https URLs are allowed.'];
        }

        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : 443;

        // `parse_url()` hands back an IPv6 host with its brackets on; they are URL syntax,
        // not part of the address, and every check below wants the address.
        if (str_contains($host, '[') || str_contains($host, ']')) {
            $literal = $this->unwrapIpv6Literal($host);

            // Brackets mean IPv6 and nothing else (RFC 3986 §3.2.2). Anything else in them
            // is refused here; letting it fall through to the resolver as a name is how a
            // zone id such as `[fe80::1%25en0]` reached curl unchecked.
            if ($literal === null) {
                return ['ok' => false, 'reason' => 'Invalid URL.'];
            }

            $host = $literal;
        } else {
            // A trailing dot is the DNS root (RFC 1034 §3.1): `localhost.` is `localhost`, and
            // `169.254.169.254.` is the metadata address to curl. The names are compared without it.
            $name = str_ends_with($host, '.') ? substr($host, 0, -1) : $host;

            if ($name === '') {
                return ['ok' => false, 'reason' => 'Invalid URL.'];
            }

            $ipv4 = $this->readIpv4Address($name);

            if ($ipv4 !== null) {
                if ($this->isMetadataHost($ipv4)) {
                    return ['ok' => false, 'reason' => 'Host is not allowed.'];
                }

                if ($ipv4 !== $host) {
                    return ['ok' => false, 'reason' => 'Invalid URL.'];
                }
            }

            $host = $name;
        }

        if ($this->isMetadataHost($host)) {
            return ['ok' => false, 'reason' => 'Host is not allowed.'];
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if ($this->isBlockedAddress($host)) {
                return ['ok' => false, 'reason' => 'Host is not allowed.'];
            }

            return $this->authorizedTo($host, $port);
        }

        if ($this->isBlockedHostName($host)) {
            return ['ok' => false, 'reason' => 'Host is not allowed.'];
        }

        $resolvedAddresses = $this->resolveHostAddresses($host);

        // Nothing resolved means nothing checked, and so nothing to pin; letting curl resolve
        // the name itself is how every curl-style IPv4 spelling reached the metadata service.
        if ($resolvedAddresses === []) {
            return ['ok' => false, 'reason' => 'Host could not be resolved.'];
        }

        foreach ($resolvedAddresses as $address) {
            // The metadata service stays blocked when a name resolves to it, not only when it is
            // named, or the invariant in the class docblock is one DNS record away from false.
            if ($this->isMetadataHost($address) || $this->isBlockedAddress($address)) {
                return ['ok' => false, 'reason' => 'Host resolves to a disallowed address.'];
            }
        }

        return $this->authorizedTo($this->preferredPinnedAddress($resolvedAddresses), $port);
    }

    /**
     * Authorise the request and pin its connection to the address the guard checked.
     *
     * CURLOPT_CONNECT_TO `::ADDRESS:PORT` leaves the source host and port empty, so it matches
     * whatever host curl takes from the URL, however that host is spelt; the URL host is still
     * what TLS verifies and what the Host header says. The address is always an IP literal, so
     * curl connects without a lookup of its own.
     *
     * @return array{ok: true, curl_connect_to: list<string>}
     */
    private function authorizedTo(string $address, int $port): array
    {
        return [
            'ok' => true,
            'curl_connect_to' => [sprintf('::%s:%d', $this->formatCurlAddress($address), $port)],
        ];
    }

    private function isMetadataHost(string $host): bool
    {
        foreach ($this->addressForms($host) as $form) {
            if (in_array($form, self::METADATA_HOSTS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The dotted-decimal address of a host that curl and inet_aton(3) read as IPv4, or null for
     * a host they read as a name.
     *
     * Up to four parts, each decimal, octal (leading `0`) or hexadecimal (`0x`); the last part
     * fills every byte the earlier ones did not, so `169.254.43518` and `2852039166` are both
     * 169.254.169.254. Anything else (five parts, a part out of range, an empty part, a letter
     * outside a `0x` prefix) is a name to both of them, and so to the guard.
     */
    private function readIpv4Address(string $host): ?string
    {
        if (preg_match('/^[0-9a-fx.]+$/', $host) !== 1) {
            return null;
        }

        $parts = explode('.', $host);

        if (count($parts) > 4) {
            return null;
        }

        $numbers = [];

        foreach ($parts as $part) {
            $number = $this->readIpv4Number($part);

            if ($number === null) {
                return null;
            }

            $numbers[] = $number;
        }

        $last = (int) array_pop($numbers);
        $value = 0;

        foreach ($numbers as $number) {
            if ($number > 255) {
                return null;
            }

            $value = $value * 256 + $number;
        }

        $remainingBytes = 4 - count($numbers);

        if ($last >= 256 ** $remainingBytes) {
            return null;
        }

        return long2ip($value * 256 ** $remainingBytes + $last);
    }

    private function readIpv4Number(string $part): ?int
    {
        [$digits, $base] = match (true) {
            preg_match('/^0x[0-9a-f]+$/', $part) === 1 => [substr($part, 2), 16],
            preg_match('/^0[0-7]*$/', $part) === 1 => [$part, 8],
            preg_match('/^[1-9][0-9]*$/', $part) === 1 => [$part, 10],
            default => [null, 0],
        };

        if ($digits === null) {
            return null;
        }

        $digits = ltrim($digits, '0');

        // Eleven digits in any of the three bases already exceed 32 bits, without overflowing.
        if (strlen($digits) > 11) {
            return null;
        }

        $number = $digits === '' ? 0 : intval($digits, $base);

        return $number > 0xFFFFFFFF ? null : $number;
    }

    /**
     * The address inside a URL's IPv6 brackets, canonicalised, or null when the bracketed
     * host is not a valid IPv6 literal.
     *
     * Canonicalising matters as much as unwrapping: METADATA_HOSTS and the blocklist are
     * compared as text, so `[FD00:EC2:0:0:0:0:0:254]` would miss the metadata entry that
     * `[fd00:ec2::254]` hits. `filter_var()` rejects a zone id, an IPv4 address and a
     * hostname in brackets, all of which therefore return null.
     */
    private function unwrapIpv6Literal(string $host): ?string
    {
        if (! str_starts_with($host, '[') || ! str_ends_with($host, ']')) {
            return null;
        }

        $literal = substr($host, 1, -1);

        if (filter_var($literal, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return null;
        }

        $packed = inet_pton($literal);

        return $packed === false ? $literal : (string) inet_ntop($packed);
    }

    /**
     * Every address a host denotes: itself, plus the IPv4 address an IPv6 literal carries.
     *
     * `::ffff:169.254.169.254` (IPv4-mapped, RFC 4291 §2.5.5.2) and `::169.254.169.254`
     * (IPv4-compatible, §2.5.5.1) reach the same host as the IPv4 address inside them, and
     * neither spelling matches an IPv4 entry as text. IpUtils unwraps the mapped form against
     * an IPv4 subnet but not the compatible one, and neither form can match METADATA_HOSTS,
     * so both are expanded here instead.
     *
     * @return list<string>
     */
    private function addressForms(string $address): array
    {
        $packed = @inet_pton($address);

        if ($packed === false || strlen($packed) !== 16) {
            return [$address];
        }

        $prefix = substr($packed, 0, 12);

        if ($prefix !== str_repeat("\0", 10)."\xff\xff" && $prefix !== str_repeat("\0", 12)) {
            return [$address];
        }

        $embedded = inet_ntop(substr($packed, 12));

        return $embedded === false ? [$address] : [$address, $embedded];
    }

    /**
     * curl needs brackets around an IPv6 pin (`::[2001:db8::1]:443`).
     */
    private function formatCurlAddress(string $address): string
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return '['.$address.']';
        }

        return $address;
    }

    /**
     * The canonical IP addresses a hostname resolves to; anything else a resolver returns is
     * dropped, because only an address may become the pin.
     *
     * DNS first, for A and AAAA; when DNS has no answer, the system resolver (hosts file, IPv4),
     * so a name an admin maps locally keeps working now that an unresolved name is refused.
     *
     * @return list<string>
     */
    private function resolveHostAddresses(string $host): array
    {
        $resolver = $this->hostResolver ?? static function (string $name): array {
            $addresses = [];

            foreach (@dns_get_record($name, DNS_A | DNS_AAAA) ?: [] as $record) {
                $addresses[] = $record['ip'] ?? $record['ipv6'] ?? null;
            }

            return $addresses !== [] ? $addresses : (gethostbynamel($name) ?: []);
        };

        $addresses = [];

        foreach ($resolver($host) as $address) {
            $packed = is_string($address) ? @inet_pton($address) : false;

            if ($packed !== false) {
                $addresses[] = (string) inet_ntop($packed);
            }
        }

        return array_values(array_unique($addresses));
    }

    /**
     * Prefer an IPv4 pin when both families were validated (curl + Http client quirks).
     *
     * @param  non-empty-list<string>  $addresses
     */
    private function preferredPinnedAddress(array $addresses): string
    {
        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return $address;
            }
        }

        return $addresses[0];
    }

    private function isBlockedHostName(string $host): bool
    {
        return $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal');
    }

    private function isBlockedAddress(string $address): bool
    {
        foreach ($this->addressForms($address) as $form) {
            if (IpUtils::checkIp($form, self::BLOCKED_NETWORKS)) {
                return true;
            }
        }

        return false;
    }
}

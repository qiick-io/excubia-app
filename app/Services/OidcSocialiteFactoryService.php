<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\Oidc\GenericOidcProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Two\AbstractProvider;
use RuntimeException;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Builds the Socialite OIDC driver from the SSO settings and the issuer's discovery document.
 * Copied from qiick-io/externa-core until Signaculum SSO exists.
 *
 * `services.oidc.*` (OIDC_DISCOVERY_BASE, OIDC_SERVER_ORIGIN, OIDC_ALLOW_PRIVATE_HOSTS) exist
 * only for Docker testing, where the browser and the container reach the IdP on different hosts.
 */
class OidcSocialiteFactoryService
{
    /**
     * Private, loopback, link-local (cloud metadata), CGNAT and reserved ranges.
     */
    private const BLOCKED_CIDRS = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24',
        '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4', '::/128', '::1/128', 'fc00::/7', 'fe80::/10',
        'ff00::/8', '2001:db8::/32',
    ];

    public function __construct(private SettingsRepositoryService $settings) {}

    public function enabled(): bool
    {
        return $this->settings->isOidcLoginAvailable();
    }

    /**
     * @throws RuntimeException when SSO is off or discovery fails
     */
    public function driver(): AbstractProvider
    {
        if (! $this->enabled()) {
            throw new RuntimeException('OIDC is not configured.');
        }

        $discovery = $this->rewriteServerEndpoints($this->discover(rtrim((string) $this->settings->oidcIssuer(), '/')));
        $endpoints = [];

        foreach (['authorization_endpoint', 'token_endpoint', 'userinfo_endpoint'] as $key) {
            $endpoints[$key] = is_string($discovery[$key] ?? null) ? $discovery[$key] : '';

            if ($endpoints[$key] === '') {
                throw new RuntimeException('OIDC discovery missing required endpoints.');
            }
        }

        return new GenericOidcProvider(
            request(),
            (string) $this->settings->oidcClientId(),
            (string) $this->settings->oidcClientSecret(),
            route('auth.oidc.callback'),
            [],
            $endpoints['authorization_endpoint'],
            $endpoints['token_endpoint'],
            $endpoints['userinfo_endpoint'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function discover(string $issuer): array
    {
        $fetchBase = $this->dockerSetting('discovery_base') ?? $issuer;

        return Cache::remember('oidc.discovery.'.sha1($issuer.'|'.$fetchBase), now()->addHours(6), function () use ($fetchBase): array {
            $discoveryUrl = $fetchBase.'/.well-known/openid-configuration';

            if (! $this->isAllowedEndpoint($discoveryUrl)) {
                throw new RuntimeException('OIDC discovery URL is not allowed.');
            }

            $response = Http::timeout(10)->acceptJson()->withOptions(['allow_redirects' => false])->get($discoveryUrl);

            if (! $response->successful() || ! is_array($response->json())) {
                throw new RuntimeException('OIDC discovery request failed.');
            }

            /** @var array<string, mixed> $json */
            $json = $response->json();

            foreach (['token_endpoint', 'userinfo_endpoint', 'authorization_endpoint'] as $key) {
                if (is_string($json[$key] ?? null) && ! $this->isAllowedEndpoint($json[$key])) {
                    throw new RuntimeException('OIDC discovery returned a disallowed endpoint.');
                }
            }

            return $json;
        });
    }

    /**
     * Rewrites token and userinfo hosts for Docker (browser issuer ≠ container DNS).
     *
     * @param  array<string, mixed>  $discovery
     * @return array<string, mixed>
     */
    private function rewriteServerEndpoints(array $discovery): array
    {
        $origin = $this->dockerSetting('server_origin');

        if ($origin === null) {
            return $discovery;
        }

        foreach (['token_endpoint', 'userinfo_endpoint'] as $key) {
            if (is_string($discovery[$key] ?? null) && $discovery[$key] !== '') {
                $discovery[$key] = (string) preg_replace('#^https?://[^/]+#', $origin, $discovery[$key]);
            }
        }

        return $discovery;
    }

    /**
     * SSRF guard: http(s) only, no credentials, no private or metadata hosts unless
     * OIDC_ALLOW_PRIVATE_HOSTS is set. DNS is skipped in tests so Http::fake hosts work.
     */
    private function isAllowedEndpoint(string $url): bool
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])
            || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $host = trim(strtolower($parts['host']), '[]');

        if (in_array($host, ['metadata', 'metadata.google.internal'], true)) {
            return false;
        }

        if ((bool) config('services.oidc.allow_private_hosts', false)) {
            return true;
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) === false && app()->runningUnitTests()) {
            return true;
        }

        // ponytail: IPv4 resolution only (gethostbynamel); add AAAA lookups if an IPv6-only IdP shows up.
        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : (gethostbynamel($host) ?: []);

        return $ips !== [] && array_filter($ips, fn (string $ip): bool => IpUtils::checkIp($ip, self::BLOCKED_CIDRS)) === [];
    }

    /**
     * Docker-only override from config/services.php; ignored in tests so Http::fake works.
     */
    private function dockerSetting(string $key): ?string
    {
        if (app()->environment('testing')) {
            return null;
        }

        $value = config('services.oidc.'.$key);

        return is_string($value) && trim($value) !== '' ? rtrim(trim($value), '/') : null;
    }
}

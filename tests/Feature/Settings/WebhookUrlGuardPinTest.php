<?php

declare(strict_types=1);

use App\Jobs\SendAlertWebhooksJob;
use App\Services\AlertWebhookService;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Qiick\Outbound\Contracts\HostResolver;
use Qiick\Outbound\Models\WebhookDeliveryAttempt;
use Qiick\Outbound\Ssrf\RemoteUrlGuard;
use Qiick\Outbound\Ssrf\UnsafeUrl;

/**
 * What curl connects to for alert webhooks, and how the package guard knows it checked that address.
 *
 * Exercises {@see RemoteUrlGuard} and {@see PinnedHttpClient} from qiick/laravel-outbound: the
 * product copy of WebhookUrlGuardService is gone. `filter_var()` rejects `2852039166`,
 * `0251.0376.0251.0376` and `169.254.169.254.`, but curl and inet_aton(3) read each of them as
 * 169.254.169.254. CURLOPT_RESOLVE is a DNS-cache entry keyed by host name and is ignored for
 * hosts curl parses as an address; CURLOPT_CONNECT_TO with an empty source host pins whatever
 * spelling the URL uses.
 */
function pinHostResolver(array $addresses = ['93.184.216.34']): HostResolver
{
    return new class($addresses) implements HostResolver
    {
        /**
         * @param  list<string>  $addresses
         */
        public function __construct(private readonly array $addresses) {}

        public function resolve(string $host): array
        {
            return $this->addresses;
        }
    };
}

function pinGuard(array $addresses = ['93.184.216.34']): RemoteUrlGuard
{
    return new RemoteUrlGuard(pinHostResolver($addresses), httpsOnly: true);
}

function pinGuardThatMustNotResolve(): RemoteUrlGuard
{
    return new RemoteUrlGuard(
        new class implements HostResolver
        {
            public function resolve(string $host): array
            {
                throw new RuntimeException("Guard resolved an address literal as a name: {$host}");
            }
        },
        httpsOnly: true,
    );
}

/**
 * @return string|null English refusal when the URL must not be called; null when safe
 */
function pinGuardReason(RemoteUrlGuard $guard, string $url): ?string
{
    try {
        $guard->authorize($url);

        return null;
    } catch (UnsafeUrl $exception) {
        return $exception->reason();
    }
}

/**
 * @return array{ok: true, curl_connect_to: list<string>}|array{ok: false, reason: string}
 */
function pinAuthorize(RemoteUrlGuard $guard, string $url): array
{
    try {
        $destination = $guard->authorize($url);

        return ['ok' => true, 'curl_connect_to' => [$destination->curlConnectToEntry]];
    } catch (UnsafeUrl $exception) {
        return ['ok' => false, 'reason' => $exception->reason()];
    }
}

dataset('metadata ipv4 spellings', [
    'decimal' => 'https://2852039166/latest/meta-data/',
    'decimal with a port' => 'https://2852039166:443/latest/meta-data/',
    'dotted octal' => 'https://0251.0376.0251.0376/latest/meta-data/',
    'dotted hex' => 'https://0xa9.0xfe.0xa9.0xfe/latest/meta-data/',
    'dotted hex, upper case' => 'https://0XA9.0XFE.0XA9.0XFE/latest/meta-data/',
    'one hex number' => 'https://0xa9fea9fe/latest/meta-data/',
    'one octal number' => 'https://025177524776/latest/meta-data/',
    'mixed bases' => 'https://169.0376.0xa9.254/latest/meta-data/',
    'three parts' => 'https://169.254.43518/latest/meta-data/',
    'two parts' => 'https://169.16689662/latest/meta-data/',
    'trailing dot' => 'https://169.254.169.254./latest/meta-data/',
    'decimal with a trailing dot' => 'https://2852039166./latest/meta-data/',
]);

dataset('non canonical ipv4 spellings', [
    'short form, link local' => 'https://169.254.65534/hook',
    'short form, public' => 'https://1.1/hook',
    'decimal loopback' => 'https://2130706433/hook',
    'hex loopback, two parts' => 'https://0x7f.1/hook',
    'short loopback' => 'https://127.1/hook',
    'octal private' => 'https://012.0.0.1/hook',
    'leading zeroes' => 'https://127.000.000.001/hook',
    'decimal public' => 'https://1572395042/hook',
    'canonical public with a trailing dot' => 'https://93.184.216.34./hook',
]);

/**
 * The two metadata endpoints that are not link-local: Alibaba Cloud (100.100.100.200) and
 * Oracle Cloud (192.0.0.192). A metadata address must be refused as metadata in every form the
 * guard reads, not merely as a malformed URL: the reason is part of the guard's contract with
 * the operator debugging a blocked webhook.
 */
dataset('alibaba and oracle metadata spellings', [
    'alibaba canonical' => 'https://100.100.100.200/latest/meta-data/',
    'alibaba decimal' => 'https://1684301000/latest/meta-data/',
    'alibaba dotted octal' => 'https://0144.0144.0144.0310/latest/meta-data/',
    'alibaba dotted hex' => 'https://0x64.0x64.0x64.0xc8/latest/meta-data/',
    'alibaba one hex number' => 'https://0x646464c8/latest/meta-data/',
    'alibaba one octal number' => 'https://014431062310/latest/meta-data/',
    'alibaba three parts' => 'https://100.100.25800/latest/meta-data/',
    'alibaba two parts' => 'https://100.6579400/latest/meta-data/',
    'alibaba trailing dot' => 'https://100.100.100.200./latest/meta-data/',
    'alibaba decimal trailing dot' => 'https://1684301000./latest/meta-data/',
    'alibaba ipv4-mapped ipv6, dotted' => 'https://[::ffff:100.100.100.200]/latest/meta-data/',
    'alibaba ipv4-mapped ipv6, hex' => 'https://[::ffff:6464:64c8]/latest/meta-data/',
    'oracle canonical' => 'https://192.0.0.192/latest/meta-data/',
    'oracle decimal' => 'https://3221225664/latest/meta-data/',
    'oracle dotted octal' => 'https://0300.0.0.0300/latest/meta-data/',
    'oracle dotted hex' => 'https://0xc0.0.0.0xc0/latest/meta-data/',
    'oracle one hex number' => 'https://0xc00000c0/latest/meta-data/',
    'oracle one octal number' => 'https://030000000300/latest/meta-data/',
    'oracle three parts' => 'https://192.0.192/latest/meta-data/',
    'oracle two parts' => 'https://192.192/latest/meta-data/',
    'oracle trailing dot' => 'https://192.0.0.192./latest/meta-data/',
    'oracle decimal trailing dot' => 'https://3221225664./latest/meta-data/',
    'oracle ipv4-mapped ipv6, dotted' => 'https://[::ffff:192.0.0.192]/latest/meta-data/',
    'oracle ipv4-mapped ipv6, hex' => 'https://[::ffff:c000:c0]/latest/meta-data/',
]);

it('refuses every spelling of the ipv4 metadata address', function (string $url): void {
    expect(pinGuardReason(pinGuard(), $url))->toBe('Host is not allowed.');
})->with('metadata ipv4 spellings');

it('reads an ipv4 spelling as the address it is, and never looks it up as a name', function (string $url): void {
    expect(pinGuardReason(pinGuardThatMustNotResolve(), $url))->not->toBeNull();
})->with('metadata ipv4 spellings');

it('refuses an ipv4 address that is not written in dotted decimal', function (string $url): void {
    expect(pinGuardReason(pinGuardThatMustNotResolve(), $url))->toBe('URL is invalid.');
})->with('non canonical ipv4 spellings');

it('refuses a hostname that resolves to the metadata address', function (): void {
    expect(pinGuardReason(pinGuard(['169.254.169.254']), 'https://hooks.example.com/hook'))
        ->toBe('Host resolves to a disallowed address.')
        ->and(pinGuardReason(pinGuard(['fd00:ec2::254']), 'https://hooks.example.com/hook'))
        ->toBe('Host resolves to a disallowed address.');
});

it('refuses alibaba and oracle metadata addresses in every spelling the guard canonicalises', function (string $url): void {
    expect(pinGuardReason(pinGuard(), $url))->toBe('Host is not allowed.');
})->with('alibaba and oracle metadata spellings');

it('refuses a hostname that resolves to an alibaba or oracle metadata address', function (): void {
    expect(pinGuardReason(pinGuard(['100.100.100.200']), 'https://hooks.example.com/hook'))
        ->toBe('Host resolves to a disallowed address.')
        ->and(pinGuardReason(pinGuard(['192.0.0.192']), 'https://hooks.example.com/hook'))
        ->toBe('Host resolves to a disallowed address.');
});

it('keeps the metadata names blocked with a trailing dot', function (): void {
    expect(pinGuardReason(pinGuard(), 'https://metadata./hook'))->toBe('Host is not allowed.')
        ->and(pinGuardReason(pinGuard(), 'https://metadata.google.internal./hook'))->toBe('Host is not allowed.');
});

it('keeps the blocked names blocked with a trailing dot', function (): void {
    expect(pinGuardReason(pinGuard(['127.0.0.1']), 'https://localhost./hook'))->toBe('Host is not allowed.')
        ->and(pinGuardReason(pinGuard(['10.0.0.1']), 'https://redis.internal./hook'))->toBe('Host is not allowed.')
        ->and(pinGuardReason(pinGuard(['10.0.0.1']), 'https://printer.local./hook'))->toBe('Host is not allowed.');
});

it('refuses a host that does not resolve, because there is nothing to pin', function (): void {
    expect(pinGuardReason(pinGuard([]), 'https://nxdomain.example.com/hook'))->toBe('Host could not be resolved.');
});

it('never pins a resolver answer that is not an address', function (): void {
    expect(pinGuardReason(pinGuard(['metadata.google.internal']), 'https://cname.example.com/hook'))
        ->toBe('Host could not be resolved.')
        ->and(pinAuthorize(pinGuard(['metadata.google.internal', '93.184.216.34']), 'https://cname.example.com/hook'))
        ->toBe(['ok' => true, 'curl_connect_to' => ['::93.184.216.34:443']]);
});

it('pins every destination it authorises to exactly one checked address', function (string $url, array $addresses, string $pin): void {
    expect(pinAuthorize(pinGuard($addresses), $url))->toBe([
        'ok' => true,
        'curl_connect_to' => [$pin],
    ]);
})->with([
    'public hostname' => ['https://hooks.example.com/hook', ['93.184.216.34'], '::93.184.216.34:443'],
    'custom port' => ['https://hooks.example.com:8443/hook', ['93.184.216.34'], '::93.184.216.34:8443'],
    'fqdn trailing dot' => ['https://hooks.example.com./hook', ['93.184.216.34'], '::93.184.216.34:443'],
    'public ipv4 literal' => ['https://93.184.216.34/hook', [], '::93.184.216.34:443'],
    'public ipv6 literal' => ['https://[2606:2800:220:1:248:1893:25c8:1946]/hook', [], '::[2606:2800:220:1:248:1893:25c8:1946]:443'],
]);

it('checks every record of a hostname, and pins one of them, ipv4 first', function (): void {
    $addresses = ['2606:2800:220:1:248:1893:25c8:1946', '93.184.216.34', '93.184.216.35'];

    expect(pinAuthorize(pinGuard($addresses), 'https://many.example.com/hook'))->toBe([
        'ok' => true,
        'curl_connect_to' => ['::93.184.216.34:443'],
    ])
        ->and(pinGuardReason(pinGuard([...$addresses, '10.0.0.5']), 'https://many.example.com/hook'))
        ->toBe('Host resolves to a disallowed address.');
});

it('still treats a name that only looks numeric as a name', function (string $host): void {
    expect(pinAuthorize(pinGuard(['93.184.216.34']), "https://{$host}/hook"))->toBe([
        'ok' => true,
        'curl_connect_to' => ['::93.184.216.34:443'],
    ]);
})->with([
    '1.2.3.4.5',
    '256.1.1.1',
    '4294967296',
    '1.16777216',
    '123456789012',
    'cafe.1.2.3',
    'deadbeef.example.com',
]);

it('refuses a hostname with empty labels as an invalid URL, without resolving DNS', function (string $host): void {
    expect(pinGuardReason(pinGuardThatMustNotResolve(), "https://{$host}/hook"))->toBe('URL is invalid.');
})->with([
    '1..2',
    '.example.com',
    'example..com',
]);

it('resolves once per delivery and sends the request to the address it checked', function (): void {
    $resolver = new class implements HostResolver
    {
        public int $lookups = 0;

        public function resolve(string $host): array
        {
            $this->lookups++;

            return $this->lookups === 1 ? ['93.184.216.34'] : ['169.254.169.254'];
        }
    };
    $guard = new RemoteUrlGuard($resolver, httpsOnly: true);

    $sentOptions = null;
    bindPinnedAlertClient($guard, function (Request $request, array $options) use (&$sentOptions): PromiseInterface {
        $sentOptions = $options;

        return Http::response('', 204);
    });

    configureAlertWebhook('https://rebind.example.com/hook');

    app(AlertWebhookService::class)->send(['id' => (string) Str::uuid(), 'event' => 'ping']);
    app()->call([new SendAlertWebhooksJob, 'handle']);

    expect($resolver->lookups)->toBe(1)
        ->and($sentOptions['curl'] ?? null)->toBe([CURLOPT_CONNECT_TO => ['::93.184.216.34:443']]);

    bindPinnedAlertClient($guard, static fn (): PromiseInterface => Http::response('', 204));

    app(AlertWebhookService::class)->send(['id' => (string) Str::uuid(), 'event' => 'ping']);
    app()->call([new SendAlertWebhooksJob, 'handle']);

    expect($resolver->lookups)->toBe(2)
        ->and(WebhookDeliveryAttempt::query()->latest('id')->firstOrFail()->error_code)->toBe('ssrf_refused');
});

it('pins a literal address in the url too, since curl reads more spellings than the guard', function (): void {
    $sentOptions = null;
    bindPinnedAlertClient(pinGuardThatMustNotResolve(), function (Request $request, array $options) use (&$sentOptions): PromiseInterface {
        $sentOptions = $options;

        return Http::response('', 204);
    });

    configureAlertWebhook('https://93.184.216.34/hook');

    app(AlertWebhookService::class)->send(['id' => (string) Str::uuid(), 'event' => 'ping']);
    app()->call([new SendAlertWebhooksJob, 'handle']);

    expect($sentOptions['curl'] ?? null)->toBe([CURLOPT_CONNECT_TO => ['::93.184.216.34:443']]);
});

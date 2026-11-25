<?php

use App\Actions\IssueAlertWebhookSecretAction;
use App\Actions\SaveAlertWebhookAction;
use App\Models\User;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Qiick\Outbound\Delivery\WebhookDeliveryCoordinator;
use Qiick\Outbound\Http\OutboundHttpLimits;
use Qiick\Outbound\Http\PinnedHttpClient;
use Qiick\Outbound\Models\WebhookDelivery;
use Qiick\Outbound\Ssrf\RemoteUrlGuard;
use Qiick\Outbound\Testing\FakeOutboundHttpFactory;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// Browser tests (#112) run outside the default suite: `vendor/bin/pest tests/Browser`.
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Browser');

pest()->browser()->timeout(10_000);

// The installer rewrites .env and switches the database connection itself, so its tests boot
// the app without RefreshDatabase (as in externa-core) and use their own SQLite file.
pest()->extend(TestCase::class)
    ->in('Unit/Console');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/**
 * Strict comparison of a decoded JSON column that ignores object key order: MySQL stores JSON
 * with its keys reordered (#136). List order and value types still have to match.
 */
expect()->extend('toBeSameJson', function (array $expected) {
    $sortKeys = function (mixed $value) use (&$sortKeys): mixed {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map($sortKeys, $value);
    };

    return expect($sortKeys($this->value))->toBe($sortKeys($expected));
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * A valid Standard Webhooks secret (`whsec_` + base64 of 32 raw bytes) for alert tests.
 */
function alertWebhookSecret(string $byte = 's'): string
{
    return 'whsec_'.base64_encode(str_repeat($byte, 32));
}

/**
 * Configures the alert webhook on qiick/laravel-outbound as Settings → Integrations does: the
 * instance endpoint for `$url` and a generated signing secret, which is returned.
 */
function configureAlertWebhook(string $url = 'https://93.184.216.34/excubia'): string
{
    $actor = User::factory()->create();
    app(SaveAlertWebhookAction::class)->execute($url, $actor);

    return app(IssueAlertWebhookSecretAction::class)->execute($actor)['secret'] ?? '';
}

/**
 * The alert types stored for the webhook, oldest first (one qiick/laravel-outbound delivery each).
 *
 * @return list<string>
 */
function alertWebhookEvents(): array
{
    return WebhookDelivery::query()->orderBy('id')->pluck('event_type')->all();
}

/**
 * Bind a {@see PinnedHttpClient} over a faked private factory so
 * {@see Http::fake()} on the shared facade cannot bypass the pin.
 *
 * @param  (callable(Request, array): PromiseInterface)|null  $callback
 */
function bindPinnedAlertClient(RemoteUrlGuard $guard, ?callable $callback = null): void
{
    $factory = FakeOutboundHttpFactory::fake($callback);
    app()->instance(RemoteUrlGuard::class, $guard);
    app()->instance(PinnedHttpClient::class, new PinnedHttpClient(
        $factory,
        $guard,
        app(OutboundHttpLimits::class),
    ));
    // The webhook coordinator keeps the client it was built with: rebuild it over the fake one.
    app()->forgetInstance(WebhookDeliveryCoordinator::class);
}

/**
 * Errors of a value against a (sub)schema of a core schema (runtime, report); [] when it matches.
 * `$ref` to `#/$defs/…` is followed; `if` / `then` / `oneOf` are not: check those parts directly.
 *
 * @param  array<string, mixed>  $schema
 * @param  array<string, mixed>  $root
 * @return list<string>
 */
function coreSchemaErrors(mixed $value, array $schema, array $root, string $path = '$'): array
{
    if (isset($schema['$ref'])) {
        // A JSON pointer into the root, e.g. `#/$defs/server/properties/server`.
        $target = $root;

        foreach (explode('/', substr($schema['$ref'], 2)) as $segment) {
            $target = $target[$segment];
        }

        $schema = [...$target, ...array_diff_key($schema, ['$ref' => true])];
    }

    $errors = [];
    $types = (array) ($schema['type'] ?? []);
    $actual = match (true) {
        // An empty PHP array is an empty list or an empty object, whichever the schema wants.
        $value === [] => in_array('array', $types, true) ? 'array' : 'object',
        is_array($value) && array_is_list($value) => 'array',
        is_array($value) => 'object',
        is_int($value) => 'integer',
        is_float($value) => 'number',
        is_bool($value) => 'boolean',
        is_null($value) => 'null',
        default => 'string',
    };

    if ($types !== [] && ! in_array($actual, $types, true) && ! ($actual === 'integer' && in_array('number', $types, true))) {
        return ["{$path}: {$actual} is not ".implode('|', $types)];
    }

    if (array_key_exists('const', $schema) && $value !== $schema['const']) {
        $errors[] = "{$path}: not the const";
    }

    if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
        $errors[] = "{$path}: not in the enum";
    }

    if (is_string($value)) {
        if (isset($schema['pattern']) && preg_match('/'.str_replace('/', '\/', $schema['pattern']).'/u', $value) !== 1) {
            $errors[] = "{$path}: does not match {$schema['pattern']}";
        }

        if (mb_strlen($value) < ($schema['minLength'] ?? 0) || mb_strlen($value) > ($schema['maxLength'] ?? PHP_INT_MAX)) {
            $errors[] = "{$path}: length out of range";
        }
    }

    if ((is_int($value) || is_float($value)) && ($value < ($schema['minimum'] ?? -INF) || $value > ($schema['maximum'] ?? INF))) {
        $errors[] = "{$path}: out of range";
    }

    if ($actual === 'array') {
        if (count($value) < ($schema['minItems'] ?? 0) || count($value) > ($schema['maxItems'] ?? PHP_INT_MAX)) {
            $errors[] = "{$path}: item count out of range";
        }

        foreach ($value as $index => $item) {
            $errors = [...$errors, ...coreSchemaErrors($item, $schema['items'] ?? [], $root, "{$path}[{$index}]")];
        }
    }

    if ($actual === 'object') {
        foreach (array_diff($schema['required'] ?? [], array_keys($value)) as $missing) {
            $errors[] = "{$path}: missing {$missing}";
        }

        foreach ($value as $key => $item) {
            if (! isset($schema['properties'][$key])) {
                if (($schema['additionalProperties'] ?? true) === false) {
                    $errors[] = "{$path}: unexpected {$key}";
                }

                continue;
            }

            $errors = [...$errors, ...coreSchemaErrors($item, $schema['properties'][$key], $root, "{$path}.{$key}")];
        }
    }

    return $errors;
}

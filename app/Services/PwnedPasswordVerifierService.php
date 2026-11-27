<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\NotPwnedVerifier;

/** Bounds the breach lookup while Laravel handles hashing and comparing the returned suffixes. */
final class PwnedPasswordVerifierService extends NotPwnedVerifier
{
    public function __construct(Factory $factory)
    {
        parent::__construct($factory, 3);
    }

    /**
     * Sends a padded range request to the fixed provider. An outage permits the password and
     * emits a warning with the HTTP status only, since the request URL contains the hash prefix.
     *
     * @param  string  $hashPrefix
     * @return Collection<int, non-falsy-string>
     */
    protected function search($hashPrefix): Collection
    {
        try {
            $response = $this->factory->withHeader('Add-Padding', 'true')
                ->withoutRedirecting()
                ->connectTimeout($this->timeout)
                ->timeout($this->timeout)
                ->get('https://api.pwnedpasswords.com/range/'.$hashPrefix);
        } catch (ConnectionException) {
            $response = null;
        }

        if ($response === null || ! $response->ok()) {
            Log::warning('Pwned Passwords lookup unavailable.', ['http_status' => $response?->status()]);

            return collect();
        }

        return str($response->body())->trim()->explode("\n")->filter(fn (string $line): bool => str_contains($line, ':'));
    }
}

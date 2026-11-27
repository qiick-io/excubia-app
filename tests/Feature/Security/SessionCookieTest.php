<?php

declare(strict_types=1);

/**
 * Security review (#51): the session cookie is Secure by default on an https install, and
 * SESSION_SECURE_COOKIE still overrides it.
 */
function sessionCookieConfig(array $env): array
{
    $previous = [$_ENV, $_SERVER];

    foreach ($env as $key => $value) {
        unset($_ENV[$key], $_SERVER[$key]);

        if ($value !== null) {
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
    }

    try {
        return require config_path('session.php');
    } finally {
        [$_ENV, $_SERVER] = $previous;
    }
}

it('marks the session cookie Secure when APP_URL is https', function (?string $appUrl, ?string $override, bool $isSecure) {
    expect((bool) sessionCookieConfig(['APP_URL' => $appUrl, 'SESSION_SECURE_COOKIE' => $override])['secure'])->toBe($isSecure);
})->with([
    'https install' => ['https://excubia.example.com', null, true],
    'plain http (first run on localhost)' => ['http://localhost:8080', null, false],
    'explicit off' => ['https://excubia.example.com', 'false', false],
    'explicit on' => ['http://localhost:8080', 'true', true],
]);

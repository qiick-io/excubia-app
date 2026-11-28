<?php

declare(strict_types=1);

namespace App\Services\Upgrade;

/**
 * The fingerprint of the release's configuration sources: every `config/*.php` file and
 * `composer.lock` (the packages' own defaults). `config/excubia.php` stores it, so a cached
 * configuration carries the fingerprint of the release that cached it, and `excubia:upgrade`
 * tells a cache built by this release's build step (Laravel Cloud, Docker) from one left by the
 * previous release, by content, never by file times.
 */
class ConfigFingerprintService
{
    public static function current(): string
    {
        $sources = glob(base_path('config/*.php')) ?: [];
        sort($sources);
        $sources[] = base_path('composer.lock');
        $context = hash_init('sha256');

        foreach ($sources as $source) {
            if (is_file($source)) {
                hash_update($context, basename($source)."\0");
                hash_update_file($context, $source);
            }
        }

        return hash_final($context);
    }
}

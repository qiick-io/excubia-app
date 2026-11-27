<?php

declare(strict_types=1);

namespace App\Services;

use Qiick\HttpBasics\Contracts\DefaultLocaleResolver;
use Qiick\HttpBasics\Contracts\SupportedLocales;

/**
 * Excubia's locales for qiick/laravel-http-basics' locale negotiation: the locales of
 * `config/i18n.php` (the language switcher's), and the default language of Settings →
 * Localization. The package then picks, on web pages, the user's locale, else the `locale`
 * cookie, else this default; on the API and MCP, Accept-Language first.
 */
class LocalePreferencesService implements DefaultLocaleResolver, SupportedLocales
{
    public function __construct(private SettingsRepositoryService $settings) {}

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return array_map(strval(...), array_keys((array) config('i18n.available_locales', ['en' => 'English'])));
    }

    public function resolve(): string
    {
        return $this->settings->defaultLanguage();
    }
}

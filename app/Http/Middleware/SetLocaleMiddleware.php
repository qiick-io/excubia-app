<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\SettingsRepositoryService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve the active UI locale from the user, cookie, project default, or app default.
 */
class SetLocaleMiddleware
{
    /**
     * Set App locale for the request (UI + Laravel validation/auth messages).
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale($this->resolve($request));

        return $next($request);
    }

    /**
     * Preference order: authenticated user → cookie → project default → config default.
     */
    private function resolve(Request $request): string
    {
        $userLocale = $request->user()?->locale;

        if (is_string($userLocale) && self::isSupported($userLocale)) {
            return $userLocale;
        }

        return self::cookieLocale($request) ?? app(SettingsRepositoryService::class)->defaultLanguage();
    }

    /** The plain preference cookie can also be read outside the routed web middleware. */
    public static function cookieLocale(Request $request): ?string
    {
        $cookieLocale = $request->cookie('locale');

        return is_string($cookieLocale) && self::isSupported($cookieLocale) ? $cookieLocale : null;
    }

    private static function isSupported(string $locale): bool
    {
        return array_key_exists($locale, (array) config('i18n.available_locales', ['en' => 'English']));
    }
}

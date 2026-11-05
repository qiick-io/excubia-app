<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\SettingsRepositoryService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves the uploaded logo, dark logo and favicon (Settings → Appearance). Public: the login
 * page and the favicon need them before sign-in. The URL carries the file name, so browsers
 * may cache it for a long time. Uploads are limited to images by UpdateAppearanceSettingsRequest.
 */
class BrandingController extends Controller
{
    public function show(string $asset, SettingsRepositoryService $settings): StreamedResponse
    {
        $path = $settings->all()[SettingsRepositoryService::BRANDING_ASSETS[$asset]] ?? null;

        abort_unless(is_string($path) && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
            // An uploaded SVG opened on its own runs no script.
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ]);
    }
}

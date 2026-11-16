<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\SaveBrandingFilesAction;
use App\Services\SettingsRepositoryService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves the uploaded logo, dark logo and favicon (Settings → Appearance). Public: the login
 * page and the favicon need them before sign-in. The URL carries the file name, so browsers
 * may cache it for a long time. Uploads are limited to images by UpdateAppearanceSettingsRequest.
 * The file is streamed from the files disk (#312), so a private bucket works: no bucket URL
 * ever reaches the browser. The type follows the stored extension, never the disk's guess.
 */
class BrandingController extends Controller
{
    private const array CONTENT_TYPES = [...ProjectLogoController::CONTENT_TYPES, 'ico' => 'image/x-icon'];

    public function show(string $asset, SettingsRepositoryService $settings): StreamedResponse
    {
        $path = $settings->all()[SettingsRepositoryService::BRANDING_ASSETS[$asset]] ?? null;
        $contentType = self::CONTENT_TYPES[pathinfo(is_string($path) ? $path : '', PATHINFO_EXTENSION)] ?? null;
        $disk = SaveBrandingFilesAction::disk();

        abort_unless(is_string($path) && $contentType !== null && $disk->exists($path), 404);

        return $disk->response($path, null, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
            // An uploaded SVG opened on its own runs no script.
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ]);
    }
}

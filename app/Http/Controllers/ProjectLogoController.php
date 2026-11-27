<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\SaveProjectLogoAction;
use App\Models\Project;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a project's uploaded logo (#287) to the people who may see the project. The type follows
 * the stored extension, and the CSP sandbox means an SVG opened on its own runs
 * no script even if the sanitiser missed something. The file is streamed from the files disk
 * (#312), so a private bucket works: no bucket URL ever reaches the browser.
 */
class ProjectLogoController extends Controller
{
    /** Image types by stored extension; BrandingController serves the same types plus `ico`. */
    public const array CONTENT_TYPES = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
    ];

    public function show(Project $project): StreamedResponse
    {
        Gate::authorize('view', $project);

        $path = $project->logo_path;
        $contentType = self::CONTENT_TYPES[pathinfo((string) $path, PATHINFO_EXTENSION)] ?? null;
        $disk = SaveProjectLogoAction::disk();
        abort_unless($path !== null && $contentType !== null && $disk->exists($path), 404);

        return $disk->response($path, null, [
            'Content-Type' => $contentType,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ]);
    }
}

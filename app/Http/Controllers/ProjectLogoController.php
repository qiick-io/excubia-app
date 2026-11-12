<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\SaveProjectLogoAction;
use App\Models\Project;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a project's uploaded logo (#287) to the people who may see the project. The URL
 * carries a version (Project::logoUrl()), so browsers keep it for a long time. The type follows
 * the stored extension, never a guess, and the CSP sandbox means an SVG opened on its own runs
 * no script even if the sanitiser missed something.
 */
class ProjectLogoController extends Controller
{
    private const array CONTENT_TYPES = [
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
        abort_unless($path !== null && $contentType !== null && Storage::disk(SaveProjectLogoAction::DISK)->exists($path), 404);

        return Storage::disk(SaveProjectLogoAction::DISK)->response($path, null, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ]);
    }
}

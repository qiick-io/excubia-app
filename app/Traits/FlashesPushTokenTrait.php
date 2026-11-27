<?php

declare(strict_types=1);

namespace App\Traits;

use App\Models\Project;
use Inertia\Inertia;

/**
 * Flashes a project's new push token for one response only, with the URL the project pushes
 * to. The plain token is never stored: this flash is the only time the user sees it.
 */
trait FlashesPushTokenTrait
{
    private function flashToken(Project $project, string $token): void
    {
        Inertia::flash('pushToken', [
            'project' => $project->name,
            'token' => $token,
            'url' => url('/'),
        ]);
    }
}

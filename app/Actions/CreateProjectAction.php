<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Project;

/**
 * Registers a project. The push token is issued separately with RotateProjectTokenAction.
 */
class CreateProjectAction
{
    public function execute(string $name, string $slug, bool $isEnabled): Project
    {
        return Project::query()->create(['name' => $name, 'slug' => $slug, 'is_enabled' => $isEnabled]);
    }
}

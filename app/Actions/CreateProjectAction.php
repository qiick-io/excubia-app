<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AlertEventEnum;
use App\Models\Project;
use App\Services\AlertDispatcherService;

/**
 * Registers a project and raises `project.created` (#22). The push token is issued separately
 * with RotateProjectTokenAction.
 */
class CreateProjectAction
{
    public function __construct(private AlertDispatcherService $alerts) {}

    public function execute(string $name, string $slug, bool $isEnabled): Project
    {
        $project = Project::query()->create(['name' => $name, 'slug' => $slug, 'is_enabled' => $isEnabled]);
        $this->alerts->dispatch(AlertEventEnum::ProjectCreated, $project, 'project created');

        return $project;
    }
}

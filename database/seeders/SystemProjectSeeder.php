<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Project;
use Illuminate\Database\Seeder;

/**
 * The system project (#70): Excubia watching itself, so a fresh install is not empty. Visible to
 * everyone with `projects.view` until an admin restricts it. Idempotent.
 */
class SystemProjectSeeder extends Seeder
{
    public function run(): void
    {
        if (Project::query()->where('is_system', true)->exists()) {
            return;
        }

        $slug = Project::query()->where('slug', 'excubia')->exists() ? 'excubia-system' : 'excubia';

        Project::query()->create([
            'name' => 'Excubia',
            'slug' => $slug,
            'is_enabled' => true,
            'is_system' => true,
        ]);
    }
}

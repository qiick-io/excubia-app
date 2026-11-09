<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Project access by scope (#253): the members of a user group also see the projects of the
 * project groups linked to it, admin-only ones included. The row has its own `id` so a nullable
 * `role_id` ("admin on X, read-only on Y") can come later in an additive migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_group_project_group', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_group_id')->constrained('user_groups')->cascadeOnDelete();
            $table->foreignId('project_group_id')->constrained('project_groups')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_group_id', 'project_group_id']);
            $table->index('project_group_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_group_project_group');
    }
};

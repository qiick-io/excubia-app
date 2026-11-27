<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generic project groups (#252): a client, a team, a product line or an environment. A project
 * may belong to several groups. Project access by group (#253) builds on these tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('project_group_project', function (Blueprint $table): void {
            $table->foreignId('project_group_id')->constrained('project_groups')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['project_group_id', 'project_id']);
            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_group_project');
        Schema::dropIfExists('project_groups');
    }
};

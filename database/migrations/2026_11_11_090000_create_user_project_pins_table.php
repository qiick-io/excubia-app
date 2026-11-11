<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Projects a user pinned to the sidebar (#273, app-ui optional pattern `sidebar-entities`), in
 * the order they pinned them. Deleting the user or the project removes the pin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_project_pins', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'project_id']);
            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_project_pins');
    }
};

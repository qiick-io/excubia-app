<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * User groups carry roles; members inherit them (EffectivePermissionResolverService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('user_group_user', function (Blueprint $table): void {
            $table->foreignId('user_group_id')->constrained('user_groups')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_group_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('user_group_role', function (Blueprint $table): void {
            $table->foreignId('user_group_id')->constrained('user_groups')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained(config('permission.table_names.roles', 'roles'))->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_group_id', 'role_id']);
            $table->index('role_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_group_role');
        Schema::dropIfExists('user_group_user');
        Schema::dropIfExists('user_groups');
    }
};

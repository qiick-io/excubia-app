<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fleet principals: actors that can own API keys (a person or, later, a service account).
 *
 * Every user gets one `user` principal. `organisation_id` is a string(36) with no FK yet
 * (Excubia has no organisations table).
 *
 * @see knowledge/decisions/2026-11-19-fleet-core-schema
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('principals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type', 32);
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->cascadeOnDelete();
            $table->string('organisation_id', 36)->nullable()->index();
            $table->string('name');
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('principals');
    }
};

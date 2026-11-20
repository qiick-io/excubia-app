<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Activity log table of spatie/laravel-activitylog v5, as in qiick-io/externa-core /
 * signaculum-core.
 *
 * Subject and causer ids are strings, not `nullableMorphs()` (bigint): future UUID /
 * ULID subjects fit, and bigint user ids still cast cleanly. 64 characters hold a UUID
 * with room for a ULID. Index names stay `subject` / `causer`. Every row may name an
 * organisation perimeter (nullable; Excubia has none yet).
 *
 * @see knowledge/decisions/2026-11-19-fleet-core-schema
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $table): void {
            $table->id();
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->string('subject_type')->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->index(['subject_type', 'subject_id'], 'subject');
            $table->string('event')->nullable();
            $table->string('causer_type')->nullable();
            $table->string('causer_id', 64)->nullable();
            $table->index(['causer_type', 'causer_id'], 'causer');
            $table->string('organisation_id', 36)->nullable()->index();
            $table->json('attribute_changes')->nullable();
            $table->json('properties')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};

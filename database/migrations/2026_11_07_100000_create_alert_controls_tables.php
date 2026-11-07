<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alert controls (#114).
 * - `alert_mutes`: an event of a project (a rule) or one subject of it (an issue: a finding, a
 *   scheduled task, a cache store…) muted until a time, or forever when `until` is null.
 * - `maintenance_windows`: one-off (`starts_at` to `ends_at`, UTC) or weekly (`weekdays`, ISO
 *   1–7, from `start_time` for `duration_minutes`, in `timezone`) periods that suppress alerts.
 * - `alert_logs`: every alert the dispatcher saw, with its status (queued, sent, failed,
 *   suppressed, skipped), the suppression reason and the outcome per channel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_mutes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('event', 64);
            // Null mutes the whole event (rule) of the project.
            $table->string('subject')->nullable();
            $table->string('label')->nullable();
            // Null mutes forever.
            $table->timestamp('until')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['project_id', 'event']);
        });

        Schema::create('maintenance_windows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('recurrence', 16);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->json('weekdays')->nullable();
            $table->string('start_time', 5)->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->string('timezone', 64);
            $table->timestamps();
        });

        Schema::create('alert_logs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('event', 64);
            $table->string('severity', 16);
            $table->string('subject')->nullable();
            $table->string('summary', 500);
            $table->string('status', 16);
            $table->string('reason', 32)->nullable();
            // Channel → queued | sent | failed.
            $table->json('deliveries');
            $table->timestamps();

            $table->index(['project_id', 'created_at']);
            $table->index(['project_id', 'event', 'subject', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_logs');
        Schema::dropIfExists('maintenance_windows');
        Schema::dropIfExists('alert_mutes');
    }
};

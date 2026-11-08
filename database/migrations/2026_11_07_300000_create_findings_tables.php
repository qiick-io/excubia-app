<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finding lifecycle across reports (#18). One `findings` row per project and core `fingerprint`
 * (sha256 of check id, subject and advisory id): when it was first and last seen, whether a later
 * report resolved it, how often it came back, and an optional dismissal with reason and expiry
 * (the fields of an `excubia.json` ignore rule). `finding` keeps the row of the last report that
 * had it, so a resolved finding still reads well. `finding_events` is the per-finding history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('findings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->char('fingerprint', 64);
            $table->string('check_id', 100);
            $table->string('severity', 16);
            // open or resolved (FindingStatusEnum).
            $table->string('status', 16);
            $table->json('finding');
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedInteger('reopened_count')->default(0);
            $table->timestamp('dismissed_at')->nullable();
            // Inclusive, as the `until` of an excubia.json ignore rule.
            $table->date('dismissed_until')->nullable();
            $table->string('dismissed_reason', 500)->nullable();
            $table->foreignId('dismissed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['project_id', 'fingerprint']);
            $table->index(['project_id', 'status']);
            $table->index('dismissed_by_user_id');
        });

        Schema::create('finding_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('finding_id')->constrained()->cascadeOnDelete();
            // opened, resolved, reopened, dismissed or undismissed (FindingEventEnum).
            $table->string('event', 16);
            // The report that opened, resolved or reopened it; null for dismissals and pruned reports.
            $table->foreignId('health_report_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason', 500)->nullable();
            $table->date('until')->nullable();
            $table->timestamp('occurred_at');

            $table->index(['finding_id', 'occurred_at']);
            $table->index(['event', 'occurred_at']);
            // The report prune nulls these out; user deletion too.
            $table->index('health_report_id');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finding_events');
        Schema::dropIfExists('findings');
    }
};

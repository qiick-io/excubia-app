<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schedule monitoring (#110). `scheduled_tasks` is the schedule inventory of each project, from
 * the `schedule` field of its latest report push, with the status the evaluation last gave each
 * task. `scheduled_task_runs` holds the runs of each task per minute bucket and outcome, from
 * the runtime `scheduledTask` aggregates that carry a `taskId`; counts add up across batches.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->char('task_id', 64);
            $table->string('task');
            $table->string('expression', 100);
            $table->string('timezone', 64);
            $table->boolean('is_without_overlapping')->default(false);
            $table->boolean('is_on_one_server')->default(false);
            $table->boolean('can_run_in_maintenance_mode')->default(false);
            $table->json('environments');
            $table->unsignedSmallInteger('late_after_minutes')->default(5);
            // Schedule order of the inventory.
            $table->unsignedInteger('position')->default(0);
            // Written by excubia:evaluate-schedules.
            $table->string('status', 16)->default('pending');
            $table->boolean('is_low_confidence')->default(false);
            $table->timestamp('last_due_at')->nullable();
            $table->timestamp('next_due_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->string('last_outcome', 16)->nullable();
            $table->timestamp('status_changed_at')->nullable();
            // The problem status already alerted (#22), cleared when the task is fine again.
            $table->string('alerted_status', 16)->nullable();
            $table->timestamp('evaluated_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'task_id']);
        });

        Schema::create('scheduled_task_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->char('task_id', 64);
            $table->timestamp('bucket');
            $table->string('outcome', 16);
            $table->unsignedBigInteger('count');
            // The lowest sample rate of the aggregates merged into this row.
            $table->decimal('sample_rate', 5, 4);
            $table->timestamps();

            $table->unique(['project_id', 'task_id', 'bucket', 'outcome']);
            $table->index(['project_id', 'bucket']);
            $table->index('bucket');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_task_runs');
        Schema::dropIfExists('scheduled_tasks');
    }
};

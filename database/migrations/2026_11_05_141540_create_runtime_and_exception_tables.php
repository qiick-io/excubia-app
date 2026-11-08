<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Runtime aggregates (#48) and exception groups (#49) pushed by excubia-core. `pushed_batches`
 * makes a repeated runtime or exceptions push idempotent. Aggregates are additive per type,
 * minute bucket, sample rate and dimensions; exception groups are additive per fingerprint
 * and environment, and `exception_occurrences` counts each group's occurrences per minute
 * (#113) for its chart. `runtime_hourly_rollups` sums the aggregates per project, type, hour and
 * sample rate at ingest (#137), so the dashboard totals read one row per hour, not one per minute
 * and series.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pushed_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);
            $table->uuid('batch_id');
            $table->timestamp('received_at');

            $table->unique(['project_id', 'type', 'batch_id']);
            $table->index('received_at');
        });

        Schema::create('runtime_aggregates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            // Only the types this app knows are stored (#111); 32 leaves room for longer names.
            $table->string('type', 32);
            $table->timestamp('bucket');
            $table->decimal('sample_rate', 5, 4);
            $table->char('dimension_key', 64);
            $table->json('dimensions');
            $table->unsignedBigInteger('count');
            $table->unsignedBigInteger('error_count');
            // Null for the types that are not timed (#111).
            $table->double('total_ms')->nullable();
            $table->double('max_ms')->nullable();
            // Type-specific metrics (memory, cache counters, repeats, server gauges), the
            // optional latency histogram and the batch's release (#111).
            $table->json('metrics')->nullable();
            $table->json('histogram')->nullable();
            $table->string('release', 100)->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'type', 'bucket', 'sample_rate', 'dimension_key'], 'runtime_aggregates_identity');
            // Covers the per-bucket sums of the charts and the dashboard (#113): MySQL and
            // PostgreSQL read them from the index without visiting the rows.
            $table->index(['project_id', 'type', 'bucket', 'sample_rate', 'count', 'error_count'], 'runtime_aggregates_buckets');
            // The daily retention prune deletes by bucket across every project.
            $table->index('bucket');
        });

        Schema::create('runtime_hourly_rollups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            // The start of the hour (UTC).
            $table->timestamp('bucket');
            $table->decimal('sample_rate', 5, 4);
            $table->unsignedBigInteger('count');
            $table->unsignedBigInteger('error_count');
            $table->double('total_ms')->nullable();

            $table->unique(['project_id', 'type', 'bucket', 'sample_rate'], 'runtime_hourly_rollups_identity');
            $table->index('bucket');
        });

        Schema::create('exception_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->char('fingerprint', 64);
            $table->string('environment', 64);
            $table->string('class');
            $table->text('message');
            $table->json('frames');
            $table->json('previous');
            $table->string('context_kind', 16);
            $table->string('context_name');
            $table->unsignedBigInteger('count');
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            // The batch releases of the first and latest occurrence, and the release a resolved
            // group came back in (#106). Null when the core sent no release.
            $table->string('first_release', 100)->nullable();
            $table->string('last_release', 100)->nullable();
            $table->string('regressed_release', 100)->nullable();
            $table->string('status', 16)->default('open');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'fingerprint', 'environment']);
            $table->index(['project_id', 'last_seen_at']);
        });

        Schema::create('exception_occurrences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exception_group_id')->constrained()->cascadeOnDelete();
            $table->timestamp('bucket');
            $table->unsignedBigInteger('count');

            $table->unique(['exception_group_id', 'bucket']);
            $table->index(['project_id', 'bucket']);
            $table->index('bucket');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exception_occurrences');
        Schema::dropIfExists('exception_groups');
        Schema::dropIfExists('runtime_hourly_rollups');
        Schema::dropIfExists('runtime_aggregates');
        Schema::dropIfExists('pushed_batches');
    }
};

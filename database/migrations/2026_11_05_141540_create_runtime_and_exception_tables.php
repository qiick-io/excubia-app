<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Runtime aggregates (#48) and exception groups (#49) pushed by excubia-core. `pushed_batches`
 * makes a repeated runtime or exceptions push idempotent. Aggregates are additive per type,
 * minute bucket, sample rate and dimensions; exception groups are additive per fingerprint
 * and environment.
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
            $table->string('type', 16);
            $table->timestamp('bucket');
            $table->decimal('sample_rate', 5, 4);
            $table->char('dimension_key', 64);
            $table->json('dimensions');
            $table->unsignedBigInteger('count');
            $table->unsignedBigInteger('error_count');
            $table->double('total_ms');
            $table->double('max_ms');
            $table->timestamps();

            $table->unique(['project_id', 'type', 'bucket', 'sample_rate', 'dimension_key'], 'runtime_aggregates_identity');
            $table->index(['project_id', 'type', 'bucket']);
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
            $table->timestamp('first_seen');
            $table->timestamp('last_seen');
            $table->string('status', 16)->default('open');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'fingerprint', 'environment']);
            $table->index(['project_id', 'last_seen']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exception_groups');
        Schema::dropIfExists('runtime_aggregates');
        Schema::dropIfExists('pushed_batches');
    }
};

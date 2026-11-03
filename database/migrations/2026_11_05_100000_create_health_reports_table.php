<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reports pushed by excubia-core. The summary columns let the overview list projects without
 * decoding every report; `batch_id` makes a repeated push idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->uuid('batch_id');
            $table->timestamp('received_at');
            $table->timestamp('generated_at');
            $table->string('worst_severity', 16)->nullable();
            $table->unsignedInteger('critical_count')->default(0);
            $table->unsignedInteger('high_count')->default(0);
            $table->unsignedInteger('medium_count')->default(0);
            $table->unsignedInteger('low_count')->default(0);
            $table->unsignedInteger('info_count')->default(0);
            $table->json('report');
            $table->timestamps();

            $table->unique(['project_id', 'batch_id']);
            $table->index(['project_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_reports');
    }
};

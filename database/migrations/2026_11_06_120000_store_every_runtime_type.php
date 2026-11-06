<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every runtime type of excubia-core beta.2+ (#111): `total_ms` and `max_ms` are null for the
 * types that are not timed; `metrics` holds the type-specific metrics (memory, cache counters,
 * repeats, server gauges), `histogram` the optional latency counts and `release` the batch's
 * release. Projects count the entries of unknown types they skipped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runtime_aggregates', function (Blueprint $table): void {
            $table->double('total_ms')->nullable()->change();
            $table->double('max_ms')->nullable()->change();
            $table->json('metrics')->nullable();
            $table->json('histogram')->nullable();
            $table->string('release', 100)->nullable();
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->unsignedBigInteger('skipped_runtime_aggregates')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn('skipped_runtime_aggregates');
        });

        Schema::table('runtime_aggregates', function (Blueprint $table): void {
            $table->dropColumn(['metrics', 'histogram', 'release']);
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI usage per project, UTC day, provider and model (#129), summed at ingest from the `ai`
 * runtime entries as estimated real amounts (divided by the sample rate). It outlives the
 * minute aggregates, so the monthly budget and the weekly digest see the whole month. Costs are
 * computed when read, from the price table, so a price change applies to past days too.
 * `usage_key` is the SHA-256 of provider and model, which may be long.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_days', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->char('usage_key', 64);
            $table->text('provider');
            $table->text('model');
            $table->double('calls')->default(0);
            $table->double('errors')->default(0);
            $table->double('input_tokens')->default(0);
            $table->double('output_tokens')->default(0);
            $table->double('cache_read_input_tokens')->default(0);
            $table->double('cache_write_input_tokens')->default(0);
            $table->double('reasoning_tokens')->default(0);
            $table->double('image_input_tokens')->default(0);
            $table->double('image_output_tokens')->default(0);
            $table->double('audio_seconds')->default(0);
            $table->double('search_units')->default(0);

            $table->unique(['project_id', 'day', 'usage_key']);
            $table->index('day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_days');
    }
};

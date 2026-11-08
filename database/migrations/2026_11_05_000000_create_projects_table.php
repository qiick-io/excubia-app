<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Projects that push their reports to the app. Only the SHA-256 hash of the push token is
 * stored; a null hash means the token was revoked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->boolean('is_enabled')->default(true);
            // Excubia itself (#70): seeded, reported locally, never deleted.
            $table->boolean('is_system')->default(false);
            $table->string('visibility', 16)->default('everyone');
            $table->string('push_token_hash', 64)->nullable()->unique();
            $table->timestamp('last_report_at')->nullable();
            // When the project.stale alert was sent; cleared by the next report (#22).
            $table->timestamp('stale_alerted_at')->nullable();
            // Runtime entries of unknown types skipped on ingest (#111).
            $table->unsignedBigInteger('skipped_runtime_aggregates')->default(0);
            // Estimated AI spend per calendar month (USD) that alerts at 80 % and 100 % (#129); null is off.
            $table->decimal('ai_monthly_budget', 12, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};

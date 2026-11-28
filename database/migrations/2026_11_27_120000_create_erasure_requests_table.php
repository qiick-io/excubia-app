<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Resumable user erasure requests (#381), the product orchestrator of the qiick fleet erasure
 * standard: one row per case, written before any eraser runs, so a failed or interrupted erasure
 * resumes from its encrypted snapshot instead of from the user row.
 *
 * `subject` is the encrypted snapshot (user id, public id, principal and key public ids,
 * identifiers to scrub, the key id map) and becomes null when the case closes, as does `user_id`,
 * the plain lookup that keeps one open request per user (no foreign key: the case outlives
 * nothing it points at). Only the executor holding `claim_token` until `claimed_until` advances
 * a case, so two runs (a deletion and the resume command) never step on each other.
 * `organisation_id` keeps the standard shape; Excubia has no organisations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erasure_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('mode', 16);
            $table->uuid('organisation_id')->nullable();
            $table->string('status', 16)->index();
            $table->json('steps');
            $table->text('subject')->nullable();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            // The executor that holds the case and until when: a crashed one's lease runs out.
            $table->string('claim_token', 64)->nullable();
            $table->timestamp('claimed_until')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error_code', 64)->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erasure_requests');
    }
};

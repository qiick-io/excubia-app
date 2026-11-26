<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personal API keys (#315, #351) on the reference schema of qiick/laravel-api-keys 0.3
 * (`docs/reference-schema.md`).
 *
 * A key acts as its owner and never more. Only the SHA-256 of the full token is stored;
 * `key_prefix` holds the public part (`exc_{env}_{id}`), unique so a prefix names one row.
 * `owner_id` stays as the product's owner column until qiick/laravel-access lands
 * (docs/adoption-plan.md). Edited in place: no stable release yet (decision 2026-11-20).
 * Dated after qiick/laravel-access' `create_principals_table` (2026_11_24), which it references.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('key_prefix', 32)->unique();
            $table->string('key_hash', 64)->unique();
            $table->unsignedSmallInteger('token_version')->default(1);
            $table->string('hash_algorithm', 16)->default('sha256');
            $table->string('previous_key_prefix', 32)->nullable()->index();
            $table->string('previous_key_hash', 64)->nullable();
            // A datetime, not a timestamp: MySQL timestamps end in 2038, and grace and expiry are future instants.
            $table->dateTime('previous_key_valid_until')->nullable();
            $table->string('credential_kind', 16)->default('api_key');
            $table->uuid('oauth_client_id')->nullable()->index();
            // Soft-deleted users keep their keys; a hard delete must be refused, not erase evidence.
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            // The owning principal outlives its user; removing it must never erase the key or its audit trail.
            $table->foreignUuid('owner_principal_id')->constrained('principals')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('scopes');
            $table->json('ip_allowlist')->nullable();
            $table->json('restrictions')->nullable();
            $table->unsignedInteger('rate_limit_per_minute')->nullable();
            $table->unsignedInteger('rate_limit_per_day')->nullable();
            $table->unsignedInteger('rate_limit_per_month')->nullable();
            $table->json('metadata')->nullable();
            $table->dateTime('expires_at')->nullable()->index();
            $table->timestamp('expiry_notified_at')->nullable();
            $table->timestamp('expiry_notice_claimed_at')->nullable();
            // Binds a claim to its worker and its selected expiry (qiick/laravel-api-keys 0.3.0-beta.3).
            $table->string('expiry_notice_claim_token', 64)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->string('last_used_user_agent', 512)->nullable();
            $table->timestamp('last_rotated_at')->nullable();
            $table->timestamp('exposed_at')->nullable()->index();
            $table->string('exposure_source', 64)->nullable();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revoked_reason', 32)->nullable();
            $table->text('revocation_note')->nullable();
            $table->timestamps();

            $table->index(['credential_kind', 'revoked_at']);
            $table->index('owner_id');
            $table->index('owner_principal_id');
            $table->index('created_by');
            $table->index('revoked_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};

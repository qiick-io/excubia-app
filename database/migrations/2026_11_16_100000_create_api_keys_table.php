<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personal API keys (#315, #351): baseline storage from app-ui docs/api-keys.md. A key acts as
 * its owner and never more. Only the SHA-256 of the full token is stored; `key_prefix` holds the
 * public part (`exc_{env}_{id}`) for indexed lookup and display, never a slice of the secret.
 * Edited in place: this product has no releases yet (Luca, 2026-11-17).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('key_prefix', 32);
            $table->string('key_hash', 64)->unique();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            // Fleet principal that owns the key; authorisation still uses owner_id → users.
            // Removing a principal or its creator must never erase the key or its audit trail.
            $table->foreignUuid('owner_principal_id')->nullable()->constrained('principals')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('scopes');
            $table->json('ip_allowlist')->nullable();
            $table->unsignedInteger('rate_limit_per_minute')->nullable();
            // A datetime, not a timestamp: MySQL timestamps end in 2038, and a user may pick a later expiry.
            $table->dateTime('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('previous_key_hash', 64)->nullable();
            $table->timestamp('previous_key_valid_until')->nullable();
            $table->timestamps();

            $table->index('key_prefix');
            $table->index('owner_id');
            $table->index('owner_principal_id');
            $table->index('created_by');
            $table->index('revoked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};

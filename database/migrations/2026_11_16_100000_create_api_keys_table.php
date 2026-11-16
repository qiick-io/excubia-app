<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personal API keys (#315), as externa-core's `api_keys` but owned by one user: a key acts as its
 * owner and nothing more. Only the SHA-256 hash of the key is stored, with a short prefix to tell
 * keys apart in the list. Deleting the user deletes their keys. A new table: nothing to backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('key_prefix', 16);
            $table->string('key_hash', 64)->unique();
            $table->json('scopes');
            // A datetime, not a timestamp: MySQL timestamps end in 2038, and a user may pick a later expiry.
            $table->dateTime('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};

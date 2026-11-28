<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Browser sign-in registry independent of the configured Laravel session driver.
 * The public UUID identifies a sign-in without storing the Laravel session ID or remember token.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('browser_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('user_agent', 512)->nullable();
            $table->boolean('is_remembered')->default(false);
            $table->timestamp('authenticated_at');
            $table->timestamp('last_activity_at');
            $table->dateTime('expires_at')->index();

            $table->index(['user_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('browser_sessions');
    }
};

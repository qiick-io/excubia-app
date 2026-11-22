<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_active')->default(true);
            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('email')->unique();
            // Fleet-wide optional username (#84): nullable, unique, stored lowercase.
            $table->string('username')->nullable()->unique();
            // Fleet public id (UUID v7): exposed outside the database; primary key stays bigint.
            $table->uuid('public_id')->unique();
            // Fleet audit columns: soft-delete marker and the last successful sign-in.
            $table->timestamp('deleted_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->string('locale')->default('en');
            // IANA timezone (fleet baseline); default UTC.
            $table->string('timezone')->default('UTC');
            // Weekly email digest (#114), opt-in from Settings → Profile.
            $table->boolean('has_weekly_digest')->default(false);
            // Alert emails (#22), opt-in from Settings → Profile, with the user's own filters
            // (AlertFilterData: minimum severity, event types, projects).
            $table->boolean('has_alert_emails')->default(false);
            $table->json('alert_email_filters')->nullable();
            // In-app alert channel (#296), on by default, same filters as alert emails.
            $table->boolean('has_alert_in_app')->default(true);
            $table->timestamp('email_verified_at')->nullable();
            // Null for accounts that only sign in through OIDC.
            $table->string('password')->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};

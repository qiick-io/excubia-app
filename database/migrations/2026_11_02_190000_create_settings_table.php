<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scoped application settings (fleet / externa-core shape).
 *
 * Shape: UNIQUE(scope, scope_id, group, key), INDEX(scope, scope_id, group).
 * Instance settings use scope=`instance`, scope_id=`''` (empty string, NOT NULL)
 * and group=`app`: a NULL scope_id would let duplicate keys through on PostgreSQL
 * and MySQL (NULLs are distinct in unique indexes; signaculum-core #205).
 *
 * Column lengths stay short enough for a utf8mb4 unique index on MySQL
 * (4 × 4 bytes × lengths ≤ 3072).
 *
 * @see knowledge/decisions/2026-11-19-fleet-core-schema
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 32);
            $table->string('scope_id', 36);
            $table->string('group', 64);
            $table->string('key', 191);
            $table->json('value')->nullable();
            $table->timestamps();

            $table->unique(['scope', 'scope_id', 'group', 'key'], 'settings_scope_unique');
            $table->index(['scope', 'scope_id', 'group'], 'settings_scope_group_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};

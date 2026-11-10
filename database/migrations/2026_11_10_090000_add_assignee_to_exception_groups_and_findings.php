<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who is on it (#254): an optional assignee on exception groups and findings, and on the finding
 * events that record an assignment. Nullable, so existing rows need no backfill; a deleted user
 * leaves the item unassigned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exception_groups', function (Blueprint $table): void {
            $table->foreignId('assigned_user_id')->nullable()->index()->constrained('users')->nullOnDelete();
        });

        Schema::table('findings', function (Blueprint $table): void {
            $table->foreignId('assigned_user_id')->nullable()->index()->constrained('users')->nullOnDelete();
        });

        Schema::table('finding_events', function (Blueprint $table): void {
            $table->foreignId('assigned_user_id')->nullable()->index()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['finding_events', 'findings', 'exception_groups'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('assigned_user_id');
            });
        }
    }
};

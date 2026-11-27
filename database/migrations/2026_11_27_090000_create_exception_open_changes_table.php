<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Records the open-count changes that occurrence buckets and the current group status cannot reconstruct. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exception_open_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exception_group_id')->index()->constrained()->cascadeOnDelete();
            $table->timestamp('occurred_at');
            $table->smallInteger('delta');

            $table->index(['project_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exception_open_changes');
    }
};

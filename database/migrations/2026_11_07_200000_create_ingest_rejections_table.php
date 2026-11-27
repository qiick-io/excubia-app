<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Push rejections (#117): one counter per endpoint, status, reason and hour, for the System page.
 * Never the body, the token or the project: only what an admin needs to find the cause.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingest_rejections', function (Blueprint $table): void {
            $table->id();
            // reports, runtime or exceptions.
            $table->string('endpoint', 16);
            $table->unsignedSmallInteger('status');
            // A fixed word (token, disabled, too_large, throttled, invalid) or the first invalid
            // field of a 422, with list indexes replaced by `*`.
            $table->string('reason', 100);
            // The start of the hour (UTC).
            $table->timestamp('bucket');
            $table->unsignedBigInteger('count');

            $table->unique(['endpoint', 'status', 'reason', 'bucket'], 'ingest_rejections_identity');
            $table->index('bucket');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingest_rejections');
    }
};

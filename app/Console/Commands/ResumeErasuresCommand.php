<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ErasureRequest;
use App\Services\ErasureOrchestratorService;
use Illuminate\Console\Command;

/**
 * Retries the user erasures (#381) left `pending`, `running` (a crashed worker) or `failed`,
 * from their snapshots. A case whose snapshot no longer decrypts is skipped and listed on every
 * run (the command then fails): it needs an admin, not a retry. `--dry-run` writes nothing. Scheduled hourly on one server; prints case ids and counts, never personal data.
 */
class ResumeErasuresCommand extends Command
{
    protected $signature = 'excubia:resume-erasures {--dry-run : List the cases that would be resumed, change nothing}';

    protected $description = 'Resume interrupted or failed user erasures';

    public function handle(ErasureOrchestratorService $erasure): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $openRequests = ErasureRequest::query()
            ->where('status', '!=', ErasureRequest::STATUS_COMPLETED)
            ->where(fn ($query) => $query->whereNull('last_error_code')->orWhere('last_error_code', '!=', ErasureRequest::ERROR_SNAPSHOT_UNREADABLE))
            ->orderBy('requested_at')
            ->get();
        $failedCount = 0;

        foreach ($openRequests as $openRequest) {
            if (! $erasure->run($openRequest, $isDryRun)) {
                $failedCount++;
                $this->components->warn("Erasure {$openRequest->id} failed: {$openRequest->last_error_code}");
            }
        }

        $verb = $isDryRun ? 'Would resume' : 'Resumed';
        $this->components->info("{$verb} {$openRequests->count()} erasures, {$failedCount} failed.");

        // Never retried, so listed on every run until an admin acts (case ids only).
        $unreadableIds = ErasureRequest::query()
            ->where('status', '!=', ErasureRequest::STATUS_COMPLETED)
            ->where('last_error_code', ErasureRequest::ERROR_SNAPSHOT_UNREADABLE)
            ->pluck('id');

        foreach ($unreadableIds as $unreadableId) {
            $this->components->error("Erasure {$unreadableId} cannot read its snapshot (APP_KEY changed?): it needs an admin.");
        }

        return $failedCount === 0 && $unreadableIds->isEmpty() ? self::SUCCESS : self::FAILURE;
    }
}

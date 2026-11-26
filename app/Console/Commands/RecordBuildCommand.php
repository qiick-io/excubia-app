<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SystemHealthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Records the commit and time of this build in `bootstrap/cache/build.json` (#425), so the
 * sidebar can show the build ID on hosts that remove `.git` before the app runs. Composer runs
 * it after every `composer install` / `dump-autoload`; without git or a checkout it records
 * nothing and succeeds, so an install never fails because of it.
 */
class RecordBuildCommand extends Command
{
    protected $signature = 'excubia:record-build';

    protected $description = 'Record the commit of this build for the version shown in the sidebar';

    public function handle(): int
    {
        try {
            $commit = $this->checkoutCommit();

            if ($commit === null) {
                $this->components->info('No git checkout of this app here: no build ID recorded.');

                return self::SUCCESS;
            }

            // Written to a temporary file and renamed, so a reader never sees half a file.
            $temporaryFile = SystemHealthService::buildFile().'.'.getmypid().'.tmp';
            File::put($temporaryFile, (string) json_encode(['commit' => $commit, 'built_at' => now()->utc()->toIso8601ZuluString()]));
            File::move($temporaryFile, SystemHealthService::buildFile());
            $this->components->info('Recorded build '.substr($commit, 0, 7).'.');
        } catch (Throwable $exception) {
            // Best effort: a missing git binary, a timeout or a read-only directory never fails
            // `composer install`.
            $this->components->warn('No build ID recorded: '.$exception->getMessage());
        }

        return self::SUCCESS;
    }

    /**
     * HEAD of the git checkout whose root is this app, or null: not a checkout, or this app sits
     * inside another repository (an unpacked archive in someone's project), whose commit is not ours.
     */
    private function checkoutCommit(): ?string
    {
        $result = Process::path(base_path())->timeout(10)->run('git rev-parse --show-toplevel HEAD');
        $lines = explode("\n", trim($result->output()));

        if (! $result->successful() || count($lines) !== 2 || realpath(trim($lines[0])) !== realpath(base_path())) {
            return null;
        }

        $commit = trim($lines[1]);

        return preg_match('/^[0-9a-f]{40}$/', $commit) === 1 ? $commit : null;
    }
}

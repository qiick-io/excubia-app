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
            $probe = Process::path(base_path())->timeout(10)->run('git rev-parse --show-toplevel HEAD');

            // Not a checkout at all: a stamp from an earlier build would describe other code.
            if (! $probe->successful() && str_contains($probe->errorOutput(), 'not a git repository')) {
                $this->dropStaleStamp();

                return self::SUCCESS;
            }

            $commit = $this->commitOf($probe->successful() ? $probe->output() : '');

            if ($commit === null) {
                // A probe error, or a checkout of another repository: keep whatever is there.
                $this->components->warn('No build ID recorded: git did not report this app\'s commit.');

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

    private function dropStaleStamp(): void
    {
        File::delete(SystemHealthService::buildFile());

        if (File::exists(SystemHealthService::buildFile())) {
            $this->components->warn('An earlier build ID could not be removed: '.SystemHealthService::buildFile());

            return;
        }

        $this->components->info('No git checkout of this app here: no build ID recorded.');
    }

    /**
     * HEAD from `git rev-parse --show-toplevel HEAD` when the checkout's root is this app; null for
     * another repository around it (an unpacked archive in someone's project).
     */
    private function commitOf(string $output): ?string
    {
        $lines = explode("\n", trim($output));

        if (count($lines) !== 2 || realpath(trim($lines[0])) !== realpath(base_path())) {
            return null;
        }

        $commit = trim($lines[1]);

        return preg_match('/^[0-9a-f]{40}$/', $commit) === 1 ? $commit : null;
    }
}

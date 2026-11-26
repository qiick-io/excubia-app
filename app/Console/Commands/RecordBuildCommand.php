<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SystemHealthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

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
        $result = Process::path(base_path())->run('git rev-parse HEAD');
        $commit = trim($result->output());

        if (! $result->successful() || preg_match('/^[0-9a-f]{40}$/', $commit) !== 1) {
            $this->components->info('No git checkout here: no build ID recorded.');

            return self::SUCCESS;
        }

        file_put_contents(SystemHealthService::buildFile(), (string) json_encode(['commit' => $commit, 'built_at' => now()->utc()->toIso8601ZuluString()]));
        $this->components->info('Recorded build '.substr($commit, 0, 7).'.');

        return self::SUCCESS;
    }
}

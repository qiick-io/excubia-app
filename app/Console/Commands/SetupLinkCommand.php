<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\SetupTokenService;
use Illuminate\Console\Command;

/**
 * Prints the one-time link to the first-run admin step, for the operator on the server: /setup
 * creates the first super-admin only for the holder of this link (valid for an hour, used once).
 */
class SetupLinkCommand extends Command
{
    protected $signature = 'excubia:setup-link';

    protected $description = 'Print a one-time link to create the first admin at /setup';

    public function handle(SetupTokenService $setupTokens): int
    {
        if (User::query()->exists()) {
            $this->error('This install already has users: the setup wizard is closed. Use excubia:create-user instead.');

            return self::FAILURE;
        }

        $this->line(url('/setup').'?token='.$setupTokens->issue());
        $this->line('Open it within an hour; it works once.');

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\SetupTokenService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Prints the one-time link to the first-run admin step, for the operator on the server: /setup
 * creates the first super-admin only for the holder of this link (valid for an hour, used once).
 */
class SetupLinkCommand extends Command
{
    protected $signature = 'excubia:setup-link';

    protected $description = 'Print a one-time link to create the first admin at /setup';

    /**
     * @throws LockTimeoutException when the first admin is being created right now
     */
    public function handle(SetupTokenService $setupTokens): int
    {
        // Under the creation lock: a new link can never be issued between the check of the old
        // one and the creation of the first admin (which would then discard the new link).
        $token = Cache::lock(SetupTokenService::LOCK, 10)->block(5, fn (): ?string => User::query()->exists() ? null : $setupTokens->issue());

        if ($token === null) {
            $this->error('This install already has users: the setup wizard is closed. Use excubia:create-user instead.');

            return self::FAILURE;
        }

        // A fragment: the browser keeps it, and no request URL (or log) carries the token.
        $this->line(url('/setup').'#token='.$token);
        $this->line('Open it within an hour; it works once.');

        return self::SUCCESS;
    }
}

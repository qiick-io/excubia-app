<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\WeeklyDigestMail;
use App\Models\User;
use App\Services\WeeklyDigestService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Mails the weekly digest (#114) to each active user who turned it on in Settings → Profile,
 * in their locale; nobody gets an empty digest. Scheduled on Monday mornings.
 */
class SendWeeklyDigestsCommand extends Command
{
    protected $signature = 'excubia:send-digests';

    protected $description = 'Mail the weekly digest to the users who asked for it';

    public function handle(WeeklyDigestService $digests): int
    {
        $count = 0;

        User::query()->where('is_active', true)->where('has_weekly_digest', true)->each(function (User $user) use ($digests, &$count): void {
            $projects = $digests->forUser($user);

            if ($projects === null) {
                return;
            }

            Mail::to($user)->locale($user->locale)->queue(new WeeklyDigestMail($projects));
            $count++;
        });

        $this->components->info("Queued {$count} weekly digests.");

        return self::SUCCESS;
    }
}

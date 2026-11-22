<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ApiKey;
use App\Notifications\ApiKeyExpiringNotification;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Notifies owners (and issuers when different) about API keys that expire in the warning window
 * (#351): between N and N+1 days from now (N = config `api.key_expiry_notice_days`, default 7), so
 * each key is notified once without extra state when the command runs daily.
 */
#[AsCommand(name: 'excubia:notify-expiring-api-keys')]
class NotifyExpiringApiKeysCommand extends Command
{
    protected $signature = 'excubia:notify-expiring-api-keys';

    protected $description = 'Notify owners when personal API keys will expire in seven days';

    public function handle(): int
    {
        $noticeDays = max(1, (int) config('api.key_expiry_notice_days', 7));
        $windowStart = now()->addDays($noticeDays);
        $windowEnd = now()->addDays($noticeDays + 1);

        $apiKeys = ApiKey::query()
            ->with(['owner', 'creator'])
            ->whereNull('revoked_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', $windowStart)
            ->where('expires_at', '<=', $windowEnd)
            ->get();

        $sent = 0;

        foreach ($apiKeys as $apiKey) {
            // A deleted owner no longer appears through the relation; never notify a null recipient.
            $owner = $apiKey->owner;

            if ($owner === null) {
                continue;
            }

            $notification = new ApiKeyExpiringNotification($apiKey);
            $recipients = [$owner];

            if ($apiKey->creator !== null && $apiKey->created_by !== $apiKey->owner_id) {
                $recipients[] = $apiKey->creator;
            }

            foreach ($recipients as $recipient) {
                if ($recipient->is_active) {
                    $recipient->notify($notification);
                    $sent++;
                }
            }
        }

        $this->info("Notified for {$apiKeys->count()} expiring API key(s) ({$sent} notification(s)).");

        return self::SUCCESS;
    }
}

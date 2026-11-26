<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ApiKey;
use App\Models\User;
use Qiick\ApiKeys\Contracts\ApiKeyRecord;
use Qiick\ApiKeys\Contracts\ExpiryRecipientResolver;
use Qiick\ApiKeys\ExpiryRecipient;

/**
 * Who `api-keys:notify-expiring` warns about a key: its owner and, when someone else issued it,
 * the issuer. Only active users: a deactivated or deleted account gets no mail.
 */
class ApiKeyExpiryRecipientService implements ExpiryRecipientResolver
{
    /**
     * @param  list<ExpiryRecipient>  $roles
     * @return list<User>
     */
    public function recipientsFor(ApiKeyRecord $record, array $roles): iterable
    {
        if (! $record instanceof ApiKey) {
            return [];
        }

        $recipients = [];

        if (in_array(ExpiryRecipient::Owner, $roles, true) && $record->owner !== null) {
            $recipients[$record->owner->id] = $record->owner;
        }

        if (in_array(ExpiryRecipient::Issuer, $roles, true) && $record->creator !== null) {
            $recipients[$record->creator->id] = $record->creator;
        }

        return array_values(array_filter($recipients, fn (User $user): bool => $user->is_active));
    }
}

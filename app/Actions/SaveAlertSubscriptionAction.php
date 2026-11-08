<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\AlertFilterData;
use App\Models\User;

/**
 * Turns a user's alert emails (#22) on or off and stores their filters.
 */
class SaveAlertSubscriptionAction
{
    public function execute(User $user, bool $isEnabled, AlertFilterData $filter): void
    {
        $user->forceFill(['has_alert_emails' => $isEnabled, 'alert_email_filters' => $filter->toArray()])->save();
    }
}

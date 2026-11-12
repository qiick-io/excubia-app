<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\AlertFilterData;
use App\Models\User;

/**
 * Turns a user's alert emails (#22) and in-app alerts (#296) on or off and stores the filters
 * both read.
 */
class SaveAlertSubscriptionAction
{
    public function execute(User $user, bool $isEmailEnabled, bool $isInAppEnabled, AlertFilterData $filter): void
    {
        $user->forceFill([
            'has_alert_emails' => $isEmailEnabled,
            'has_alert_in_app' => $isInAppEnabled,
            'alert_email_filters' => $filter->toArray(),
        ])->save();
    }
}

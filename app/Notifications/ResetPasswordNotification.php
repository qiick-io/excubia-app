<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Laravel's reset-link mail, queued (#472): sent in the request, it made the answer for an
 * existing address measurably slower than for an unknown one, which tells them apart.
 */
class ResetPasswordNotification extends ResetPassword implements ShouldQueue
{
    use Queueable;
}

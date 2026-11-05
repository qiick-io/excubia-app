<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ExceptionStatusEnum;
use App\Models\ExceptionGroup;
use Illuminate\Support\Facades\Date;

/**
 * Sets the status of an exception group; `resolved_at` follows a resolve and clears otherwise.
 */
class UpdateExceptionStatusAction
{
    public function execute(ExceptionGroup $group, ExceptionStatusEnum $status): ExceptionGroup
    {
        $group->forceFill([
            'status' => $status,
            'resolved_at' => $status === ExceptionStatusEnum::Resolved ? Date::now() : null,
        ])->save();

        return $group;
    }
}

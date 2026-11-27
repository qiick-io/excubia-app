<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ExceptionStatusEnum;
use App\Models\ExceptionGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Resolves, ignores or reopens the selected exception groups (#276): each one through
 * `UpdateExceptionStatusAction`, so each gets its own activity entry, in one transaction.
 */
class BulkUpdateExceptionStatusAction
{
    public function __construct(private readonly UpdateExceptionStatusAction $updateStatus) {}

    /**
     * @param  Collection<int, ExceptionGroup>  $groups
     * @return int The groups whose status changed
     */
    public function execute(Collection $groups, ExceptionStatusEnum $status, User $actor): int
    {
        return DB::transaction(function () use ($groups, $status, $actor): int {
            $changed = 0;

            foreach ($groups as $group) {
                if ($group->status !== $status) {
                    $this->updateStatus->execute($group, $status, $actor);
                    $changed++;
                }
            }

            return $changed;
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ExceptionStatusEnum;
use App\Models\ExceptionGroup;
use App\Models\ExceptionOccurrence;
use App\Models\Project;
use App\Models\PushedBatch;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Stores an exceptions batch pushed by a project (#49), grouped by fingerprint and environment
 * as `docs/exception-schema.json` asks: the count adds up and first / last seen widen, also
 * across batches. The first occurrence's message and frames are kept; the context follows the
 * latest occurrence. A resolved group reopens when it occurs again; an ignored one stays
 * ignored. A repeated `batchId` stores nothing and returns false.
 *
 * The batch's optional `release` (#106) is kept as the group's first and last release. A
 * resolved group that comes back in another release than the one it was last seen in is a
 * regression in that release (the fix shipped and did not hold); coming back in the same
 * release only reopens it, as the fix may not be deployed yet.
 *
 * Each group's count is also added to the minute of its last occurrence (#113), for the group's
 * chart: the payload has no time per occurrence.
 */
class StorePushedExceptionsAction
{
    /**
     * @param  list<array<string, mixed>>  $groups  validated by StorePushedExceptionsRequest
     * @param  string|null  $release  the batch's `exceptions.release`
     * @return bool true when the batch was new
     */
    public function execute(Project $project, string $batchId, array $groups, ?string $release = null): bool
    {
        try {
            return DB::transaction(function () use ($project, $batchId, $groups, $release): bool {
                PushedBatch::query()->create(['project_id' => $project->id, 'type' => 'exceptions', 'batch_id' => $batchId, 'received_at' => Date::now()]);

                foreach ($groups as $group) {
                    $this->add($project, $group, $release);
                }

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $group
     */
    private function add(Project $project, array $group, ?string $release): void
    {
        $firstSeen = CarbonImmutable::parse((string) $group['firstSeen'])->utc();
        $lastSeen = CarbonImmutable::parse((string) $group['lastSeen'])->utc();
        $context = (array) $group['context'];
        // A kind this app does not know (#169) is stored as unknown, so a newer core never loses the group.
        $kind = in_array($context['kind'], ExceptionGroup::CONTEXT_KINDS, true) ? (string) $context['kind'] : 'unknown';

        $existing = ExceptionGroup::query()
            ->where('project_id', $project->id)
            ->where('fingerprint', (string) $group['fingerprint'])
            ->where('environment', (string) $group['environment'])
            ->lockForUpdate()
            ->first();

        if ($existing === null) {
            $created = ExceptionGroup::query()->create([
                'project_id' => $project->id,
                'fingerprint' => (string) $group['fingerprint'],
                'environment' => (string) $group['environment'],
                'class' => (string) $group['class'],
                'message' => (string) $group['message'],
                'frames' => array_values((array) $group['frames']),
                'previous' => array_values((array) $group['previous']),
                'context_kind' => $kind,
                'context_name' => (string) $context['name'],
                'count' => (int) $group['count'],
                'first_seen_at' => $firstSeen,
                'last_seen_at' => $lastSeen,
                'first_release' => $release,
                'last_release' => $release,
                'status' => ExceptionStatusEnum::Open,
            ]);
            $this->addOccurrences($created, $lastSeen, (int) $group['count']);

            return;
        }

        $isNewer = $lastSeen->gt($existing->last_seen_at);
        $isRegression = $existing->status === ExceptionStatusEnum::Resolved
            && $release !== null
            && $existing->last_release !== null
            && $release !== $existing->last_release;

        $existing->forceFill([
            'count' => $existing->count + (int) $group['count'],
            'first_seen_at' => $firstSeen->lt($existing->first_seen_at) ? $firstSeen : $existing->first_seen_at,
            'last_seen_at' => $isNewer ? $lastSeen : $existing->last_seen_at,
            'context_kind' => $isNewer ? $kind : $existing->context_kind,
            'context_name' => $isNewer ? (string) $context['name'] : $existing->context_name,
            'first_release' => $existing->first_release ?? $release,
            'last_release' => $isNewer && $release !== null ? $release : ($existing->last_release ?? $release),
            ...($existing->status === ExceptionStatusEnum::Resolved ? [
                'status' => ExceptionStatusEnum::Open,
                'resolved_at' => null,
                'regressed_release' => $isRegression ? $release : null,
            ] : []),
        ])->save();
        $this->addOccurrences($existing, $lastSeen, (int) $group['count']);
    }

    /**
     * Adds a batch's count to the group's minute. The group row is locked, so this read and
     * write cannot race another batch of the same group.
     */
    private function addOccurrences(ExceptionGroup $group, CarbonImmutable $lastSeen, int $count): void
    {
        $bucket = $lastSeen->startOfMinute();
        $occurrence = ExceptionOccurrence::query()->where('exception_group_id', $group->id)->where('bucket', $bucket)->first();

        if ($occurrence === null) {
            ExceptionOccurrence::query()->create(['project_id' => $group->project_id, 'exception_group_id' => $group->id, 'bucket' => $bucket, 'count' => $count]);

            return;
        }

        $occurrence->forceFill(['count' => $occurrence->count + $count])->save();
    }
}

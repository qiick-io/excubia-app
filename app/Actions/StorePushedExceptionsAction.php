<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ExceptionStatusEnum;
use App\Models\ExceptionGroup;
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
 */
class StorePushedExceptionsAction
{
    /**
     * @param  list<array<string, mixed>>  $groups  validated by StorePushedExceptionsRequest
     * @return bool true when the batch was new
     */
    public function execute(Project $project, string $batchId, array $groups): bool
    {
        try {
            return DB::transaction(function () use ($project, $batchId, $groups): bool {
                PushedBatch::query()->create(['project_id' => $project->id, 'type' => 'exceptions', 'batch_id' => $batchId, 'received_at' => Date::now()]);

                foreach ($groups as $group) {
                    $this->add($project, $group);
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
    private function add(Project $project, array $group): void
    {
        $firstSeen = CarbonImmutable::parse((string) $group['firstSeen'])->utc();
        $lastSeen = CarbonImmutable::parse((string) $group['lastSeen'])->utc();
        $context = (array) $group['context'];

        $existing = ExceptionGroup::query()
            ->where('project_id', $project->id)
            ->where('fingerprint', (string) $group['fingerprint'])
            ->where('environment', (string) $group['environment'])
            ->lockForUpdate()
            ->first();

        if ($existing === null) {
            ExceptionGroup::query()->create([
                'project_id' => $project->id,
                'fingerprint' => (string) $group['fingerprint'],
                'environment' => (string) $group['environment'],
                'class' => (string) $group['class'],
                'message' => (string) $group['message'],
                'frames' => array_values((array) $group['frames']),
                'previous' => array_values((array) $group['previous']),
                'context_kind' => (string) $context['kind'],
                'context_name' => (string) $context['name'],
                'count' => (int) $group['count'],
                'first_seen' => $firstSeen,
                'last_seen' => $lastSeen,
                'status' => ExceptionStatusEnum::Open,
            ]);

            return;
        }

        $isNewer = $lastSeen->gt($existing->last_seen);

        $existing->forceFill([
            'count' => $existing->count + (int) $group['count'],
            'first_seen' => $firstSeen->lt($existing->first_seen) ? $firstSeen : $existing->first_seen,
            'last_seen' => $isNewer ? $lastSeen : $existing->last_seen,
            'context_kind' => $isNewer ? (string) $context['kind'] : $existing->context_kind,
            'context_name' => $isNewer ? (string) $context['name'] : $existing->context_name,
            ...($existing->status === ExceptionStatusEnum::Resolved ? ['status' => ExceptionStatusEnum::Open, 'resolved_at' => null] : []),
        ])->save();
    }
}

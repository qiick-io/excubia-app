<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\AiUsageDay;
use App\Models\Project;
use App\Traits\AddsOnUpsertTrait;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Expression;

/**
 * Adds `ai` runtime entries (#129) to the project's daily AI usage per provider and model: calls,
 * errors and usage counts as estimated real amounts (`value / sampleRate`), a null usage count
 * adding nothing. Summed in PHP first, so one upsert holds each day once, then added to the
 * stored day by the database (AddsOnUpsertTrait). Runs inside the runtime push transaction.
 */
class StoreAiUsageDaysAction
{
    use AddsOnUpsertTrait;

    /** The summed columns. */
    private const SUMS = ['calls', 'errors', ...AiUsageDay::USAGE_COLUMNS];

    /**
     * @param  list<array<string, mixed>>  $entries  validated `ai` aggregates of one push
     */
    public function execute(Project $project, array $entries): void
    {
        $days = [];

        foreach ($entries as $entry) {
            $provider = (string) $entry['provider'];
            $model = (string) $entry['model'];
            $day = CarbonImmutable::parse((string) $entry['bucket'])->utc()->toDateString();
            $usageKey = hash('sha256', $provider."\0".$model);
            $sampleRate = max((float) $entry['sampleRate'], 0.0001);
            $row = $days["{$day}|{$usageKey}"] ?? [
                'project_id' => $project->id, 'day' => $day, 'usage_key' => $usageKey, 'provider' => $provider, 'model' => $model,
                ...array_fill_keys(self::SUMS, 0.0),
            ];
            $row['calls'] += (int) $entry['count'] / $sampleRate;
            $row['errors'] += (int) $entry['errorCount'] / $sampleRate;

            foreach (AiUsageDay::USAGE_COLUMNS as $metric => $column) {
                $row[$column] += is_numeric($entry[$metric] ?? null) ? (float) $entry[$metric] / $sampleRate : 0.0;
            }

            $days["{$day}|{$usageKey}"] = $row;
        }

        if ($days === []) {
            return;
        }

        $connection = (new AiUsageDay)->getConnection();
        $update = array_map(fn (string $column): Expression => $this->addOnUpsert($connection, 'ai_usage_days', $column), array_combine(self::SUMS, self::SUMS));

        AiUsageDay::query()->toBase()->upsert(array_values($days), ['project_id', 'day', 'usage_key'], $update);
    }
}

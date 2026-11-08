<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One UTC day of AI usage of one provider and model of a project (#129), summed at ingest by
 * StoreAiUsageDaysAction from the `ai` runtime entries, as estimated real amounts. Costs come
 * from the price table when read (AiPriceService).
 *
 * @property int $id
 * @property int $project_id
 * @property Carbon $day
 * @property string $usage_key
 * @property string $provider
 * @property string $model
 * @property float $calls
 * @property float $errors
 * @property float $input_tokens cached input included
 * @property float $output_tokens
 * @property float $cache_read_input_tokens
 * @property float $cache_write_input_tokens
 * @property float $reasoning_tokens
 * @property float $image_input_tokens
 * @property float $image_output_tokens
 * @property float $audio_seconds
 * @property float $search_units
 */
class AiUsageDay extends Model
{
    public $timestamps = false;

    /** The summed columns, as named in the `ai` metrics of RuntimeTypeEnum::AI_USAGE. */
    public const USAGE_COLUMNS = [
        'inputTokens' => 'input_tokens',
        'outputTokens' => 'output_tokens',
        'cacheReadInputTokens' => 'cache_read_input_tokens',
        'cacheWriteInputTokens' => 'cache_write_input_tokens',
        'reasoningTokens' => 'reasoning_tokens',
        'imageInputTokens' => 'image_input_tokens',
        'imageOutputTokens' => 'image_output_tokens',
        'audioSeconds' => 'audio_seconds',
        'searchUnits' => 'search_units',
    ];

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day' => 'date',
            'calls' => 'float',
            'errors' => 'float',
            ...array_fill_keys(array_values(self::USAGE_COLUMNS), 'float'),
        ];
    }
}

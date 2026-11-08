<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AiUsageDay;
use App\Models\Project;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;

/**
 * The AI price table (#129) and the cost of AI usage. excubia-core sends no prices: the app
 * prices the tokens with this table, editable in Settings → AI prices (`ai_prices`), seeded with
 * DEFAULTS, the public list prices of each provider's official pricing page (SOURCES) as checked
 * on DEFAULTS_UPDATED_ON. Prices are in USD: tokens per 1M, audio per second, search units per unit.
 *
 * A model is priced by the row of its provider (as named in the project's `config/ai.php`) with
 * the same model name, or the row whose model the reported one extends by a date or version
 * suffix (`gpt-4o-2024-08-06` → `gpt-4o`, `claude-sonnet-4-5-20250929` → `claude-sonnet-4-5`).
 * A model without a row has no cost: the pages show its tokens only.
 *
 * Price history (#202): a row may carry `effective_from` (a UTC day, `Y-m-d`); each day is priced
 * with the row in force that day, the one with the latest `effective_from` on or before it. A row
 * without the date (the shape saved before #202) is in force since forever, so a price change is a
 * new row of the same model from its first day and earlier days keep the old price. A deprecated
 * model that its provider bills as another model from a day on (ROUTES) is priced by that model's
 * rows from that day: ROUTES stay apart from the rows because a route follows the billed model's
 * own history (Gemini 2.5 Pro billed as 3.8 Flash also takes the 2027-01-01 price of 3.8 Flash),
 * which a copied price row would miss. changeDays() lists the days any price changes, so callers
 * that sum usage over many days split it there.
 *
 * Cost = uncached input × input + cache read × cache read + cache write × cache write + output
 * × output + reasoning × reasoning + image input/output tokens × their prices + audio seconds ×
 * audio + search units × search unit, where uncached input = inputTokens − cache read − cache
 * write (core's `inputTokens` includes the cached input). Without a cache price the input price
 * applies; without a reasoning price reasoning is taken as part of the output (OpenAI and
 * Anthropic count it there; set it for providers that report it apart).
 *
 * @phpstan-type Price array{provider: string, model: string, effective_from: string|null, input: float, cache_read: float|null, cache_write: float|null, output: float, reasoning: float|null, image_input: float|null, image_output: float|null, audio_second: float|null, search_unit: float|null}
 * @phpstan-type Usage array{inputTokens: float, outputTokens: float, cacheReadInputTokens: float, cacheWriteInputTokens: float, reasoningTokens: float, imageInputTokens: float, imageOutputTokens: float, audioSeconds: float, searchUnits: float}
 */
class AiPriceService
{
    /** When DEFAULTS were checked against SOURCES. */
    public const DEFAULTS_UPDATED_ON = '2026-11-08';

    /**
     * The official pricing page each provider's DEFAULTS come from, checked on DEFAULTS_UPDATED_ON.
     *
     * @var array<string, string>
     */
    public const SOURCES = [
        'anthropic' => 'https://platform.claude.com/docs/en/about-claude/pricing',
        'openai' => 'https://developers.openai.com/api/docs/pricing',
        'gemini' => 'https://aistudio.google.com/docs/pricing',
    ];

    /**
     * Deprecated models their provider routes to and bills as another model from a day on (UTC),
     * by provider and model: [first day, billed model]. Gemini 2.5 Pro and 2.5 Flash per
     * https://aistudio.google.com/docs/deprecations, checked on DEFAULTS_UPDATED_ON. Exact model
     * names only: Google's notice names no snapshot of them.
     *
     * @var array<string, array<string, array{0: string, 1: string}>>
     */
    public const ROUTES = [
        'gemini' => [
            'gemini-2.5-pro' => ['2026-12-01', 'gemini-3.8-flash'],
            'gemini-2.5-flash' => ['2026-12-01', 'gemini-3.5-flash-lite'],
        ],
    ];

    /** The price fields of a row, after provider and model. */
    public const PRICE_FIELDS = ['input', 'cache_read', 'cache_write', 'output', 'reasoning', 'image_input', 'image_output', 'audio_second', 'search_unit'];

    /**
     * Standard-tier list prices (USD) from SOURCES: input, cache read, cache write, output per 1M
     * tokens, then reasoning, image input and image output per 1M tokens, audio per second (no
     * search unit: no reranker default). Only models an official page prices are here: better no default than a wrong
     * one. One row per model, so tiered prices use the base tier: Anthropic's cache write is the
     * 5-minute write (the 1-hour write costs more), Claude Haiku 5.5 is priced for prompts up to
     * 100k tokens and Gemini 2.5 Pro for prompts up to 200k tokens (both cost more above), and
     * Gemini is priced for text, image and video input (audio input costs more). Gemini's output
     * price includes thinking tokens. The optional 11th value is the row's `effective_from`:
     * Gemini 3.8 Flash input, cache read and output double from 2027-01-01 (#202).
     *
     * @var list<array{0: string, 1: string, 2: float, 3: float|null, 4: float|null, 5: float, 6?: float|null, 7?: float|null, 8?: float|null, 9?: float|null, 10?: string}>
     */
    private const DEFAULTS = [
        ['anthropic', 'claude-opus-5-5', 4.0, 0.2, 5.0, 20.0],
        ['anthropic', 'claude-sonnet-5-5', 2.0, 0.1, 2.5, 10.0],
        ['anthropic', 'claude-haiku-5-5', 0.1, 0.01, 0.125, 0.5],
        ['anthropic', 'claude-opus-4-8', 5.0, 0.5, 6.25, 25.0],
        ['anthropic', 'claude-sonnet-4-6', 3.0, 0.3, 3.75, 15.0],
        ['anthropic', 'claude-haiku-4-5', 1.0, 0.1, 1.25, 5.0],
        ['openai', 'gpt-5', 1.25, 0.125, null, 10.0],
        ['openai', 'gpt-5-mini', 0.25, 0.025, null, 2.0],
        ['openai', 'gpt-5-nano', 0.05, 0.005, null, 0.4],
        ['openai', 'gpt-4.1', 2.0, 0.5, null, 8.0],
        ['openai', 'gpt-4.1-mini', 0.4, 0.1, null, 1.6],
        ['openai', 'gpt-4o', 2.5, 1.25, null, 10.0],
        ['openai', 'gpt-4o-mini', 0.15, 0.075, null, 0.6],
        ['openai', 'text-embedding-3-small', 0.02, null, null, 0.0],
        ['openai', 'text-embedding-3-large', 0.13, null, null, 0.0],
        // $0.006 per minute.
        ['openai', 'whisper-1', 0.0, null, null, 0.0, null, null, null, 0.0001],
        ['gemini', 'gemini-2.5-pro', 1.25, 0.125, null, 10.0],
        ['gemini', 'gemini-2.5-flash', 0.3, 0.03, null, 2.5],
        ['gemini', 'gemini-3.8-flash', 0.75, 0.075, null, 3.75],
        ['gemini', 'gemini-3.8-flash', 1.5, 0.15, null, 7.5, null, null, null, null, '2027-01-01'],
        ['gemini', 'gemini-3.5-flash-lite', 0.3, 0.03, null, 2.5],
    ];

    /** @var list<Price>|null */
    private ?array $prices = null;

    public function __construct(private SettingsRepositoryService $settings) {}

    /**
     * The seeded rows.
     *
     * @return list<Price>
     */
    public function defaults(): array
    {
        return array_values(array_map(fn (array $row): array => [
            'provider' => $row[0],
            'model' => $row[1],
            'effective_from' => $row[10] ?? null,
            'input' => $row[2],
            'cache_read' => $row[3],
            'cache_write' => $row[4],
            'output' => $row[5],
            'reasoning' => $row[6] ?? null,
            'image_input' => $row[7] ?? null,
            'image_output' => $row[8] ?? null,
            'audio_second' => $row[9] ?? null,
            // No official page prices a reranker the defaults could hold (#165).
            'search_unit' => null,
        ], self::DEFAULTS));
    }

    /**
     * The saved table, or the defaults until Settings → AI prices is saved.
     *
     * @return list<Price>
     */
    public function prices(): array
    {
        $stored = $this->settings->all()['ai_prices'];

        return $this->prices ??= is_array($stored) ? array_values(array_map($this->normalize(...), array_filter($stored, 'is_array'))) : $this->defaults();
    }

    /**
     * The date of the table: the day it was last saved, else the defaults' date.
     */
    public function updatedOn(): string
    {
        $stored = $this->settings->all()['ai_prices_updated_on'];

        return is_string($stored) && $stored !== '' ? $stored : self::DEFAULTS_UPDATED_ON;
    }

    /**
     * The price row of a provider and model in force on a day (today by default), or null when
     * the table has none: the exact model before a snapshot of a shorter one, then the row with
     * the latest `effective_from` on or before the day (a row without one is in force since forever).
     *
     * @return Price|null
     */
    public function priceFor(string $provider, string $model, ?CarbonInterface $on = null): ?array
    {
        $provider = mb_strtolower($provider);
        $model = mb_strtolower($model);
        $day = ($on ?? Date::now())->toDateString();
        $best = null;
        $bestRank = null;

        foreach ($this->prices() as $price) {
            $priced = mb_strtolower($price['model']);
            $effectiveFrom = $price['effective_from'] ?? '';

            if (mb_strtolower($price['provider']) !== $provider || $effectiveFrom > $day) {
                continue;
            }

            // A dated or versioned snapshot of a priced model: `-2024-08-06`, `-20250929`, `@20251101`, `-latest`, `-001`.
            $isSnapshot = str_starts_with($model, $priced)
                && preg_match('/^[-@](\d{4}-?\d{2}-?\d{2}|\d{3,}|latest|preview)$/', substr($model, strlen($priced))) === 1;

            if ($priced !== $model && ! $isSnapshot) {
                continue;
            }

            // Exact match first, then the longest priced model, then the latest row in force.
            $rank = [$priced === $model, strlen($priced), $effectiveFrom];

            if ($bestRank === null || $rank > $bestRank) {
                $best = $price;
                $bestRank = $rank;
            }
        }

        return $best;
    }

    /**
     * The days (UTC, `Y-m-d`, ascending) on which some price changes: every `effective_from` of
     * the table and the first day of every route.
     *
     * @return list<string>
     */
    public function changeDays(): array
    {
        $days = array_merge(
            array_filter(array_column($this->prices(), 'effective_from')),
            array_column(array_merge(...array_values(self::ROUTES)), 0),
        );
        $days = array_values(array_unique($days));
        sort($days);

        return $days;
    }

    /**
     * The price period a day falls in: the last change day on or before it, '' before the first.
     * Usage of one model within one period has one price, so sums are priced per period.
     */
    public function periodOf(CarbonInterface $on): string
    {
        $day = $on->toDateString();
        $period = '';

        foreach ($this->changeDays() as $changeDay) {
            if ($changeDay <= $day) {
                $period = $changeDay;
            }
        }

        return $period;
    }

    /**
     * The model a provider bills some usage of a model on a day as: its ROUTES model from the
     * route's first day, else the model itself.
     */
    public function billedModel(string $provider, string $model, CarbonInterface $on): string
    {
        $route = self::ROUTES[mb_strtolower($provider)][mb_strtolower($model)] ?? null;

        return $route !== null && $on->toDateString() >= $route[0] ? $route[1] : $model;
    }

    /**
     * The estimated cost (USD) of some usage of a provider and model on a day, at the row in force
     * that day; null when the model, as billed that day (billedModel), has no price, 0.0 when
     * nothing was used.
     *
     * @param  Usage  $usage  estimated real amounts
     */
    public function cost(string $provider, string $model, array $usage, CarbonInterface $on): ?float
    {
        if (array_sum($usage) <= 0) {
            return 0.0;
        }

        $price = $this->priceFor($provider, $this->billedModel($provider, $model, $on), $on);

        if ($price === null) {
            return null;
        }

        $cacheRead = $usage['cacheReadInputTokens'];
        $cacheWrite = $usage['cacheWriteInputTokens'];
        $uncached = max(0.0, $usage['inputTokens'] - $cacheRead - $cacheWrite);
        $perToken = $uncached * $price['input']
            + $cacheRead * ($price['cache_read'] ?? $price['input'])
            + $cacheWrite * ($price['cache_write'] ?? $price['input'])
            + $usage['outputTokens'] * $price['output']
            + $usage['reasoningTokens'] * ($price['reasoning'] ?? 0.0)
            + $usage['imageInputTokens'] * ($price['image_input'] ?? 0.0)
            + $usage['imageOutputTokens'] * ($price['image_output'] ?? 0.0);

        return $perToken / 1_000_000
            + $usage['audioSeconds'] * ($price['audio_second'] ?? 0.0)
            + $usage['searchUnits'] * ($price['search_unit'] ?? 0.0);
    }

    /**
     * The estimated cost of a project's daily AI usage from one day to another (both included),
     * with whether any of it has no price.
     *
     * @return array{cost: float, calls: float, hasUnpriced: bool}
     */
    public function costOfDays(Project $project, CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = AiUsageDay::query()
            ->where('project_id', $project->id)
            // A half-open range: SQLite stores a model-written date with its time.
            ->where('day', '>=', $from->toDateString())
            ->where('day', '<', $to->copy()->addDay()->toDateString())
            ->get();
        $total = ['cost' => 0.0, 'calls' => 0.0, 'hasUnpriced' => false];

        // Days of another price period (a dated row or a route, changeDays()) are priced apart.
        foreach ($rows->groupBy(fn (AiUsageDay $day): string => $day->usage_key."\0".$this->periodOf($day->day)) as $days) {
            /** @var AiUsageDay $first */
            $first = $days->first();
            $usage = [];

            foreach (AiUsageDay::USAGE_COLUMNS as $metric => $column) {
                $usage[$metric] = (float) $days->sum($column);
            }

            /** @var Usage $usage */
            $cost = $this->cost($first->provider, $first->model, $usage, $first->day);
            $total['calls'] += (float) $days->sum('calls');
            $total['cost'] += $cost ?? 0.0;
            $total['hasUnpriced'] = $total['hasUnpriced'] || $cost === null;
        }

        return $total;
    }

    /**
     * A stored row with its numbers as floats (or null), so old or hand-edited rows read safely.
     *
     * @param  array<mixed>  $row
     * @return Price
     */
    private function normalize(array $row): array
    {
        $number = fn (string $field): ?float => is_numeric($row[$field] ?? null) ? (float) $row[$field] : null;
        $effectiveFrom = $row['effective_from'] ?? null;

        return [
            'provider' => (string) ($row['provider'] ?? ''),
            'model' => (string) ($row['model'] ?? ''),
            // Rows saved before #202 have no date: in force since forever.
            'effective_from' => is_string($effectiveFrom) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveFrom) === 1 ? $effectiveFrom : null,
            ...array_combine(self::PRICE_FIELDS, array_map($number, self::PRICE_FIELDS)),
            'input' => $number('input') ?? 0.0,
            'output' => $number('output') ?? 0.0,
        ];
    }
}

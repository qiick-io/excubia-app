<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Models\Setting;
use App\Services\AiPriceService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Date;

/**
 * Settings → AI prices (#129): the price rows per provider and model, in USD (tokens per 1M,
 * audio per second, search units per unit). Input and output prices are required, the others
 * optional. A row may start on a day (`effective_from`, UTC, #202; empty for since forever), so a
 * provider and model appear once per start day. Needs `can-manage-alerts`, like the budgets and
 * alert rules the prices feed.
 */
class UpdateAiPricesSettingsRequest extends FormRequest
{
    /** Rows the table holds at most. */
    public const MAX_ROWS = 200;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manageAlerts', Setting::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'prices' => ['present', 'list', 'max:'.self::MAX_ROWS],
            'prices.*' => ['array'],
            'prices.*.provider' => ['required', 'string', 'max:100'],
            'prices.*.model' => ['required', 'string', 'max:200'],
            'prices.*.effective_from' => ['nullable', 'date_format:Y-m-d'],
        ];

        foreach (AiPriceService::PRICE_FIELDS as $field) {
            $rules["prices.*.{$field}"] = [in_array($field, ['input', 'output'], true) ? 'required' : 'nullable', 'numeric', 'min:0', 'max:100000'];
        }

        return $rules;
    }

    /**
     * A provider and model listed twice from the same day is an error on the second row.
     *
     * @return list<callable>
     */
    public function after(): array
    {
        return [function ($validator): void {
            $seen = [];

            foreach ((array) $this->input('prices', []) as $index => $row) {
                $key = mb_strtolower(trim((string) ($row['provider'] ?? ''))."\0".trim((string) ($row['model'] ?? ''))."\0".trim((string) ($row['effective_from'] ?? '')));

                if (isset($seen[$key])) {
                    $validator->errors()->add("prices.{$index}.model", __('This provider and model are already listed from this day.'));
                }

                $seen[$key] = true;
            }
        }];
    }

    /**
     * @return array{ai_prices: list<array<string, string|float|null>>, ai_prices_updated_on: string}
     */
    public function settings(): array
    {
        $rows = [];

        foreach ((array) $this->input('prices', []) as $row) {
            $effectiveFrom = trim((string) ($row['effective_from'] ?? ''));
            $price = [
                'provider' => mb_strtolower(trim((string) $row['provider'])),
                'model' => trim((string) $row['model']),
                'effective_from' => $effectiveFrom === '' ? null : $effectiveFrom,
            ];

            foreach (AiPriceService::PRICE_FIELDS as $field) {
                $price[$field] = is_numeric($row[$field] ?? null) ? (float) $row[$field] : null;
            }

            $rows[] = $price;
        }

        return ['ai_prices' => $rows, 'ai_prices_updated_on' => Date::now()->toDateString()];
    }
}

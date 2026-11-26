<?php

declare(strict_types=1);

namespace App\Rules;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;
use Throwable;

/**
 * The local end (`Y-m-d\TH:i`) of a one-off maintenance window, read in the window's own
 * `timezone`, must be in the future (#437): a window that already ended silences nothing, so it
 * is a wrong year or timezone the user should hear about. `after:now` would compare in UTC.
 */
class EndsInFutureRule implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    /**
     * @param  array<string, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $timezone = is_string($this->data['timezone'] ?? null) ? $this->data['timezone'] : 'UTC';

        try {
            $end = CarbonImmutable::createFromFormat('Y-m-d\TH:i', (string) $value, $timezone);
        } catch (Throwable) {
            return; // date_format reports a malformed value.
        }

        if ($end !== null && $end->isPast()) {
            $fail(__('The end must be in the future: this window would silence nothing.'));
        }
    }
}

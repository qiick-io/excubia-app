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
 * The local start and end (`Y-m-d\TH:i`) of a one-off maintenance window, read in the window's
 * own `timezone` (#437), the way CreateMaintenanceWindowAction stores them:
 *
 * - a time that does not exist there (the hour skipped when clocks go forward) is refused, as it
 *   would be stored shifted;
 * - on the end: it must come after the start, compared as instants (not wall times, which differ
 *   around a clock change), and be in the future, since a window that already ended silences
 *   nothing. `after:now` would compare in UTC.
 */
class OneOffWindowTimeRule implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(private bool $isEnd = true) {}

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
        $instant = self::instant($value, $timezone);

        if ($instant === false) {
            $fail(__('This time does not exist in the chosen timezone (the clocks skip it).'));

            return;
        }

        if ($instant === null || ! $this->isEnd) {
            return;
        }

        $start = self::instant($this->data['starts_at'] ?? null, $timezone);

        if ($start instanceof CarbonImmutable && ! $instant->isAfter($start)) {
            $fail(__('The end must be after the start.'));

            return;
        }

        if (! $instant->isFuture()) {
            $fail(__('The end must be in the future: this window would silence nothing.'));
        }
    }

    /**
     * The local time as an instant; null when it is not a well-formed local time (date_format
     * reports that), false when the timezone skips it.
     */
    private static function instant(mixed $value, string $timezone): CarbonImmutable|false|null
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            $instant = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $value, $timezone);
        } catch (Throwable) {
            return null;
        }

        if (! $instant instanceof CarbonImmutable) {
            return null;
        }

        return $instant->format('Y-m-d\TH:i') === $value ? $instant : false;
    }
}

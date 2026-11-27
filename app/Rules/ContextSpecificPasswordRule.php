<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/** Rejects passwords built from public Excubia identifiers and role labels. */
final readonly class ContextSpecificPasswordRule implements ValidationRule
{
    /** @var list<string> Normalized forms of the vocabulary in docs/SECURITY-MODEL.md. */
    private const array TERMS = ['excubia', 'excubiaapp', 'excubiacore', 'qiick', 'excubiasystem', 'superadmin', 'admin', 'reader', 'lettore', 'leser'];

    /** @var list<string> */
    private array $normalizedTerms;

    /** @param list<string> $deploymentTerms */
    public function __construct(array $deploymentTerms)
    {
        $this->normalizedTerms = [...self::TERMS, ...array_map(self::normalize(...), $deploymentTerms)];
    }

    /** @param Closure(string, ?string=): PotentiallyTranslatedString $fail */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $candidate = self::normalize($value);

        if ($candidate !== '' && in_array($candidate, $this->normalizedTerms, true)) {
            $fail('validation.password.context_specific')->translate();
        }
    }

    /** Compare the whole value after removing separators and predictable number affixes. */
    private static function normalize(string $value): string
    {
        return trim(mb_convert_case(str_replace([' ', '-', '_'], '', $value), MB_CASE_FOLD, 'UTF-8'), '0..9');
    }
}

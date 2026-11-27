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

    /** The substitution for each letter lets a single password use `1` for both `i` and `l`. */
    private const array LEETSPEAK = ['a' => '[a4]', 'e' => '[e3]', 'i' => '[i1]', 'l' => '[l1]', 'o' => '[o0]', 's' => '[s5]'];

    /** @var list<string> */
    private array $normalizedTerms;

    /** @param list<string> $deploymentTerms */
    public function __construct(array $deploymentTerms)
    {
        $normalizedDeploymentTerms = array_map(
            fn (string $term): string => trim(self::alphanumeric($term), '0..9'),
            $deploymentTerms,
        );

        // Empty entries would match every password. Short words often occur in unrelated phrases.
        $this->normalizedTerms = [...self::TERMS, ...array_filter(
            $normalizedDeploymentTerms,
            fn (string $term): bool => mb_strlen($term, 'UTF-8') >= 4,
        )];
    }

    /** @param Closure(string, ?string=): PotentiallyTranslatedString $fail */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $candidate = trim(self::alphanumeric($value), '0..9');
        $withoutInsertedDigits = preg_replace('/[0-9]+/', '', $candidate) ?? '';
        $leetCandidate = self::alphanumeric(str_replace('@', 'a', $value));

        foreach ($this->normalizedTerms as $term) {
            if (str_contains($candidate, $term)
                || str_contains($withoutInsertedDigits, $term)
                || preg_match('/'.strtr($term, self::LEETSPEAK).'/u', $leetCandidate) === 1) {
                $fail('validation.password.context_specific')->translate();

                return;
            }
        }
    }

    /** Returns only Unicode letters and numbers, folded for case-insensitive matching. */
    private static function alphanumeric(string $value): string
    {
        return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_convert_case($value, MB_CASE_FOLD, 'UTF-8')) ?? '';
    }
}

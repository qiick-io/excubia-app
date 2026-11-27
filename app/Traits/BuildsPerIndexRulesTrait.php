<?php

declare(strict_types=1);

namespace App\Traits;

/**
 * Builds the rules of the pushed lists (`report.checks`, `exceptions.groups`) per index, as
 * `/api/runtime` does since #195 (#238). The validator expands a wildcard rule over the whole
 * flattened subtree it covers, so unknown fields a later excubia-core nests inside a check or a
 * group (core 1.1 may add any) were walked once per rule: about 0.4 s for 1 MB.
 *
 * The expansion yields the keys the wildcard would, in the same order (rule by rule, then index
 * by index), and names them in messages as the validator names a wildcard's keys (raw, not
 * `first seen`), so what is rejected, the error keys and the messages stay the same. The caller
 * puts the expanded rules last, where the validator appends a wildcard's keys. An array whose
 * keys hold a dot keeps its wildcard, which the validator then expands over that array alone.
 */
trait BuildsPerIndexRulesTrait
{
    /** @var array<string, string> the expanded keys, each displayed as itself */
    private array $perIndexAttributes = [];

    /**
     * Custom attribute names (FormRequest): the keys perIndexRules() built from a wildcard.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->perIndexAttributes;
    }

    /**
     * @param  array<string, mixed>  $rules  keys may contain `*`
     * @return array<string, mixed>
     */
    private function perIndexRules(array $rules): array
    {
        $expanded = [];

        foreach ($rules as $key => $fieldRules) {
            foreach (self::perIndexKeys(explode('.', $key), $this->all(), '') as $expandedKey) {
                $expanded[$expandedKey] = $fieldRules;

                if (str_contains($key, '*') && ! str_contains($expandedKey, '*')) {
                    $this->perIndexAttributes[$expandedKey] = $expandedKey;
                }
            }
        }

        return $expanded;
    }

    /**
     * The keys of one rule: a named segment always continues (the validator fills a missing
     * field with null), a `*` continues once per element of an array and matches nothing on
     * a value that is no array.
     *
     * @param  list<string>  $segments
     * @return list<string>
     */
    private static function perIndexKeys(array $segments, mixed $value, string $prefix): array
    {
        if ($segments === []) {
            return [rtrim($prefix, '.')];
        }

        $segment = array_shift($segments);

        if ($segment !== '*') {
            return self::perIndexKeys($segments, is_array($value) ? ($value[$segment] ?? null) : null, $prefix.$segment.'.');
        }

        if (! is_array($value)) {
            return [];
        }

        // A key with a dot is split by the validator's flattening: only the wildcard matches it as before.
        foreach (array_keys($value) as $index) {
            if (str_contains((string) $index, '.')) {
                return [$prefix.implode('.', ['*', ...$segments])];
            }
        }

        $keys = [];

        foreach ($value as $index => $element) {
            array_push($keys, ...self::perIndexKeys($segments, $element, $prefix.$index.'.'));
        }

        return $keys;
    }
}

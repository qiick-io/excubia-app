<?php

declare(strict_types=1);

namespace Qiick\Standards\Conformance\Support;

/**
 * Reads and validates `qiick-standards.json` conformance divergences.
 *
 * A product may skip a check only with:
 * `{ "C12": { "reason": "...", "decision": "knowledge/decisions/...", "review_by": "YYYY-MM-DD" } }`
 *
 * Registry-item divergences stay string reasons and are ignored here.
 */
final class DivergenceGate
{
    /**
     * @param array<string, array{reason: string, decision: string, review_by: string}> $divergences
     * @param list<string> $knownCheckIds
     */
    public function __construct(
        private readonly array $divergences,
        private readonly array $knownCheckIds,
        private readonly \DateTimeImmutable $today,
    ) {}

    /**
     * @param list<string> $knownCheckIds
     */
    public static function fromBasePath(string $basePath, array $knownCheckIds, ?\DateTimeImmutable $today = null): self
    {
        $path = $basePath.'/qiick-standards.json';
        $divergences = [];

        if (is_file($path)) {
            /** @var array{divergences?: array<string, mixed>} $decoded */
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

            foreach ($decoded['divergences'] ?? [] as $key => $value) {
                if (! is_array($value)) {
                    // Registry coverage keeps string reasons; skip those here.
                    continue;
                }

                $reason = $value['reason'] ?? null;
                $decision = $value['decision'] ?? null;
                $reviewBy = $value['review_by'] ?? null;

                if (! is_string($reason) || trim($reason) === '') {
                    throw new \RuntimeException("Divergence \"{$key}\" needs a non-empty reason.");
                }

                if (! is_string($decision) || trim($decision) === '') {
                    throw new \RuntimeException("Divergence \"{$key}\" needs a decision path.");
                }

                if (! is_string($reviewBy) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $reviewBy)) {
                    throw new \RuntimeException("Divergence \"{$key}\" needs review_by as YYYY-MM-DD.");
                }

                $divergences[$key] = [
                    'reason' => $reason,
                    'decision' => $decision,
                    'review_by' => $reviewBy,
                ];
            }
        }

        return new self($divergences, $knownCheckIds, $today ?? new \DateTimeImmutable('today'));
    }

    /**
     * @return array{reason: string, decision: string, review_by: string}|null
     */
    public function for(string $checkId): ?array
    {
        return $this->divergences[$checkId] ?? null;
    }

    public function isDiverged(string $checkId): bool
    {
        return isset($this->divergences[$checkId]);
    }

    /**
     * Validate unknown ids and expired review_by dates.
     *
     * @throws \RuntimeException
     */
    public function assertDeclarationsAreValid(): void
    {
        $known = array_flip($this->knownCheckIds);

        foreach ($this->divergences as $checkId => $divergence) {
            if (! isset($known[$checkId])) {
                throw new \RuntimeException(
                    "Unknown conformance check id \"{$checkId}\" in divergences.",
                );
            }

            $reviewBy = \DateTimeImmutable::createFromFormat('Y-m-d', $divergence['review_by']);

            if ($reviewBy === false) {
                throw new \RuntimeException("Divergence \"{$checkId}\" has an invalid review_by.");
            }

            if ($reviewBy < $this->today) {
                throw new \RuntimeException(
                    "Divergence \"{$checkId}\" review_by {$divergence['review_by']} has expired; re-review or remove it.",
                );
            }
        }
    }

    /**
     * @param list<string> $passingCheckIds Check ids that ran green without needing a skip
     *
     * @throws \RuntimeException
     */
    public function assertNoStaleDeclarations(array $passingCheckIds): void
    {
        $passing = array_flip($passingCheckIds);

        foreach (array_keys($this->divergences) as $checkId) {
            if (isset($passing[$checkId])) {
                throw new \RuntimeException(
                    "Divergence \"{$checkId}\" is stale: the check passes. Remove it from qiick-standards.json.",
                );
            }
        }
    }

    /**
     * @return list<string>
     */
    public function declaredCheckIds(): array
    {
        return array_keys($this->divergences);
    }
}

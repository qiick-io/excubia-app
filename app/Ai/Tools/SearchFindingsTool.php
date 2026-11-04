<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Enums\SeverityEnum;
use App\Services\AssistantQueryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Findings across projects, filtered by severity, package, advisory, check or dates.
 */
class SearchFindingsTool extends AssistantTool
{
    public function description(): Stringable|string
    {
        return 'Search the findings that warn or fail across all projects (or one). By default it reads the latest report of each project; with from/to it reads every report received in that date range. Returns at most '.AssistantQueryService::SEARCH_LIMIT.' findings and says when the list is truncated.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('Limit to one project slug.'),
            'minimum_severity' => $schema->string()->enum(SeverityEnum::values())->description('Only findings at this severity or worse.'),
            'package' => $schema->string()->description('Part of the subject, usually a Composer or npm package name such as guzzlehttp/guzzle.'),
            'advisory_id' => $schema->string()->description('Exact advisory id, such as GHSA-xxxx or CVE-xxxx.'),
            'check_id' => $schema->string()->description('Exact check id, such as composer-audit.'),
            'from' => $schema->string()->description('Start date, YYYY-MM-DD.'),
            'to' => $schema->string()->description('End date, YYYY-MM-DD.'),
        ];
    }

    protected function read(AssistantQueryService $query, Request $request): ?array
    {
        return $query->searchFindings([
            'project' => $request['project'] ?? null,
            'minimumSeverity' => $request['minimum_severity'] ?? null,
            'package' => $request['package'] ?? null,
            'advisoryId' => $request['advisory_id'] ?? null,
            'checkId' => $request['check_id'] ?? null,
            'from' => $this->date($request['from'] ?? null),
            'to' => $this->date($request['to'] ?? null),
        ]);
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }
}

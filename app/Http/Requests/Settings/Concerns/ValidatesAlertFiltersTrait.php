<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings\Concerns;

use App\Data\AlertFilterData;
use App\Enums\AlertEventEnum;
use App\Enums\SeverityEnum;
use Illuminate\Validation\Rule;

/**
 * Validation of an alert filter (#22) under a key (`channels.slack`, `alerts`): a required
 * minimum severity, the event types (none checked: no event) and the projects (none: all).
 */
trait ValidatesAlertFiltersTrait
{
    /**
     * @param  array<mixed>  $projectIds  the project ids the filter may name, for Rule::in()
     * @return array<string, array<mixed>>
     */
    protected function alertFilterRules(string $key, array $projectIds): array
    {
        return [
            "{$key}.minimum_severity" => ['required', Rule::enum(SeverityEnum::class)],
            "{$key}.event_types" => ['nullable', 'array'],
            "{$key}.event_types.*" => ['string', 'distinct', Rule::in(AlertEventEnum::selectableValues())],
            "{$key}.project_ids" => ['nullable', 'array'],
            "{$key}.project_ids.*" => ['integer', 'distinct', Rule::in($projectIds)],
        ];
    }

    protected function alertFilter(string $key): AlertFilterData
    {
        return new AlertFilterData(
            SeverityEnum::from($this->string("{$key}.minimum_severity")->toString()),
            array_values(array_map('strval', (array) $this->input("{$key}.event_types", []))),
            array_values(array_map('intval', (array) $this->input("{$key}.project_ids", []))),
        );
    }
}

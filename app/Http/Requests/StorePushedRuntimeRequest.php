<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\RuntimeTypeEnum;
use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a runtime push of excubia-core (`docs/push-schema.json` `$defs.runtimeEnvelope`,
 * payload `docs/runtime-schema.json`, protocol version 1). Each aggregate of a known type must
 * carry the dimensions and metrics of its type; an aggregate of a type this app does not know
 * only needs a `type` and is skipped on store, never rejected (#111). The token was checked by
 * AuthenticateProjectToken.
 */
class StorePushedRuntimeRequest extends FormRequest
{
    private const STATUS_CLASSES = ['1xx', '2xx', '3xx', '4xx', '5xx'];

    /** Dimensions limited to a set of values, per type (`docs/runtime-schema.json`). */
    private const DIMENSION_VALUES = [
        'request' => ['statusClass' => self::STATUS_CLASSES],
        'outgoingHttp' => ['statusClass' => self::STATUS_CLASSES],
        'job' => ['outcome' => ['processed', 'failed']],
        'scheduledTask' => ['outcome' => ['finished', 'failed', 'skipped']],
        'logLevel' => ['level' => ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'unknown']],
        'duplicateQuery' => ['origin' => ['request', 'job']],
        'command' => ['exitCodeClass' => ['0', '1', 'other']],
    ];

    /** Validation of the type-specific metrics (`RuntimeTypeEnum::metrics()`). */
    private const METRIC_RULES = [
        'totalMb' => ['required', 'numeric', 'min:0'],
        'maxMb' => ['required', 'numeric', 'min:0'],
        'hits' => ['required', 'integer', 'min:0'],
        'misses' => ['required', 'integer', 'min:0'],
        'writes' => ['required', 'integer', 'min:0'],
        'deletes' => ['required', 'integer', 'min:0'],
        'totalRepeats' => ['required', 'integer', 'min:2'],
        'maxRepeats' => ['required', 'integer', 'min:2'],
        'loadAverage1' => ['present', 'nullable', 'numeric', 'min:0'],
        'loadAverage5' => ['present', 'nullable', 'numeric', 'min:0'],
        'loadAverage15' => ['present', 'nullable', 'numeric', 'min:0'],
        'cpuCores' => ['present', 'nullable', 'integer', 'min:1'],
        'cpuLimit' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        'memoryUsedMb' => ['present', 'nullable', 'integer', 'min:0'],
        'memoryTotalMb' => ['present', 'nullable', 'integer', 'min:0'],
        'diskUsedGb' => ['present', 'nullable', 'numeric', 'min:0'],
        'diskTotalGb' => ['present', 'nullable', 'numeric', 'min:0'],
        'isContainer' => ['sometimes', 'boolean'],
    ];

    public function authorize(): bool
    {
        return $this->project() instanceof Project;
    }

    /**
     * The envelope, then one rule set per aggregate of a known type.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'protocolVersion' => ['required', 'integer', Rule::in(StorePushedReportRequest::PROTOCOL_VERSIONS)],
            'type' => ['required', 'in:runtime'],
            'batchId' => ['required', 'string', 'regex:/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/'],
            'sentAt' => ['required', 'date'],
            'runtime' => ['required', 'array'],
            'runtime.release' => ['sometimes', 'string', 'max:100'],
            'runtime.bucketSeconds' => ['required', 'integer', 'in:60'],
            'runtime.aggregates' => ['present', 'list'],
            'runtime.aggregates.*' => ['array'],
            'runtime.aggregates.*.type' => ['required', 'string'],
        ];

        foreach ((array) $this->input('runtime.aggregates', []) as $index => $aggregate) {
            $type = is_array($aggregate) && is_string($aggregate['type'] ?? null) ? RuntimeTypeEnum::tryFrom($aggregate['type']) : null;

            if ($type === null) {
                continue;
            }

            foreach ($this->aggregateRules($type) as $field => $fieldRules) {
                $rules["runtime.aggregates.{$index}.{$field}"] = $fieldRules;
            }
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'protocolVersion.in' => 'Unsupported protocolVersion. This Excubia app accepts: '.implode(', ', StorePushedReportRequest::PROTOCOL_VERSIONS).'. Update qiick/excubia-core or the app.',
        ];
    }

    public function project(): ?Project
    {
        $project = $this->attributes->get('project');

        return $project instanceof Project ? $project : null;
    }

    /**
     * Rules for one aggregate of a known type, keyed by its field (`histogram.*` included).
     * Dimensions are strings (empty allowed, as core sends an empty queue name); those with a
     * fixed set of values must be one of them.
     *
     * @return array<string, list<mixed>>
     */
    private function aggregateRules(RuntimeTypeEnum $type): array
    {
        $rules = [
            'bucket' => ['required', 'string', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:00Z$/'],
            'sampleRate' => ['required', 'numeric', 'gt:0', 'max:1'],
            'count' => ['required', 'integer', 'min:1'],
            'errorCount' => ['required', 'integer', 'min:0'],
        ];

        foreach ($type->dimensions() as $dimension) {
            $values = self::DIMENSION_VALUES[$type->value][$dimension] ?? null;
            $rules[$dimension] = $values === null ? ['present', 'string', 'max:2000'] : ['required', 'string', Rule::in($values)];
        }

        foreach ($type->optionalDimensions() as $dimension) {
            $rules[$dimension] = ['sometimes', 'string', 'max:2000'];
        }

        if ($type->isTimed()) {
            $rules['totalMs'] = ['required', 'numeric', 'min:0'];
            $rules['maxMs'] = ['required', 'numeric', 'min:0'];
            $rules['histogram'] = ['sometimes', 'list', 'size:'.RuntimeTypeEnum::HISTOGRAM_LENGTH];
            $rules['histogram.*'] = ['integer', 'min:0'];
        }

        foreach (array_keys($type->metrics()) as $metric) {
            $rules[$metric] = self::METRIC_RULES[$metric];
        }

        return $rules;
    }
}

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
 * only needs a `type` and is skipped on store, never rejected (#111). A value core enumerates
 * (`statusClass`, `outcome`, `level`, `origin`, `exitCodeClass`, `operation`, `octaneServer`,
 * `workerType`, the billing `provider` and `eventType`, the auth `guard`, the health `status` and `severity`) is only checked as a string: core adds values
 * additively, and a value this app does not know must never reject the batch (#169). StorePushedRuntimeAction stores it, or
 * skips and counts the entry when the app cannot read it. The token was checked by
 * AuthenticateProjectTokenMiddleware.
 */
class StorePushedRuntimeRequest extends FormRequest
{
    /** Dimensions with a pattern (`docs/runtime-schema.json`); unknown check ids are accepted. */
    private const DIMENSION_PATTERNS = [
        'health' => ['checkId' => '/^[a-z0-9-]{1,64}$/'],
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
        // Unknown values are skipped and counted on store (#169).
        'status' => ['required', 'string', 'max:64'],
        'severity' => ['required', 'string', 'max:64'],
        'findingCount' => ['required', 'integer', 'min:0'],
        'fingerprints' => ['present', 'list', 'max:'.RuntimeTypeEnum::HEALTH_FINGERPRINTS],
        'fingerprints.*' => ['string', 'regex:/^[0-9a-f]{64}$/'],
        // `ai` (#129): usage counts are null when the provider does not report them.
        'failoverCount' => ['required', 'integer', 'min:0'],
        'inputTokens' => ['present', 'nullable', 'integer', 'min:0'],
        'outputTokens' => ['present', 'nullable', 'integer', 'min:0'],
        'cacheReadInputTokens' => ['present', 'nullable', 'integer', 'min:0'],
        'cacheWriteInputTokens' => ['present', 'nullable', 'integer', 'min:0'],
        'reasoningTokens' => ['present', 'nullable', 'integer', 'min:0'],
        'imageInputTokens' => ['present', 'nullable', 'integer', 'min:0'],
        'imageOutputTokens' => ['present', 'nullable', 'integer', 'min:0'],
        'audioSeconds' => ['present', 'nullable', 'numeric', 'min:0'],
        'searchUnits' => ['present', 'nullable', 'numeric', 'min:0'],
        // `octane` (#168): every metric always present, 0 without that event (`maxMb` above).
        'workerStarts' => ['required', 'integer', 'min:0'],
        'workerStops' => ['required', 'integer', 'min:0'],
        'requests' => ['required', 'integer', 'min:0'],
        'tasks' => ['required', 'integer', 'min:0'],
        'ticks' => ['required', 'integer', 'min:0'],
        'totalRequestsAtStop' => ['required', 'integer', 'min:0'],
        'maxRequestsAtStop' => ['required', 'integer', 'min:0'],
        'maxGrowthMb' => ['required', 'numeric', 'min:0'],
        // `reverb` (#175): every metric always present, 0 without that event.
        'connectionsOpened' => ['required', 'integer', 'min:0'],
        'connectionsRejected' => ['required', 'integer', 'min:0'],
        'connectionsPruned' => ['required', 'integer', 'min:0'],
        'messagesReceived' => ['required', 'integer', 'min:0'],
        'messagesSent' => ['required', 'integer', 'min:0'],
        'publicChannelsCreated' => ['required', 'integer', 'min:0'],
        'privateChannelsCreated' => ['required', 'integer', 'min:0'],
        'presenceChannelsCreated' => ['required', 'integer', 'min:0'],
        'publicChannelsRemoved' => ['required', 'integer', 'min:0'],
        'privateChannelsRemoved' => ['required', 'integer', 'min:0'],
        'presenceChannelsRemoved' => ['required', 'integer', 'min:0'],
        'maxConnections' => ['required', 'integer', 'min:0'],
        // `billingWebhook` (#178): every counter always present, 0 without that event.
        'handledCount' => ['required', 'integer', 'min:0'],
        'paymentFailedCount' => ['required', 'integer', 'min:0'],
        'paymentActionRequiredCount' => ['required', 'integer', 'min:0'],
        'subscriptionCanceledCount' => ['required', 'integer', 'min:0'],
        // `auth` (#180): every counter always present, 0 without that event.
        'loginCount' => ['required', 'integer', 'min:0'],
        'lockoutCount' => ['required', 'integer', 'min:0'],
        'tokenAuthenticationCount' => ['required', 'integer', 'min:0'],
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
     * Dimensions are strings (empty allowed, as core sends an empty queue name), enumerated ones
     * included (#169); those with a pattern must match it.
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
            $pattern = self::DIMENSION_PATTERNS[$type->value][$dimension] ?? null;
            $rules[$dimension] = $pattern === null ? ['present', 'string', 'max:2000'] : ['required', 'string', 'regex:'.$pattern];
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

            if (isset(self::METRIC_RULES[$metric.'.*'])) {
                $rules[$metric.'.*'] = self::METRIC_RULES[$metric.'.*'];
            }
        }

        return $rules;
    }
}

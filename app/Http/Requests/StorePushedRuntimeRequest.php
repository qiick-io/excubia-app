<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\RuntimeTypeEnum;
use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates a runtime push of excubia-core (`docs/push-schema.json` `$defs.runtimeEnvelope`,
 * payload `docs/runtime-schema.json`, protocol version 1). Each aggregate must carry the
 * dimensions of its type as strings. The token was checked by AuthenticateProjectToken.
 */
class StorePushedRuntimeRequest extends FormRequest
{
    private const STATUS_CLASSES = ['1xx', '2xx', '3xx', '4xx', '5xx'];

    private const OUTCOMES = [
        'job' => ['processed', 'failed'],
        'scheduledTask' => ['finished', 'failed', 'skipped'],
    ];

    public function authorize(): bool
    {
        return $this->project() instanceof Project;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'protocolVersion' => ['required', 'integer', Rule::in(StorePushedReportRequest::PROTOCOL_VERSIONS)],
            'type' => ['required', 'in:runtime'],
            'batchId' => ['required', 'string', 'regex:/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/'],
            'sentAt' => ['required', 'date'],
            'runtime' => ['required', 'array'],
            'runtime.bucketSeconds' => ['required', 'integer', 'in:60'],
            'runtime.aggregates' => ['present', 'list'],
            'runtime.aggregates.*' => ['array'],
            'runtime.aggregates.*.type' => ['required', Rule::in(RuntimeTypeEnum::values())],
            'runtime.aggregates.*.bucket' => ['required', 'string', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:00Z$/'],
            'runtime.aggregates.*.sampleRate' => ['required', 'numeric', 'gt:0', 'max:1'],
            'runtime.aggregates.*.count' => ['required', 'integer', 'min:1'],
            'runtime.aggregates.*.errorCount' => ['required', 'integer', 'min:0'],
            'runtime.aggregates.*.totalMs' => ['required', 'numeric', 'min:0'],
            'runtime.aggregates.*.maxMs' => ['required', 'numeric', 'min:0'],
        ];
    }

    /**
     * The dimensions of each aggregate's type: present, strings, and in their enum when they
     * have one (status class, outcome).
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach ((array) $this->input('runtime.aggregates', []) as $index => $aggregate) {
                $type = is_array($aggregate) ? RuntimeTypeEnum::tryFrom((string) ($aggregate['type'] ?? '')) : null;

                if ($type === null) {
                    continue;
                }

                foreach ($type->dimensions() as $dimension) {
                    $value = $aggregate[$dimension] ?? null;
                    $isValid = is_string($value) && mb_strlen($value) <= 2000
                        && ($dimension !== 'statusClass' || in_array($value, self::STATUS_CLASSES, true))
                        && ($dimension !== 'outcome' || in_array($value, self::OUTCOMES[$type->value] ?? [], true));

                    if (! $isValid) {
                        $validator->errors()->add("runtime.aggregates.{$index}.{$dimension}", "The {$dimension} of a {$type->value} aggregate is missing or invalid.");
                    }
                }
            }
        }];
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
}

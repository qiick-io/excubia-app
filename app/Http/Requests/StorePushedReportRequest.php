<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\SeverityEnum;
use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the push envelope of excubia-core (`docs/push-schema.json`, protocol version 1) and
 * the report inside it (`docs/report-schema.json`, schema version 1). Unknown keys are allowed,
 * so additive core changes keep working. The token was checked by AuthenticateProjectToken.
 */
class StorePushedReportRequest extends FormRequest
{
    public const PROTOCOL_VERSIONS = [1];

    private const STATUSES = ['pass', 'warn', 'fail', 'skipped'];

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
            'protocolVersion' => ['required', 'integer', Rule::in(self::PROTOCOL_VERSIONS)],
            'batchId' => ['required', 'string', 'regex:/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/'],
            'sentAt' => ['required', 'date'],
            'report' => ['required', 'array'],
            'report.schemaVersion' => ['required', 'integer', Rule::in([1])],
            'report.generatedAt' => ['sometimes', 'nullable', 'date'],
            'report.checks' => ['present', 'list'],
            'report.checks.*' => ['array'],
            'report.checks.*.checkId' => ['required', 'string'],
            'report.checks.*.name' => ['required', 'string'],
            'report.checks.*.status' => ['required', Rule::in(self::STATUSES)],
            'report.checks.*.findings' => ['present', 'list'],
            'report.checks.*.findings.*' => ['array'],
            'report.checks.*.findings.*.checkId' => ['required', 'string'],
            'report.checks.*.findings.*.severity' => ['required', Rule::in(SeverityEnum::values())],
            'report.checks.*.findings.*.status' => ['required', Rule::in(self::STATUSES)],
            'report.checks.*.findings.*.subject' => ['present', 'string'],
            'report.checks.*.findings.*.message' => ['present', 'string'],
            'report.checks.*.findings.*.fixHint' => ['present', 'string'],
            'report.checks.*.findings.*.evidenceUrl' => ['present', 'nullable', 'string'],
            'report.checks.*.findings.*.advisoryId' => ['nullable', 'string'],
            'report.upgradePlan' => ['present', 'list'],
            'report.upgradePlan.*.stepNumber' => ['required', 'integer', 'min:1'],
            'report.upgradePlan.*.message' => ['required', 'string'],
            // Schedule inventory (#110), optional: older cores and the standalone CLI omit it.
            'report.schedule' => ['sometimes', 'list', 'max:500'],
            'report.schedule.*' => ['array'],
            'report.schedule.*.taskId' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/', 'distinct'],
            'report.schedule.*.task' => ['required', 'string'],
            'report.schedule.*.expression' => ['required', 'string', 'max:100'],
            'report.schedule.*.timezone' => ['required', 'string', 'max:64'],
            'report.schedule.*.withoutOverlapping' => ['required', 'boolean'],
            'report.schedule.*.onOneServer' => ['required', 'boolean'],
            'report.schedule.*.runsInMaintenanceMode' => ['required', 'boolean'],
            'report.schedule.*.environments' => ['present', 'list'],
            'report.schedule.*.environments.*' => ['string', 'max:64'],
            'report.schedule.*.lateAfterMinutes' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'protocolVersion.in' => 'Unsupported protocolVersion. This Excubia app accepts: '.implode(', ', self::PROTOCOL_VERSIONS).'. Update qiick/excubia-core or the app.',
            'report.schemaVersion.in' => 'Unsupported report schemaVersion. This Excubia app accepts: 1.',
        ];
    }

    public function project(): ?Project
    {
        $project = $this->attributes->get('project');

        return $project instanceof Project ? $project : null;
    }
}

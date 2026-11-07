<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates an exceptions push of excubia-core (`docs/push-schema.json`
 * `$defs.exceptionsEnvelope`, payload `docs/exception-schema.json`, protocol version 1). The
 * core masks every group before it leaves the project; the app stores it as received. The
 * token was checked by AuthenticateProjectToken.
 */
class StorePushedExceptionsRequest extends FormRequest
{
    private const TIMESTAMP = 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/';

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
            'type' => ['required', 'in:exceptions'],
            'batchId' => ['required', 'string', 'regex:/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/'],
            'sentAt' => ['required', 'date'],
            'exceptions' => ['required', 'array'],
            'exceptions.release' => ['sometimes', 'string', 'max:100'],
            'exceptions.groups' => ['present', 'list'],
            'exceptions.groups.*' => ['array'],
            'exceptions.groups.*.class' => ['required', 'string', 'max:255'],
            'exceptions.groups.*.fingerprint' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],
            'exceptions.groups.*.message' => ['present', 'string', 'max:1000'],
            'exceptions.groups.*.frames' => ['present', 'list', 'max:50'],
            'exceptions.groups.*.frames.*.file' => ['present', 'nullable', 'string', 'max:500'],
            'exceptions.groups.*.frames.*.line' => ['present', 'nullable', 'integer', 'min:0'],
            'exceptions.groups.*.frames.*.function' => ['present', 'nullable', 'string', 'max:500'],
            'exceptions.groups.*.frames.*.inApp' => ['required', 'boolean'],
            'exceptions.groups.*.previous' => ['present', 'list', 'max:5'],
            'exceptions.groups.*.previous.*' => ['string', 'max:255'],
            'exceptions.groups.*.context' => ['required', 'array'],
            'exceptions.groups.*.context.kind' => ['required', 'in:request,job,command,unknown'],
            'exceptions.groups.*.context.name' => ['present', 'string', 'max:255'],
            'exceptions.groups.*.environment' => ['required', 'string', 'max:64'],
            'exceptions.groups.*.count' => ['required', 'integer', 'min:1'],
            'exceptions.groups.*.firstSeen' => ['required', 'string', self::TIMESTAMP],
            'exceptions.groups.*.lastSeen' => ['required', 'string', self::TIMESTAMP],
        ];
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

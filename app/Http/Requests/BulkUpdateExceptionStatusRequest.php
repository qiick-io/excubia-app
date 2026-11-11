<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ExceptionStatusEnum;
use App\Http\Requests\Concerns\TargetsProjectExceptionGroupsTrait;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Resolves, ignores or reopens several exception groups of a project at once (#276).
 */
class BulkUpdateExceptionStatusRequest extends FormRequest
{
    use TargetsProjectExceptionGroupsTrait;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...$this->selectionRules(),
            'status' => ['required', Rule::enum(ExceptionStatusEnum::class)],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->validateSelectionBelongsToProject($validator)];
    }
}

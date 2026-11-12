<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesProjectLogoTrait;
use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Validates a new project. The slug is derived from the name and must be unique too, so
 * "Shop" and "shop" cannot both exist. An optional logo comes with it (#287).
 */
class StoreProjectRequest extends FormRequest
{
    use ValidatesProjectLogoTrait;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', Project::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique(Project::class)],
            'slug' => ['required', 'string', Rule::unique(Project::class)],
            'is_enabled' => ['boolean'],
            ...$this->projectLogoRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.required' => __('The name must contain letters or digits.'),
            'slug.unique' => __('A project with a similar name already exists.'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['slug' => Str::slug($this->string('name')->toString())]);
    }
}

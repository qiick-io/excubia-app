<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The monthly AI budget of a project (#129), in USD; empty turns it off. Needs
 * `can-manage-alerts` on a project the user can see, as the other alert controls (#22).
 */
class UpdateAiBudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project && (bool) $this->user()?->can('manageAlerts', $project);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'ai_monthly_budget' => ['nullable', 'numeric', 'min:0.01', 'max:10000000'],
        ];
    }

    public function budget(): ?float
    {
        return $this->filled('ai_monthly_budget') ? round((float) $this->input('ai_monthly_budget'), 2) : null;
    }
}

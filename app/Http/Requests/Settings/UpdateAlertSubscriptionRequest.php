<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Data\AlertFilterData;
use App\Http\Requests\Settings\Concerns\ValidatesAlertFiltersTrait;
use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The signed-in user's alert emails (#22): on or off, and their own filters under `alerts`,
 * whose projects are limited to those the user can see. Needs `can-show-projects`.
 */
class UpdateAlertSubscriptionRequest extends FormRequest
{
    use ValidatesAlertFiltersTrait;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('viewAny', Project::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'has_alert_emails' => ['sometimes', 'boolean'],
            ...$this->alertFilterRules('alerts', Project::query()->visibleTo($this->user())->pluck('id')->all()),
        ];
    }

    public function filter(): AlertFilterData
    {
        return $this->alertFilter('alerts');
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings\Concerns;

use App\Models\Setting;

/**
 * Shared authorization for project settings form requests (SettingPolicy, `settings.manage`).
 */
trait AuthorizesProjectSettingsTrait
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('update', Setting::class);
    }
}

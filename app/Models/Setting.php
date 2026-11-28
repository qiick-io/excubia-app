<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The policy subject of the settings pages (SettingPolicy), on qiick/laravel-settings' `settings`
 * table. Read and write settings through SettingsRepositoryService, never through this model.
 *
 * @property int $id
 * @property string $scope
 * @property string $scope_id empty string for instance scope (never NULL; see signaculum #205)
 * @property string $group
 * @property string $key
 * @property mixed $value
 * @property string|null $encryption package column (`app` when the value is encrypted)
 * @property int $revision optimistic concurrency counter from qiick/laravel-settings
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['scope', 'scope_id', 'group', 'key', 'value', 'encryption', 'revision', 'metadata'])]
class Setting extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'json',
            'revision' => 'integer',
            'metadata' => 'array',
        ];
    }
}

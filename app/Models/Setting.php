<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One scoped application setting. Read and write it through SettingsRepositoryService.
 *
 * @property int $id
 * @property string $scope
 * @property string $scope_id empty string for instance scope (never NULL; see signaculum #205)
 * @property string $group
 * @property string $key
 * @property mixed $value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['scope', 'scope_id', 'group', 'key', 'value'])]
class Setting extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }
}

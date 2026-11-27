<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Qiick\Access\Principals\Principal as AccessPrincipal;
use Qiick\Access\Principals\PrincipalDirectory;

/**
 * Actor that can own API keys: a person (`user`) or, later, a service account. The table and
 * the lifecycle are qiick/laravel-access' (`access.principals.model` points here): rows are
 * created and deactivated only through {@see PrincipalDirectory}, never written directly.
 *
 * @property string $id
 * @property string $type `user` or `service`
 * @property int|null $user_id
 * @property string|null $organisation_id
 * @property string $name
 * @property Carbon|null $deactivated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Principal extends AccessPrincipal
{
    /**
     * @return HasMany<ApiKey, $this>
     */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class, 'owner_principal_id');
    }
}

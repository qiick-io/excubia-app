<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PrincipalTypeEnum;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Actor that can own API keys: a person (`user`) or, later, a service account.
 *
 * @property string $id
 * @property PrincipalTypeEnum|string $type
 * @property int|null $user_id
 * @property string|null $organisation_id
 * @property string $name
 * @property Carbon|null $deactivated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Principal extends Model
{
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'type',
        'user_id',
        'organisation_id',
        'name',
        'deactivated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PrincipalTypeEnum::class,
            'deactivated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<ApiKey, $this>
     */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class, 'owner_principal_id');
    }
}

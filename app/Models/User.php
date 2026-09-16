<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Identity\Models\UserInvitation;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $attributes = [
        'is_active' => true,
    ];

    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
        'deactivated_at',
        'session_revoked_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'deactivated_at' => 'immutable_datetime',
            'session_revoked_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    /**
     * @return HasMany<UserInvitation, $this>
     */
    public function sentInvitations(): HasMany
    {
        return $this->hasMany(UserInvitation::class, 'invited_by_user_id');
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->is_active === false) {
            return false;
        }

        return $this->roles()
            ->whereHas('permissions', fn ($query) => $query->where('key', $permission))
            ->exists();
    }
}

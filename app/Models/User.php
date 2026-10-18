<?php

namespace App\Models;

use App\Domain\Access\Models\Role;
use App\Domain\Organizations\Models\OrganizationMembership;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'phone', 'password'])]
#[Hidden(['password', 'remember_token', 'mfa_secret', 'mfa_recovery_codes', 'mfa_last_used_timestep'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $attributes = ['status' => 'active'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'suspended_at' => 'datetime',
            'mfa_secret' => 'encrypted',
            'mfa_enabled_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    public function activeMemberships(): HasMany
    {
        return $this->memberships()->where('status', 'active');
    }

    public function platformRoles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'platform_role_user')->withPivot('granted_by');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}

<?php

namespace App\Domain\Organizations\Models;

use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Organizations\Enums\VerificationStatus;
use App\Domain\Platform\Models\Attachment;
use App\Domain\Platform\Models\Country;
use App\Models\User;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[UseFactory(OrganizationFactory::class)]
class Organization extends Model
{
    use HasFactory, HasUlids;

    // State fields only change through the verification/status services.
    protected $guarded = ['id', 'verification_status', 'verified_at', 'status', 'suspended_at'];

    protected $attributes = ['status' => 'active', 'verification_status' => 'unverified'];

    protected function casts(): array
    {
        return [
            'type' => OrganizationType::class,
            'verification_status' => VerificationStatus::class,
            'verified_at' => 'datetime',
            'suspended_at' => 'datetime',
            'branding' => 'array',
            'settings' => 'array',
        ];
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'country_code');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_memberships')->withPivot('status');
    }

    public function modules(): HasMany
    {
        return $this->hasMany(OrganizationModule::class);
    }

    public function verifications(): HasMany
    {
        // ULIDs break ties between events recorded in the same second.
        return $this->hasMany(OrganizationVerification::class)->latest('created_at')->orderByDesc('id');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isVerified(): bool
    {
        return $this->verification_status === VerificationStatus::Verified;
    }

    public function hasModule(string $module): bool
    {
        return $this->modules()->where('module', $module)->where('enabled', true)->exists();
    }
}

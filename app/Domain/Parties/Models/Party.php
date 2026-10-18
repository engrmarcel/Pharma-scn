<?php

namespace App\Domain\Parties\Models;

use App\Domain\Organizations\Models\Organization;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Shared behaviour of suppliers and customers (tenant-owned).
 */
abstract class Party extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:4',
            'payment_terms_days' => 'integer',
        ];
    }

    /** supplier | customer */
    abstract public function partyType(): string;

    public function contacts(): HasMany
    {
        return $this->hasMany(PartyContact::class, 'party_id')->where('party_type', $this->partyType());
    }

    public function linkedOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'linked_organization_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}

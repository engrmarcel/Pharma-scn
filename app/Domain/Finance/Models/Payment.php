<?php

namespace App\Domain\Finance\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    use BelongsToOrganization, HasUlids;

    public const METHODS = ['cash', 'bank_transfer', 'card', 'mobile_money', 'cheque', 'other'];

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:4', 'allocated_amount' => 'decimal:4', 'payment_date' => 'date'];
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }
}

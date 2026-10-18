<?php

namespace App\Domain\Finance\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    use BelongsToOrganization, HasUlids;

    public const TYPES = ['asset', 'liability', 'equity', 'revenue', 'expense'];

    protected $guarded = ['id', 'organization_id'];

    /** Debit-normal accounts increase with debits. */
    public function isDebitNormal(): bool
    {
        return in_array($this->type, ['asset', 'expense'], true);
    }
}

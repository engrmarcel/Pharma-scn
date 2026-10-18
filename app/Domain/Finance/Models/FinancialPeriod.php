<?php

namespace App\Domain\Finance\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class FinancialPeriod extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'closed_at' => 'datetime'];
    }
}

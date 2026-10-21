<?php

namespace App\Domain\Marketplace\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class MarketplaceInvoice extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['net_amount' => 'decimal:4', 'tax_amount' => 'decimal:4', 'total' => 'decimal:4', 'invoice_date' => 'date:Y-m-d', 'due_date' => 'date:Y-m-d', 'lines' => 'array'];
    }
}

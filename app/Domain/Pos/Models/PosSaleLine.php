<?php

namespace App\Domain\Pos\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PosSaleLine extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['prescription' => 'array', 'discount_percent' => 'decimal:4', 'tax_rate' => 'decimal:4', 'quantity' => 'decimal:4', 'base_quantity' => 'decimal:4', 'unit_price' => 'decimal:4', 'list_price' => 'decimal:4', 'line_subtotal' => 'decimal:4', 'line_discount' => 'decimal:4', 'line_tax' => 'decimal:4', 'line_total' => 'decimal:4'];
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PosSaleAllocation::class, 'pos_sale_line_id');
    }
}

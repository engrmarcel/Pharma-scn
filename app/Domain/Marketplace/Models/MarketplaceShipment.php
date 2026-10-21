<?php

namespace App\Domain\Marketplace\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketplaceShipment extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected $attributes = ['status' => 'in_transit'];

    protected function casts(): array
    {
        return ['shipped_at' => 'datetime', 'received_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(MarketplaceShipmentLine::class);
    }
}

<?php

namespace App\Domain\Parties\Models;

class Supplier extends Party
{
    public const TYPES = ['manufacturer', 'importer', 'distributor', 'wholesaler', 'pharmacy', 'service_provider', 'other'];

    protected function casts(): array
    {
        return parent::casts() + ['minimum_order_value' => 'decimal:4', 'lead_time_days' => 'integer'];
    }

    public function partyType(): string
    {
        return 'supplier';
    }
}

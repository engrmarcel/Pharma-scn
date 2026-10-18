<?php

namespace App\Domain\Parties\Models;

class Customer extends Party
{
    public const TYPES = ['pharmacy', 'hospital', 'clinic', 'healthcare_facility', 'wholesaler', 'distributor', 'institution', 'individual', 'other'];

    protected function casts(): array
    {
        return parent::casts() + ['latitude' => 'decimal:7', 'longitude' => 'decimal:7'];
    }

    public function partyType(): string
    {
        return 'customer';
    }
}

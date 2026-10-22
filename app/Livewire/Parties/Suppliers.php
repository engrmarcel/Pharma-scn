<?php

namespace App\Livewire\Parties;

use App\Domain\Parties\Models\Supplier;
use Livewire\Attributes\Title;

#[Title('Suppliers')]
class Suppliers extends Directory
{
    public const ROUTE = 'parties.suppliers';

    protected const KIND = 'suppliers';

    protected function types(): array
    {
        return Supplier::TYPES;
    }

    protected function extraFields(): array
    {
        return ['lead_time_days' => 'Lead time (days)', 'minimum_order_value' => 'Minimum order value'];
    }
}

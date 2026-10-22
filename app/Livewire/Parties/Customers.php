<?php

namespace App\Livewire\Parties;

use App\Domain\Parties\Models\Customer;
use Livewire\Attributes\Title;

#[Title('Customers')]
class Customers extends Directory
{
    public const ROUTE = 'parties.customers';

    protected const KIND = 'customers';

    protected function types(): array
    {
        return Customer::TYPES;
    }

    protected function extraFields(): array
    {
        return ['licence_reference' => 'Licence number', 'latitude' => 'Latitude', 'longitude' => 'Longitude'];
    }
}

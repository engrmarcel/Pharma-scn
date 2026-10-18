<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Parties\Models\Customer;
use Illuminate\Validation\Rule;

class CustomerController extends PartyController
{
    protected function model(): string
    {
        return Customer::class;
    }

    protected function permissionPrefix(): string
    {
        return 'customers';
    }

    protected function typeRules(): array
    {
        return [Rule::in(Customer::TYPES)];
    }

    protected function extraRules(): array
    {
        return [
            'licence_reference' => ['nullable', 'string', 'max:64'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
        ];
    }
}

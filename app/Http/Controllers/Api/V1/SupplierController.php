<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Parties\Models\Supplier;
use Illuminate\Validation\Rule;

class SupplierController extends PartyController
{
    protected function model(): string
    {
        return Supplier::class;
    }

    protected function permissionPrefix(): string
    {
        return 'suppliers';
    }

    protected function typeRules(): array
    {
        return [Rule::in(Supplier::TYPES)];
    }

    protected function extraRules(): array
    {
        return [
            'lead_time_days' => ['nullable', 'integer', 'between:0,365'],
            'minimum_order_value' => ['nullable', 'decimal:0,4', 'min:0'],
        ];
    }
}

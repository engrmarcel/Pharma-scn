<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\FieldForce\Services\FieldForceScope;
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

    /** Members with customers.view_assigned see only customers in their territories or assigned to them. */
    protected function authorizeView(): void
    {
        abort_unless(app(FieldForceScope::class)->canSeeCustomers(), 403);
    }

    protected function visible($query)
    {
        return app(FieldForceScope::class)->scopeCustomers($query, 'id');
    }

    protected function typeRules(): array
    {
        return [Rule::in(Customer::TYPES)];
    }

    protected function extraRules(): array
    {
        return [
            'licence_reference' => ['nullable', 'string', 'max:64'],
            'price_list_id' => ['nullable', 'ulid', Rule::exists('price_lists', 'id')->where('organization_id', $this->tenant->id())],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
        ];
    }
}

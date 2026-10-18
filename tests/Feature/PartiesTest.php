<?php

namespace Tests\Feature;

use App\Domain\Parties\Models\Customer;
use App\Domain\Parties\Models\Supplier;
use Tests\FeatureTestCase;

class PartiesTest extends FeatureTestCase
{
    public function test_procurement_officer_manages_suppliers_with_contacts(): void
    {
        [$buyer, $org] = $this->organizationMember(['procurement_officer']);
        [, $manufacturer] = $this->organizationMember(orgAttributes: ['type' => 'manufacturer', 'name' => 'Linked Manufacturer']);

        $id = $this->actingAsApi($buyer, $org)->api('POST', 'suppliers', [
            'code' => 'SUP-001', 'name' => 'Acme Distribution', 'type' => 'distributor',
            'payment_terms_days' => 30, 'credit_limit' => '5000000.5000', 'lead_time_days' => 3,
            'linked_organization_id' => $manufacturer->id,
        ])->assertCreated()
            ->assertJsonPath('data.currency_code', 'NGN')
            ->assertJsonPath('data.credit_limit', '5000000.5000')
            ->assertJsonMissingPath('data.organization_id')
            ->json('data.id');

        $this->api('POST', "suppliers/{$id}/contacts", ['name' => 'Ada Obi', 'email' => 'ada@acme.test', 'is_primary' => true])->assertCreated();
        $second = $this->api('POST', "suppliers/{$id}/contacts", ['name' => 'Bayo Ade', 'is_primary' => true])->assertCreated()->json('data.id');

        $contacts = $this->api('GET', "suppliers/{$id}")->assertOk()->json('data.contacts');
        $this->assertSame([['Bayo Ade', true], ['Ada Obi', false]], array_map(fn ($c) => [$c['name'], $c['is_primary']], $contacts), 'only one primary contact');

        $this->api('DELETE', "suppliers/{$id}/contacts/{$second}")->assertNoContent();
        $this->api('PATCH', "suppliers/{$id}", ['status' => 'on_hold'])->assertOk()->assertJsonPath('data.status', 'on_hold');
        $this->api('GET', 'suppliers?q=acme')->assertOk()->assertJsonPath('data.0.code', 'SUP-001');

        $this->assertDatabaseHas('audit_logs', ['action' => 'supplier.created', 'organization_id' => $org->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'supplier.updated', 'organization_id' => $org->id]);
    }

    public function test_codes_are_unique_per_organization_only(): void
    {
        [$a, $orgA] = $this->organizationMember();
        [$b, $orgB] = $this->organizationMember();
        $body = ['code' => 'C-1', 'name' => 'Lekki Pharmacy', 'type' => 'pharmacy'];

        $this->actingAsApi($a, $orgA)->api('POST', 'customers', $body)->assertCreated();
        $this->api('POST', 'customers', $body)->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->actingAsApi($b, $orgB)->api('POST', 'customers', $body)->assertCreated();
    }

    public function test_parties_and_contacts_are_isolated_between_tenants(): void
    {
        [$a, $orgA] = $this->organizationMember();
        [$b, $orgB] = $this->organizationMember();
        $bob = $this->inTenant($orgB, fn () => Customer::query()->create(['code' => 'X', 'name' => 'Secret Hospital', 'type' => 'hospital', 'currency_code' => 'NGN']));

        $this->actingAsApi($a, $orgA)->api('GET', 'customers')->assertOk()->assertJsonCount(0, 'data');
        $this->api('GET', "customers/{$bob->id}")->assertNotFound();
        $this->api('PATCH', "customers/{$bob->id}", ['name' => 'Hijack'])->assertNotFound();
        $this->api('POST', "customers/{$bob->id}/contacts", ['name' => 'Spy'])->assertNotFound();
        $this->assertSame('Secret Hospital', $bob->fresh()->name);
    }

    public function test_permissions_per_role(): void
    {
        [$owner, $org] = $this->organizationMember();
        [$cashier] = $this->organizationMember(['pos_cashier'], organization: $org);
        [$rep] = $this->organizationMember(['sales_representative'], organization: $org);
        [$auditor] = $this->organizationMember(['auditor'], organization: $org);

        $this->actingAsApi($cashier, $org)->api('GET', 'customers')->assertOk();
        $this->api('POST', 'customers', ['code' => 'C', 'name' => 'N', 'type' => 'pharmacy'])->assertForbidden();
        $this->api('GET', 'suppliers')->assertForbidden();

        $this->actingAsApi($rep, $org)->api('GET', 'customers')->assertOk();
        $this->actingAsApi($auditor, $org)->api('GET', 'suppliers')->assertOk();
        $this->api('POST', 'suppliers', ['code' => 'S', 'name' => 'N', 'type' => 'other'])->assertForbidden();
    }

    public function test_validation_rules(): void
    {
        [$owner, $org] = $this->organizationMember();
        $this->actingAsApi($owner, $org);

        $this->api('POST', 'customers', ['code' => 'C', 'name' => 'N', 'type' => 'spaceship'])->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->api('POST', 'customers', ['code' => 'C', 'name' => 'N', 'type' => 'pharmacy', 'latitude' => 6.5])->assertUnprocessable()->assertJsonValidationErrors('longitude');
        $this->api('POST', 'suppliers', ['code' => 'S', 'name' => 'N', 'type' => 'other', 'credit_limit' => '1.12345'])->assertUnprocessable()->assertJsonValidationErrors('credit_limit');
        $this->api('POST', 'suppliers', ['code' => 'S', 'name' => 'Me', 'type' => 'other', 'linked_organization_id' => $org->id])->assertUnprocessable();
    }

    public function test_modules_gate_party_endpoints(): void
    {
        [$owner, $org] = $this->organizationMember(orgAttributes: ['type' => 'logistics_provider']);

        // Logistics providers get only the logistics module by default.
        $this->actingAsApi($owner, $org)->api('GET', 'suppliers')->assertForbidden()->assertJsonPath('error.code', 'module_not_enabled');
        $this->api('GET', 'customers')->assertForbidden()->assertJsonPath('error.code', 'module_not_enabled');
    }

    public function test_preferred_supplier_must_belong_to_the_same_organization(): void
    {
        [$a, $orgA] = $this->organizationMember();
        [, $orgB] = $this->organizationMember();
        $product = $this->makeProduct();
        $own = $this->inTenant($orgA, fn () => Supplier::query()->create(['code' => 'S1', 'name' => 'Own', 'type' => 'distributor', 'currency_code' => 'NGN']));
        $foreign = $this->inTenant($orgB, fn () => Supplier::query()->create(['code' => 'S1', 'name' => 'Foreign', 'type' => 'distributor', 'currency_code' => 'NGN']));

        $this->actingAsApi($a, $orgA)->api('POST', 'catalogue/items', ['product_id' => $product->id, 'sku' => 'X', 'preferred_supplier_id' => $foreign->id])
            ->assertUnprocessable()->assertJsonValidationErrors('preferred_supplier_id');
        $this->api('POST', 'catalogue/items', ['product_id' => $product->id, 'sku' => 'X', 'preferred_supplier_id' => $own->id])
            ->assertCreated()->assertJsonPath('data.preferred_supplier_id', $own->id);
    }
}

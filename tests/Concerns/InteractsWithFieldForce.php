<?php

namespace Tests\Concerns;

use App\Domain\Organizations\Models\OrganizationModule;
use App\Domain\Parties\Models\Customer;

/** A distributor with two territories, representatives, a supervisor and customers (field-force and sync tests). */
trait InteractsWithFieldForce
{
    use InteractsWithInventory;

    /** Lekki Clinic sits at this point; offsets below are measured from it. */
    protected const LAT = 6.4281;

    protected const LNG = 3.4219;

    /**
     * Two territories: T1 (rep1, supervisor) with customer A, T2 (rep2) with customer B.
     * Customer C has no territory and no recorded location; it is assigned to rep1 directly.
     */
    protected function fieldTeam(): array
    {
        [$owner, $org, $product, $main] = $this->stockedOrganization();
        OrganizationModule::query()->create(['organization_id' => $org->id, 'module' => 'field_force', 'enabled' => true]);
        $this->receive($org, $main, $product, 'FF-1', '200', '+1 year', ['unit_cost' => '50']);
        [$rep1] = $this->organizationMember(['sales_representative'], organization: $org);
        [$rep2] = $this->organizationMember(['sales_representative'], organization: $org);
        [$sup] = $this->organizationMember(['field_supervisor'], organization: $org);

        [$a, $b, $c] = $this->inTenant($org, fn () => [
            Customer::query()->create(['code' => 'A', 'name' => 'Lekki Clinic', 'type' => 'clinic', 'currency_code' => 'NGN', 'latitude' => self::LAT, 'longitude' => self::LNG]),
            Customer::query()->create(['code' => 'B', 'name' => 'Ikeja Pharmacy', 'type' => 'pharmacy', 'currency_code' => 'NGN', 'latitude' => 6.6018, 'longitude' => 3.3515]),
            Customer::query()->create(['code' => 'C', 'name' => 'Yaba Chemist', 'type' => 'pharmacy', 'currency_code' => 'NGN']),
        ]);

        $this->actingAsApi($owner, $org);
        $this->api('POST', 'price-lists', ['code' => 'STD', 'name' => 'Standard', 'is_default' => true,
            'items' => [['product_id' => $product->id, 'package_level_code' => 'pack', 'unit_price' => '3000']]])->assertCreated();
        $t1 = $this->api('POST', 'field-force/territories', ['code' => 'LAG-ISL', 'name' => 'Lagos Island', 'warehouse_id' => $main->id])->assertCreated()->json('data.id');
        $t2 = $this->api('POST', 'field-force/territories', ['code' => 'LAG-MLD', 'name' => 'Lagos Mainland', 'warehouse_id' => $main->id])->assertCreated()->json('data.id');
        $this->api('PUT', "field-force/territories/{$t1}/members", ['members' => [['user_id' => $rep1->id, 'role' => 'representative'], ['user_id' => $sup->id, 'role' => 'supervisor']]])->assertOk();
        $this->api('PUT', "field-force/territories/{$t2}/members", ['members' => [['user_id' => $rep2->id, 'role' => 'representative']]])->assertOk();
        $this->api('PUT', 'field-force/customer-assignments', ['customer_ids' => [$a->id], 'territory_id' => $t1])->assertOk();
        $this->api('PUT', 'field-force/customer-assignments', ['customer_ids' => [$b->id], 'territory_id' => $t2, 'assigned_representative_id' => $rep2->id])->assertOk();
        $this->api('PUT', 'field-force/customer-assignments', ['customer_ids' => [$c->id], 'territory_id' => null, 'assigned_representative_id' => $rep1->id])->assertOk();

        return compact('owner', 'org', 'product', 'main', 'rep1', 'rep2', 'sup', 'a', 'b', 'c', 't1', 't2');
    }

    protected function event(array $overrides = []): array
    {
        return $overrides + ['occurred_at' => now()->subMinute()->toIso8601String(), 'location_status' => 'granted',
            'latitude' => self::LAT + 0.0004, 'longitude' => self::LNG, 'accuracy_m' => 15, 'mock_location' => false,
            'device' => ['platform' => 'android', 'app_version' => '1.0.0']];
    }
}

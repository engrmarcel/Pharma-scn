<?php

namespace Tests\Feature;

use App\Domain\Marketplace\Models\TradingRelationship;
use App\Domain\Parties\Models\Customer;
use App\Domain\Parties\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithMarketplace;
use Tests\FeatureTestCase;

class MarketplaceTest extends FeatureTestCase
{
    use InteractsWithMarketplace;

    public function test_discovery_labels_availability_source_freshness_and_supplier_verification(): void
    {
        $s = $this->listedSupplier();
        $live = $this->listing($s, $s['amox']);
        $declared = $this->listing($s, $s['amox'], ['package_level_code' => 'carton', 'unit_price' => '58000', 'minimum_order_quantity' => '1',
            'availability_mode' => 'declared', 'declared_availability' => 'limited']);
        [$buyer, $pharmacy] = $this->marketplaceOrg('pharmacy');

        $rows = collect($this->actingAsApi($buyer, $pharmacy)->api('GET', 'marketplace/discover', ['q' => 'Supplimox'])->assertOk()->json('data'))->keyBy('listing_id');
        $this->assertCount(2, $rows);

        // Live: a status band from the supplier's stock at search time, not an exact count, never a promise.
        $this->assertSame(['live', 'in_stock'], [$rows[$live]['availability']['source'], $rows[$live]['availability']['status']]);
        $this->assertArrayHasKey('as_of', $rows[$live]['availability']);
        $this->assertArrayNotHasKey('quantity', $rows[$live]['availability']);
        $this->assertStringContainsString('Not reserved', $rows[$live]['availability']['note']);

        // Declared: the supplier's own statement with its date; stale after a week.
        $this->assertSame(['declared', 'limited', false], [$rows[$declared]['availability']['source'], $rows[$declared]['availability']['status'], $rows[$declared]['availability']['stale']]);
        $this->travel(8)->days();
        $this->assertTrue(collect($this->api('GET', 'marketplace/discover', ['q' => 'Supplimox'])->json('data'))->firstWhere('listing_id', $declared)['availability']['stale']);
        $this->travelBack();

        $supplier = $rows[$live]['supplier'];
        $this->assertSame('verified', $supplier['verification']['status']);
        $this->assertStringContainsString('not an approval by a regulator', $supplier['verification']['label']);
        $this->assertSame(['delivery', 'pickup'], $supplier['delivery_options']);

        // Filters: ingredient, region served, availability.
        $this->assertCount(2, $this->api('GET', 'marketplace/discover', ['ingredient' => 'trihydrate', 'state' => 'Ogun'])->json('data'));
        $this->assertCount(0, $this->api('GET', 'marketplace/discover', ['state' => 'Kano'])->json('data'));
        $this->assertCount(2, $this->api('GET', 'marketplace/discover', ['available_only' => 1])->json('data'));

        // Hidden: paused listings, unlisted profiles, suppliers without the marketplace module.
        $this->actingAsApi($s['owner'], $s['org'])->api('PATCH', "marketplace/listings/{$declared}", ['status' => 'paused'])->assertOk();
        $this->assertCount(1, $this->actingAsApi($buyer, $pharmacy)->api('GET', 'marketplace/discover')->json('data'));
        $this->enableMarketplace($s['org'], false);
        $this->assertCount(0, $this->api('GET', 'marketplace/discover')->json('data'));
    }

    public function test_restricted_products_need_verified_parties_of_licensed_types(): void
    {
        $unverified = $this->stockedSupplier(verified: false);
        $this->actingAsApi($unverified['owner'], $unverified['org'])->api('POST', 'marketplace/listings', [
            'product_id' => $unverified['rx']->id, 'package_level_code' => 'pack', 'unit_price' => '9000',
        ])->assertForbidden()->assertJsonPath('error.code', 'supplier_verification_required');

        $s = $this->listedSupplier();
        $this->listing($s, $s['rx']);
        [$verifiedBuyer, $verifiedPharmacy] = $this->marketplaceOrg('pharmacy');
        [$newBuyer, $newPharmacy] = $this->marketplaceOrg('pharmacy', verified: false);

        $row = $this->actingAsApi($verifiedBuyer, $verifiedPharmacy)->api('GET', 'marketplace/discover', ['q' => 'Rocetrix'])->json('data.0');
        $this->assertSame(['prescription_only', true, true], [$row['restriction']['classification'], $row['restriction']['restricted'], $row['restriction']['you_may_buy']]);

        $row = $this->actingAsApi($newBuyer, $newPharmacy)->api('GET', 'marketplace/discover', ['q' => 'Rocetrix'])->json('data.0');
        $this->assertFalse($row['restriction']['you_may_buy']);
        $this->assertStringContainsString('platform verification', $row['restriction']['reason']);
    }

    public function test_relationships_create_each_sides_erp_party_and_follow_the_trading_matrix(): void
    {
        $s = $this->listedSupplier();
        [$buyer, $pharmacy] = $this->marketplaceOrg('pharmacy');
        [$procurement] = $this->organizationMember(['procurement_officer'], organization: $pharmacy);

        $rel = $this->actingAsApi($procurement, $pharmacy)->api('POST', 'marketplace/relationships', ['supplier_organization_id' => $s['org']->id, 'message' => 'Monthly antibiotics'])
            ->assertCreated()->assertJsonPath('data.status', 'requested')->assertJsonPath('data.your_side', 'buyer')->json('data');
        $party = $this->inTenant($pharmacy, fn () => Supplier::query()->findOrFail($rel['erp_party_id']));
        $this->assertSame($s['org']->id, $party->linked_organization_id);
        $this->api('POST', 'marketplace/relationships', ['supplier_organization_id' => $s['org']->id])->assertStatus(409);

        // Only the supplier decides; a rejection needs a reason.
        $this->api('POST', "marketplace/relationships/{$rel['id']}/approve")->assertForbidden()->assertJsonPath('error.code', 'relationship_not_yours');
        $this->actingAsApi($s['owner'], $s['org']);
        $this->api('POST', "marketplace/relationships/{$rel['id']}/reject")->assertUnprocessable();
        $approved = $this->api('POST', "marketplace/relationships/{$rel['id']}/approve", ['payment_terms_days' => 30])->assertOk()
            ->assertJsonPath('data.status', 'approved')->assertJsonPath('data.your_side', 'supplier')->json('data');
        $customer = $this->inTenant($s['org'], fn () => Customer::query()->findOrFail($approved['erp_party_id']));
        $this->assertSame([$pharmacy->id, 'pharmacy', 30], [$customer->linked_organization_id, $customer->type, $customer->payment_terms_days]);

        $this->assertSame(1, collect($this->api('GET', 'marketplace/relationships', ['as' => 'supplier'])->json('data'))->count());
        $this->actingAsApi($buyer, $pharmacy)->api('POST', "marketplace/relationships/{$rel['id']}/suspend", ['reason' => 'Quality complaint'])->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->assertSame('suspended', TradingRelationship::query()->find($rel['id'])->status);

        // The trading matrix: a manufacturer does not sell to single pharmacies.
        [$maker, $manufacturer] = $this->marketplaceOrg('manufacturer');
        $this->actingAsApi($maker, $manufacturer)->api('PUT', 'marketplace/profile', ['listed' => true])->assertOk();
        $this->actingAsApi($buyer, $pharmacy)->api('POST', 'marketplace/relationships', ['supplier_organization_id' => $manufacturer->id])
            ->assertForbidden()->assertJsonPath('error.code', 'buyer_type_not_allowed');
        $this->api('GET', "marketplace/suppliers/{$s['org']->id}")->assertOk()->assertJsonPath('data.relationship', 'suspended')->assertJsonPath('data.you_may_trade', true);
    }

    public function test_marketplace_access_needs_the_module_and_permissions(): void
    {
        $s = $this->listedSupplier();
        [$cashier] = $this->organizationMember(['pos_cashier'], organization: $s['org']);
        $this->actingAsApi($cashier, $s['org'])->api('GET', 'marketplace/discover')->assertForbidden();
        $this->actingAsApi($cashier, $s['org'])->api('POST', 'marketplace/listings', [])->assertForbidden();

        [$buyer, $hospital] = $this->organizationMember(orgAttributes: ['type' => 'hospital']);
        DB::table('organization_modules')->where('organization_id', $hospital->id)->where('module', 'marketplace')->update(['enabled' => false]); // switched off
        $this->actingAsApi($buyer, $hospital)->api('GET', 'marketplace/discover')->assertForbidden()->assertJsonPath('error.code', 'module_not_enabled');
    }
}

<?php

namespace Tests\Feature;

use App\Domain\Catalogue\Models\Product;
use App\Domain\Catalogue\Models\ProductChangeRequest;
use App\Domain\Catalogue\Models\RegulatoryIdentifier;
use App\Domain\Catalogue\Models\RegulatoryVerification;
use App\Domain\Platform\Models\RegulatoryAuthority;
use LogicException;
use Tests\FeatureTestCase;

class ProductMasterTest extends FeatureTestCase
{
    public function test_catalogue_manager_creates_a_product_with_derived_package_hierarchy(): void
    {
        $manager = $this->platformUser('platform_operations_admin');

        $response = $this->actingAsApi($manager)->api('POST', 'admin/products', $this->productPayload([
            'identifiers' => [['type' => 'gtin', 'value' => '06291041500213', 'package_level_code' => 'pack', 'is_primary' => true]],
            'classifications' => [['country_code' => 'NG', 'classification' => 'prescription_only']],
        ]))->assertCreated();

        $response->assertJsonPath('data.display_name', 'Testmox 500 mg Capsule')
            ->assertJsonPath('data.status', 'under_review')
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.package_levels.1.base_unit_quantity', '10.0000')
            ->assertJsonPath('data.package_levels.2.base_unit_quantity', '20.0000')
            ->assertJsonPath('data.package_levels.3.base_unit_quantity', '1000.0000')
            ->assertJsonPath('data.classifications.0.classification', 'prescription_only');

        $this->assertMatchesRegularExpression('/^PRD-[0-9A-Z]{10}$/', $response->json('data.platform_sku'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'product.created', 'auditable_id' => $response->json('data.id'), 'organization_id' => null]);
    }

    public function test_package_hierarchy_must_be_contiguous_and_positive(): void
    {
        $manager = $this->platformUser('platform_operations_admin');
        $this->actingAsApi($manager);

        $gap = $this->productPayload();
        $gap['package_levels'] = [$gap['package_levels'][0], $gap['package_levels'][2]];
        $this->api('POST', 'admin/products', $gap)->assertUnprocessable()->assertJsonPath('error.code', 'package_hierarchy_invalid');

        $zero = $this->productPayload();
        $zero['package_levels'][1]['quantity_of_child'] = null;
        $this->api('POST', 'admin/products', $zero)->assertUnprocessable()->assertJsonPath('error.code', 'package_hierarchy_invalid');

        $this->assertSame(0, Product::query()->count(), 'failed creates must roll back completely');
    }

    public function test_gtin_check_digit_is_validated(): void
    {
        $this->actingAsApi($this->platformUser('platform_operations_admin'))
            ->api('POST', 'admin/products', $this->productPayload(['identifiers' => [['type' => 'gtin', 'value' => '06291041500214']]]))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'gtin_invalid');
    }

    public function test_concurrent_edits_are_detected_with_versions(): void
    {
        $manager = $this->platformUser('platform_operations_admin');
        $product = $this->makeProduct();

        $this->actingAsApi($manager)->api('PATCH', "admin/products/{$product->id}", ['description' => 'First edit', 'version' => 1])
            ->assertOk()->assertJsonPath('data.version', 2);

        $this->api('PATCH', "admin/products/{$product->id}", ['description' => 'Stale edit', 'version' => 1])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'product_version_conflict')
            ->assertJsonPath('error.details.current_version', 2);

        $this->assertSame('First edit', $product->fresh()->description);
    }

    public function test_regulatory_identifier_starts_unverified_and_records_verification_history(): void
    {
        $manager = $this->platformUser('platform_operations_admin');
        $officer = $this->platformUser('compliance_officer');
        $product = $this->makeProduct();

        // Test data, deliberately not a real registration number.
        $identifier = $this->actingAsApi($manager)->api('POST', "admin/products/{$product->id}/regulatory-identifiers", [
            'authority_code' => 'NAFDAC',
            'identifier_type' => 'registration_number',
            'value' => 'zz-fixture 0001',
            'declared_status' => 'registered',
            'expiry_date' => now()->addYears(2)->toDateString(),
        ])->assertCreated()
            ->assertJsonPath('data.value', 'ZZ-FIXTURE0001')
            ->assertJsonPath('data.declared.status', 'registered')
            ->assertJsonPath('data.platform_verification.status', 'unverified')
            ->json('data.id');

        // Managers cannot verify; compliance officers can.
        $this->api('POST', "admin/regulatory-identifiers/{$identifier}/verifications", ['method' => 'official_portal_lookup', 'result' => 'verified'])
            ->assertForbidden();

        $this->actingAsApi($officer)->api('POST', "admin/regulatory-identifiers/{$identifier}/verifications", ['method' => 'official_portal_lookup', 'result' => 'inconclusive'])
            ->assertUnprocessable()->assertJsonValidationErrors('notes');

        $this->api('POST', "admin/regulatory-identifiers/{$identifier}/verifications", [
            'method' => 'official_portal_lookup', 'result' => 'verified', 'source' => 'Regulator public register',
        ])->assertCreated()
            ->assertJsonPath('data.platform_verification.status', 'verified')
            ->assertJsonCount(1, 'data.verifications');

        $this->actingAsApi($manager)->api('GET', "products/{$product->id}")
            ->assertJsonPath('data.regulatory_identifiers.0.platform_verification.status', 'verified')
            ->assertJsonPath('data.regulatory_identifiers.0.platform_verification.notice', 'Platform verification record. It is not a statement or approval by the regulatory authority.');
    }

    public function test_submitter_cannot_verify_their_own_identifier(): void
    {
        $user = $this->platformUser('platform_super_admin');
        $product = $this->makeProduct();

        $id = $this->actingAsApi($user)->api('POST', "admin/products/{$product->id}/regulatory-identifiers", [
            'authority_code' => 'NAFDAC', 'identifier_type' => 'registration_number', 'value' => 'TEST-0001',
        ])->json('data.id');

        $this->api('POST', "admin/regulatory-identifiers/{$id}/verifications", ['method' => 'other', 'result' => 'verified'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'verification_conflict_of_interest');
    }

    public function test_verified_identifier_past_its_declared_expiry_reports_expired(): void
    {
        $product = $this->makeProduct();
        $identifier = RegulatoryIdentifier::query()->create([
            'subject_type' => 'product', 'subject_id' => $product->id,
            'authority_id' => RegulatoryAuthority::query()->where('code', 'NAFDAC')->value('id'),
            'country_code' => 'NG', 'identifier_type' => 'registration_number', 'value' => 'TEST-0002',
            'verification_status' => 'verified', 'expiry_date' => now()->subDay()->toDateString(),
        ]);

        $this->assertSame('expired', $identifier->effectiveStatus()->value);
        $this->assertSame('verified', $identifier->verification_status->value, 'history is not rewritten');
    }

    public function test_verification_history_is_append_only(): void
    {
        $product = $this->makeProduct();
        $identifier = RegulatoryIdentifier::query()->create([
            'subject_type' => 'product', 'subject_id' => $product->id,
            'authority_id' => RegulatoryAuthority::query()->where('code', 'NAFDAC')->value('id'),
            'country_code' => 'NG', 'identifier_type' => 'registration_number', 'value' => 'TEST-0003',
        ]);
        $verification = RegulatoryVerification::query()->create([
            'regulatory_identifier_id' => $identifier->id, 'method' => 'other', 'result' => 'failed', 'verified_at' => now(),
        ]);

        $this->expectException(LogicException::class);
        $verification->update(['result' => 'verified']);
    }

    public function test_tenants_cannot_write_the_canonical_catalogue_directly(): void
    {
        [$owner] = $this->organizationMember();

        $this->actingAsApi($owner)->api('POST', 'admin/products', $this->productPayload())->assertForbidden();
    }

    public function test_unverified_organizations_cannot_propose_products(): void
    {
        [$owner, $org] = $this->organizationMember();

        $this->actingAsApi($owner, $org)->api('POST', 'product-change-requests', ['payload' => $this->productPayload()])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'organization_not_verified');
    }

    public function test_verified_organization_proposal_is_reviewed_and_published(): void
    {
        [$owner, $org] = $this->organizationMember(verified: true);
        $reviewer = $this->platformUser('compliance_officer');

        $requestId = $this->actingAsApi($owner, $org)->api('POST', 'product-change-requests', [
            'payload' => $this->productPayload(['status' => 'active', 'regulatory_identifiers' => [[
                'authority_code' => 'NAFDAC', 'identifier_type' => 'registration_number', 'value' => 'TEST-1234',
            ]]]),
        ])->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');

        $this->assertArrayNotHasKey('status', ProductChangeRequest::acrossTenants()->find($requestId)->payload, 'proposers cannot set status');

        // Proposed product is not visible to tenants until approved.
        $this->api('GET', 'products?q=Testmox')->assertJsonCount(0, 'data');

        $productId = $this->actingAsApi($reviewer)->api('POST', "admin/product-change-requests/{$requestId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.regulatory_identifiers.0.platform_verification.status', 'unverified')
            ->json('data.id');

        $this->assertDatabaseHas('products', ['id' => $productId, 'submitted_by_organization_id' => $org->id]);
        $this->assertDatabaseHas('regulatory_identifiers', ['subject_id' => $productId, 'submitted_by_organization_id' => $org->id]);

        $this->actingAsApi($owner, $org)->api('GET', 'products?q=Testmox')->assertJsonCount(1, 'data');
        $this->api('GET', 'regulatory-identifiers/lookup?authority=NAFDAC&value=test-1234')
            ->assertOk()->assertJsonPath('meta.product_ids.0', $productId);

        // Decided once.
        $this->actingAsApi($reviewer)->api('POST', "admin/product-change-requests/{$requestId}/approve")->assertStatus(409);
    }

    public function test_update_proposal_against_stale_version_is_rejected_at_approval(): void
    {
        [$owner, $org] = $this->organizationMember(verified: true);
        $reviewer = $this->platformUser('compliance_officer');
        $manager = $this->platformUser('platform_operations_admin');
        $product = $this->makeProduct();

        $requestId = $this->actingAsApi($owner, $org)->api('POST', 'product-change-requests', [
            'product_id' => $product->id, 'base_version' => 1, 'payload' => ['description' => 'Corrected leaflet text'],
        ])->assertCreated()->json('data.id');

        $this->actingAsApi($manager)->api('PATCH', "admin/products/{$product->id}", ['description' => 'Edited meanwhile', 'version' => 1])->assertOk();

        $this->actingAsApi($reviewer)->api('POST', "admin/product-change-requests/{$requestId}/approve")
            ->assertStatus(409)->assertJsonPath('error.code', 'product_version_conflict');
        $this->assertSame('pending', ProductChangeRequest::acrossTenants()->find($requestId)->status);
    }

    public function test_product_search_by_name_identifier_and_filters(): void
    {
        [$owner] = $this->organizationMember();
        $this->makeProduct(['identifiers' => [['type' => 'gtin', 'value' => '06291041500213']]]);
        $this->makeProduct(['generic_name' => 'Artemether/Lumefantrine', 'brand_name' => 'Testmal', 'dosage_form_code' => 'tablet',
            'ingredients' => [['name' => 'Artemether', 'strength_value' => 20, 'strength_unit' => 'mg'], ['name' => 'Lumefantrine', 'strength_value' => 120, 'strength_unit' => 'mg']]]);

        $this->actingAsApi($owner);
        $this->api('GET', 'products?q=testmal')->assertJsonCount(1, 'data')->assertJsonPath('data.0.brand_name', 'Testmal');
        $this->api('GET', 'products?q=06291041500213')->assertJsonCount(1, 'data')->assertJsonPath('data.0.brand_name', 'Testmox');
        $this->api('GET', 'products?ingredient=lumefantrine')->assertJsonCount(1, 'data');
        $this->api('GET', 'products?dosage_form=capsule')->assertJsonCount(1, 'data');
        $this->api('GET', 'products?q=%25')->assertJsonCount(0, 'data');
    }

    public function test_organization_products_store_exact_decimal_prices_in_local_currency(): void
    {
        [$owner, $org] = $this->organizationMember();
        $product = $this->makeProduct();

        $this->actingAsApi($owner, $org)->api('POST', 'catalogue/items', [
            'product_id' => $product->id, 'sku' => 'AMX500', 'default_selling_price' => '1250.1250', 'default_purchase_cost' => '0.1',
        ])->assertCreated()
            ->assertJsonPath('data.currency_code', 'NGN')
            ->assertJsonPath('data.default_selling_price', '1250.1250')
            ->assertJsonPath('data.default_purchase_cost', '0.1000');

        $this->api('POST', 'catalogue/items', ['product_id' => $product->id, 'sku' => 'DUP'])
            ->assertUnprocessable()->assertJsonValidationErrors('product_id');
        $this->api('POST', 'catalogue/items', ['product_id' => $product->id, 'sku' => 'X', 'default_selling_price' => '1.12345'])
            ->assertUnprocessable()->assertJsonValidationErrors('default_selling_price');
    }

    public function test_products_under_review_cannot_be_stocked_by_tenants(): void
    {
        [$owner, $org] = $this->organizationMember();
        $product = $this->makeProduct(['status' => 'under_review']);

        $this->actingAsApi($owner, $org)->api('POST', 'catalogue/items', ['product_id' => $product->id, 'sku' => 'NEW'])
            ->assertUnprocessable()->assertJsonValidationErrors('product_id');
    }
}

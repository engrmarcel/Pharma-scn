<?php

namespace Tests\Feature\Web;

use App\Domain\Catalogue\Models\OrganizationProduct;
use App\Domain\Catalogue\Models\ProductChangeRequest;
use App\Domain\Parties\Models\Customer;
use App\Domain\Parties\Models\Supplier;
use App\Livewire\Catalogue\ChangeRequests;
use App\Livewire\Catalogue\Items;
use App\Livewire\Parties\Customers;
use App\Livewire\Parties\Suppliers;
use App\Support\Tenancy\TenantContext;
use Livewire\Livewire;
use Tests\FeatureTestCase;

class CatalogueAndPartiesScreensTest extends FeatureTestCase
{
    private function owner(bool $verified = false): array
    {
        [$owner, $org, $membership] = $this->organizationMember(orgAttributes: ['type' => 'pharmacy'], verified: $verified);
        app(TenantContext::class)->set($org, $membership);

        return [$owner, $org];
    }

    public function test_pages_render_through_the_router(): void
    {
        [$owner, $org] = $this->owner();
        $this->actingAs($owner)->withSession([config('platform.tenancy.session_key') => $org->id]);

        $this->get(route('catalogue.items'))->assertOk()->assertSee('Add product');
        $this->get(route('catalogue.change-requests'))->assertOk()->assertSee('Propose a new product');
        $this->get(route('parties.customers'))->assertOk()->assertSee('New customer');
        $this->get(route('parties.suppliers'))->assertOk()->assertSee('New supplier');
    }

    public function test_add_a_product_to_the_catalogue_with_the_picker(): void
    {
        [$owner, $org] = $this->owner();
        $product = $this->makeProduct(['generic_name' => 'Paracetamol', 'brand_name' => 'Webdol']);

        Livewire::actingAs($owner)->test(Items::class)
            ->call('create')->call('openPicker', 'item')->set('productSearch', 'Webdol')
            ->assertSee('Webdol')
            ->call('pick', $product->id)
            ->assertSet('item.product_id', $product->id)
            ->set('item.sku', 'PCM-500')->set('item.default_selling_price', '120')->call('save')
            ->assertHasNoErrors()->assertSet('item', null)->assertSee('PCM-500');

        $this->assertSame('PCM-500', OrganizationProduct::acrossTenants()->where('organization_id', $org->id)->sole()->sku);
    }

    public function test_verified_organizations_propose_products_and_errors_land_on_fields(): void
    {
        [$owner, $org] = $this->owner(verified: true);

        $component = Livewire::actingAs($owner)->test(ChangeRequests::class)->call('proposeNew')
            ->call('send')->assertHasErrors(['product.generic_name', 'product.dosage_form_code']);

        $component->set('product.generic_name', 'Webcillin')->set('product.dosage_form_code', 'capsule')
            ->set('product.ingredients.0.name', 'Webcillin')->set('product.ingredients.0.strength_value', '250')
            ->set('product.package_levels.0.code', 'capsule')->set('product.package_levels.0.name', 'Capsule')->set('product.package_levels.0.unit_code', 'capsule')
            ->call('send')->assertHasNoErrors()->assertSet('product', null)->assertSee('Webcillin');

        $this->assertSame('pending', ProductChangeRequest::query()->where('organization_id', $org->id)->sole()->status);
    }

    public function test_customer_and_supplier_accounts_with_contacts(): void
    {
        [$owner, $org] = $this->owner();

        Livewire::actingAs($owner)->test(Customers::class)
            ->call('create')->set('party.code', 'CUST1')->set('party.name', 'Ward Clinic')->set('party.type', 'clinic')->call('save')
            ->assertHasNoErrors()->assertSee('Ward Clinic')
            ->set('contact.name', 'Dr Ade')->set('contact.is_primary', true)->call('addContact')->assertSee('Dr Ade');

        $customer = Customer::acrossTenants()->where('organization_id', $org->id)->sole();
        $this->assertSame(1, $customer->contacts()->count());

        Livewire::actingAs($owner)->test(Suppliers::class)
            ->call('create')->set('party.code', 'SUP1')->set('party.name', 'Lagos Pharma')->set('party.type', 'distributor')->call('save')
            ->assertHasNoErrors()->set('detailTab', 'performance')->assertSee('Orders placed');
        $this->assertSame(1, Supplier::acrossTenants()->where('organization_id', $org->id)->count());
    }
}

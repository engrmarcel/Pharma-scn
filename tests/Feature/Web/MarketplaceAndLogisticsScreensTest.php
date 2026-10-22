<?php

namespace Tests\Feature\Web;

use App\Domain\Logistics\Models\Shipment;
use App\Domain\Marketplace\Models\MarketplaceListing;
use App\Domain\Marketplace\Models\MarketplaceOrder;
use App\Domain\Marketplace\Models\MarketplaceShipment;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Livewire\Logistics\Shipments;
use App\Livewire\Logistics\Vehicles;
use App\Livewire\Marketplace\Discover;
use App\Livewire\Marketplace\Listings;
use App\Livewire\Marketplace\Orders;
use App\Livewire\Marketplace\Profile;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Web\InternalApi;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithInventory;
use Tests\Concerns\InteractsWithMarketplace;
use Tests\FeatureTestCase;

class MarketplaceAndLogisticsScreensTest extends FeatureTestCase
{
    use InteractsWithInventory, InteractsWithMarketplace;

    private function as(User $user, $org): void
    {
        app(TenantContext::class)->set($org, OrganizationMembership::query()->where('organization_id', $org->id)->where('user_id', $user->id)->sole());
    }

    public function test_order_through_the_marketplace_from_search_to_receipt(): void
    {
        $s = $this->listedSupplier();
        $listingId = $this->listing($s, $s['amox']);
        $b = $this->tradingBuyer($s);

        $this->as($b['buyer'], $b['org']);
        Livewire::actingAs($b['buyer'])->test(Discover::class)
            ->set('search', 'Supplimox')->assertSee('Supplimox')
            ->call('add', collect(app(InternalApi::class)->get('marketplace/discover', ['q' => 'Supplimox'])['data'])->firstWhere('listing_id', $listingId))
            ->call('startCheckout', $s['org']->id)->set('checkout.delivery_warehouse_id', $b['warehouse']->id)->call('placeOrder')->assertHasNoErrors();
        $order = MarketplaceOrder::query()->sole();
        $this->assertSame('submitted', $order->status);

        $this->as($s['owner'], $s['org']);
        Livewire::withQueryParams(['open' => $order->id])->actingAs($s['owner'])->test(Orders::class)
            ->call('start', 'accept')->call('apply')->assertHasNoErrors()
            ->call('startShipment', app(InternalApi::class)->get("marketplace/orders/{$order->id}")['data'])->call('apply')->assertHasNoErrors()
            ->call('simple', 'invoice');
        $this->assertSame('shipped', $order->fresh()->status);

        $this->as($b['buyer'], $b['org']);
        Livewire::withQueryParams(['open' => $order->id])->actingAs($b['buyer'])->test(Orders::class)
            ->assertSee('Receive')->call('receive', MarketplaceShipment::query()->sole()->id)->assertHasNoErrors();
        $this->assertSame('received', $order->fresh()->status);

        // Supplier side: listings and profile.
        $this->as($s['owner'], $s['org']);
        Livewire::actingAs($s['owner'])->test(Listings::class)
            ->call('create')->call('openPicker', 'listing')->set('productSearch', 'CEF')->call('pick', $s['rx']->id)
            ->set('form.package_level_code', 'pack')->set('form.unit_price', '9000')->call('save')->assertHasNoErrors();
        $this->assertSame(2, MarketplaceListing::query()->count());
        Livewire::actingAs($s['owner'])->test(Profile::class)->set('form.headline', 'Fast delivery in Lagos')->call('save')->assertHasNoErrors();

        $this->actingAs($s['owner'])->withSession([config('platform.tenancy.session_key') => $s['org']->id]);
        foreach (['marketplace.discover' => 'Your orders in progress', 'marketplace.orders' => $order->number, 'marketplace.rfqs' => 'New RFQ', 'marketplace.quotes' => 'Quotes',
            'marketplace.listings' => 'New listing', 'marketplace.relationships' => 'our buyer', 'marketplace.disputes' => 'No disputes', 'marketplace.fees' => 'Fee rate',
            'marketplace.profile' => 'Fast delivery in Lagos'] as $route => $text) {
            $this->get(route($route))->assertOk()->assertSee($text);
        }
    }

    public function test_manual_shipment_assigned_dispatched_and_delivered(): void
    {
        $s = $this->stockedSupplier();
        $this->as($s['owner'], $s['org']);

        Livewire::actingAs($s['owner'])->test(Vehicles::class)
            ->call('create')->set('vehicle.registration', 'KJA-1')->call('save')->assertHasNoErrors()->assertSee('KJA-1');

        $screen = Livewire::actingAs($s['owner'])->test(Shipments::class)
            ->call('create')->set('form.source_type', 'manual')->set('form.origin_warehouse_id', $s['main']->id)->set('form.destination_name', 'Ikorodu depot')
            ->call('addItem')->call('openPicker', 'item.0')->set('productSearch', 'CEF')->call('pick', $s['rx']->id)
            ->set('form.items.0.batch_number', 'mp-cef-1')->set('form.items.0.quantity', '24')->call('save')->assertHasNoErrors();
        $shipment = Shipment::query()->sole();
        $detail = app(InternalApi::class)->get("logistics/shipments/{$shipment->id}")['data'];

        $screen->call('startStep', 'assign', $detail)->set('step.driver_name', 'Musa Bello')->call('applyStep')->assertHasNoErrors()
            ->call('dispatchShipment')
            ->call('startStep', 'deliver', $detail)->set('step.received_by_name', 'Depot manager')->call('applyStep')->assertHasNoErrors()
            ->assertSee('Received by Depot manager');
        $this->assertSame('delivered', $shipment->fresh()->status);

        $this->actingAs($s['owner'])->withSession([config('platform.tenancy.session_key') => $s['org']->id]);
        $this->get(route('logistics.shipments'))->assertOk()->assertSee('Ikorodu depot');
        $this->get(route('logistics.reconciliation'))->assertOk()->assertSee('Shipped');
    }
}

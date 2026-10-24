<?php

namespace Tests\Feature\Web;

use App\Domain\Marketplace\Models\MarketplaceOrder;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Livewire\Marketplace\Discover;
use App\Livewire\Marketplace\Hub;
use App\Livewire\Marketplace\Listings;
use App\Livewire\Marketplace\Storefront;
use App\Livewire\Marketplace\Suppliers;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Web\InternalApi;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithInventory;
use Tests\Concerns\InteractsWithMarketplace;
use Tests\FeatureTestCase;

/** The marketplace's trading floor, supplier directory and storefronts, with the cart that follows the buyer. */
class MarketplaceTradingFloorTest extends FeatureTestCase
{
    use InteractsWithInventory, InteractsWithMarketplace;

    private function as(User $user, Organization $org): void
    {
        app(TenantContext::class)->set($org, OrganizationMembership::query()->where('organization_id', $org->id)->where('user_id', $user->id)->sole());
    }

    public function test_activity_and_directory_through_the_api(): void
    {
        $s = $this->listedSupplier();
        $listingId = $this->listing($s, $s['amox'], ['allowed_buyer_types' => ['pharmacy', 'hospital']]);
        $b = $this->tradingBuyer($s);
        $order = $this->actingAsApi($b['buyer'], $b['org'])->api('POST', 'marketplace/orders', ['supplier_organization_id' => $s['org']->id,
            'delivery_warehouse_id' => $b['warehouse']->id, 'lines' => [['listing_id' => $listingId, 'quantity' => '10']]])->assertCreated()->json('data.id');
        $this->api('POST', "marketplace/orders/{$order}/submit")->assertOk();

        $buyer = $this->api('GET', 'marketplace/activity')->assertOk()->json('data');
        $this->assertSame([true, false], [$buyer['organization']['can_buy'], $buyer['organization']['can_sell']]);
        $this->assertSame(1, $buyer['buying']['awaiting_supplier']);
        $this->assertSame('12000.0000', $buyer['last_30_days']['purchases'][0]['amount']);
        $this->assertSame('order', $buyer['recent'][0]['kind']);

        $directory = $this->api('GET', 'marketplace/suppliers')->assertOk()->json('data');
        $this->assertSame([$s['org']->id, 'approved', true, 1], [$directory[0]['id'], $directory[0]['relationship'], $directory[0]['you_may_trade'], $directory[0]['active_listings']]);

        $offer = $this->api('GET', 'marketplace/discover', ['q' => 'Supplimox'])->assertOk()->json('data.0');
        $this->assertSame(['pharmacy', 'hospital'], $offer['allowed_buyer_types']);
        $this->assertSame('capsule', $offer['pack']['base_unit']);
        $this->assertSame(bcdiv('1200', $offer['pack']['base_unit_quantity'], 4), $offer['price_per_base_unit']);

        $supplier = $this->actingAsApi($s['owner'], $s['org'])->api('GET', 'marketplace/activity')->assertOk()->json('data');
        $this->assertSame([1, 1, true], [$supplier['selling']['orders_to_accept'], $supplier['selling']['active_listings'], $supplier['selling']['listed']]);
        $this->assertSame([], $supplier['last_30_days']['sales'], 'not a sale until accepted');
    }

    public function test_the_buyer_browses_storefronts_fills_one_cart_across_pages_and_orders(): void
    {
        $s = $this->listedSupplier();
        $listingId = $this->listing($s, $s['amox']);
        $b = $this->tradingBuyer($s);
        $this->as($b['buyer'], $b['org']);

        Livewire::actingAs($b['buyer'])->test(Suppliers::class)->assertSee($s['org']->name)->assertSee('Trading partner');

        $offer = collect(app(InternalApi::class)->get('marketplace/discover', ['supplier' => $s['org']->id])['data'])->firstWhere('listing_id', $listingId);
        Livewire::actingAs($b['buyer'])->test(Storefront::class, ['supplier' => $s['org']->id])
            ->assertSee('Nationwide distribution')->assertSee('Your trading partner')->assertSee('Ask for a quote')
            ->call('add', $offer)->assertSee('Order from '.$s['org']->name);

        // The cart follows the buyer to the trading floor and Discover, and the order goes from there.
        Livewire::actingAs($b['buyer'])->test(Hub::class)->assertSee('Order from '.$s['org']->name);
        Livewire::actingAs($b['buyer'])->test(Discover::class)->set('view', 'compare')->assertSee('Best price')
            ->call('setCartQuantity', $s['org']->id, $listingId, '12')
            ->call('startCheckout', $s['org']->id)->set('checkout.delivery_warehouse_id', $b['warehouse']->id)->call('placeOrder')->assertHasNoErrors();
        $order = MarketplaceOrder::query()->sole();
        $this->assertSame(['submitted', '12.0000'], [$order->status, (string) $order->lines()->value('quantity')]);
        Livewire::actingAs($b['buyer'])->test(Hub::class)->assertSee('Orders waiting for the supplier')->assertDontSee('Order from '.$s['org']->name);

        $this->as($s['owner'], $s['org']);
        Livewire::actingAs($s['owner'])->test(Hub::class)->assertSee('Orders to accept')->assertSee($order->number);
    }

    public function test_selling_screens_are_for_suppliers_and_listings_offer_only_their_buyer_types(): void
    {
        $s = $this->listedSupplier();
        $b = $this->tradingBuyer($s);
        $session = fn ($org) => [config('platform.tenancy.session_key') => $org->id];

        // A pharmacy buys but never sells: no selling tabs, and the pages refuse it.
        $page = $this->actingAs($b['owner'])->withSession($session($b['org']))->get(route('marketplace.home'))->assertOk();
        $page->assertSee(route('marketplace.suppliers'), false)->assertDontSee(route('marketplace.listings'), false);
        $this->get(route('marketplace.listings'))->assertForbidden();
        $this->get(route('marketplace.storefront', $s['org']->id))->assertOk()->assertSee($s['org']->name);

        // A distributor may narrow its listings only to the buyer types it may sell to.
        $this->as($s['owner'], $s['org']);
        Livewire::actingAs($s['owner'])->test(Listings::class)->call('create')
            ->assertSee('Pharmacy')->assertSee('Regional Distributor')->assertDontSee('Manufacturer')->assertSee('View my storefront');
        $this->flushSession();
        $this->actingAs($s['owner'])->withSession($session($s['org']))->get(route('marketplace.storefront', $s['org']->id))->assertOk()->assertSee('This is your storefront as buyers see it');
    }

    public function test_new_trading_organizations_join_the_marketplace_by_default(): void
    {
        [, $pharmacy] = $this->organizationMember(orgAttributes: ['type' => 'pharmacy']);
        [, $logistics] = $this->organizationMember(orgAttributes: ['type' => 'logistics_provider']);

        $this->assertTrue($pharmacy->hasModule('marketplace'));
        $this->assertFalse($logistics->hasModule('marketplace'), 'not a buyer or supplier of medicines');
    }
}

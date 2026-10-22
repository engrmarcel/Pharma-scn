<?php

namespace Tests\Feature\Web;

use App\Domain\Inventory\Models\Batch;
use App\Domain\Inventory\Models\StockTransfer;
use App\Livewire\Inventory\Adjustments;
use App\Livewire\Inventory\Batches;
use App\Livewire\Inventory\Counts;
use App\Livewire\Inventory\Receipts;
use App\Livewire\Inventory\Settings;
use App\Livewire\Inventory\Stock;
use App\Livewire\Inventory\Transfers;
use App\Support\Tenancy\TenantContext;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithInventory;
use Tests\FeatureTestCase;

class InventoryScreensTest extends FeatureTestCase
{
    use InteractsWithInventory;

    private function setUpStock(): array
    {
        [$owner, $org, $product, $main, $annex, $membership] = $this->stockedOrganization();
        $batch = $this->receive($org, $main, $product, 'WEB-1', '100', '+200 days', ['unit_cost' => '10']);
        app(TenantContext::class)->set($org, $membership);

        return compact('owner', 'org', 'product', 'main', 'annex', 'batch');
    }

    public function test_every_inventory_page_renders_through_the_router(): void
    {
        $s = $this->setUpStock();
        $this->actingAs($s['owner'])->withSession([config('platform.tenancy.session_key') => $s['org']->id]);

        foreach (['inventory.stock' => 'Nearest expiry', 'inventory.batches' => 'WEB-1', 'inventory.movements' => 'receipt', 'inventory.receipts' => 'Receive stock',
            'inventory.adjustments' => 'New adjustment', 'inventory.counts' => 'New count', 'inventory.transfers' => 'New transfer', 'inventory.settings' => 'Allow negative stock'] as $route => $text) {
            $this->get(route($route))->assertOk()->assertSee($text);
        }
    }

    public function test_stock_views_and_batch_drill_down(): void
    {
        $s = $this->setUpStock();

        Livewire::actingAs($s['owner'])->test(Stock::class)
            ->assertSee($s['product']->display_name)->assertSee('100')
            ->call('openDrill', $s['product']->id, $s['main']->id)->assertSee('WEB-1')
            ->set('tab', 'expiring')->set('days', 365)->assertSee('WEB-1')
            ->set('tab', 'valuation')->assertSee('1,000');
    }

    public function test_receive_adjust_and_quarantine(): void
    {
        $s = $this->setUpStock();

        Livewire::actingAs($s['owner'])->test(Receipts::class)
            ->set('form.warehouse_id', $s['main']->id)
            ->call('openPicker', 'line.0')->set('productSearch', 'AMX')->call('pick', $s['product']->id)
            ->set('lines.0.batch_number', 'WEB-2')->set('lines.0.expiry_date', now()->addYear()->toDateString())->set('lines.0.quantity', '3')
            ->set('lines.0.package_level_code', 'blister')
            ->call('receive')->assertHasNoErrors()->assertSee('received into');
        $web2 = Batch::acrossTenants()->where('organization_id', $s['org']->id)->where('batch_number', 'WEB-2')->sole();
        $this->assertSame('30.0000', $this->balance($s['org'], $s['main'], $web2)[0]);

        Livewire::actingAs($s['owner'])->test(Adjustments::class)
            ->call('create')->set('form.warehouse_id', $s['main']->id)->set('form.reason_code', 'damage')
            ->set('form.lines.0.batch_id', $s['batch']->id)->set('form.lines.0.quantity_delta', '-4')
            ->call('save')->assertHasNoErrors()->assertSet('form', null)->assertSee('damage');
        $this->assertSame('96.0000', $this->balance($s['org'], $s['main'], $s['batch'])[0]);

        Livewire::actingAs($s['owner'])->test(Batches::class)
            ->call('open', $s['batch']->id)->call('startDecision', 'quarantine')->call('decide')->assertHasErrors('decision.reason')
            ->set('decision.reason', 'Supplier alert')->call('decide')->assertHasNoErrors();
        $this->assertSame('quarantined', $s['batch']->fresh()->quality_status);
    }

    public function test_count_and_transfer_flows(): void
    {
        $s = $this->setUpStock();

        Livewire::actingAs($s['owner'])->test(Counts::class)
            ->call('create')->set('form.warehouse_id', $s['main']->id)->call('save')->assertHasNoErrors()
            ->assertSee('WEB-1')->set("counted.{$s['batch']->id}", '97')->call('post')->assertHasNoErrors();
        $this->assertSame('97.0000', $this->balance($s['org'], $s['main'], $s['batch'])[0]);

        $component = Livewire::actingAs($s['owner'])->test(Transfers::class)
            ->call('create')->set('form.from_warehouse_id', $s['main']->id)->set('form.to_warehouse_id', $s['annex']->id)
            ->call('openPicker', 'line.0')->set('productSearch', 'AMX')->call('pick', $s['product']->id)->set('form.lines.0.quantity', '7')
            ->call('save')->assertHasNoErrors()->assertSee('Dispatch')
            ->call('act', 'dispatch')->assertSee('WEB-1');
        $transfer = StockTransfer::acrossTenants()->where('organization_id', $s['org']->id)->sole();
        $this->assertSame('dispatched', $transfer->status);

        $component->call('receive')->assertHasNoErrors();
        $this->assertSame('received', $transfer->fresh()->status);
        $this->assertSame('7.0000', $this->balance($s['org'], $s['annex'], $s['batch'])[0]);
    }

    public function test_settings(): void
    {
        $s = $this->setUpStock();

        Livewire::actingAs($s['owner'])->test(Settings::class)->set('form.min_shelf_life_days', 30)->call('save')->assertHasNoErrors();
        $this->assertSame(30, data_get($s['org']->fresh()->settings, 'inventory.min_shelf_life_days'));
    }
}

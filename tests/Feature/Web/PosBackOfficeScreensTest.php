<?php

namespace Tests\Feature\Web;

use App\Domain\Pos\Models\PosRefund;
use App\Domain\Pos\Models\PosSale;
use App\Domain\Pos\Models\PosShift;
use App\Domain\Pos\Models\PosTerminal as PosTerminalModel;
use App\Livewire\Pos\Refunds;
use App\Livewire\Pos\Reports;
use App\Livewire\Pos\Shifts;
use App\Livewire\Pos\Terminal as PosTerminal;
use App\Livewire\Pos\Terminals;
use App\Support\Tenancy\TenantContext;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithInventory;
use Tests\FeatureTestCase;

class PosBackOfficeScreensTest extends FeatureTestCase
{
    use InteractsWithInventory;

    public function test_terminal_sale_shift_review_refund_and_reports(): void
    {
        [$owner, $org, $product, $main, , $ownerMembership] = $this->stockedOrganization();
        [$cashier, , $cashierMembership] = $this->organizationMember(['pos_cashier'], organization: $org);
        $this->receive($org, $main, $product, 'POS-B1', '100', '+1 year', ['unit_cost' => '50']);
        $this->actingAsApi($owner, $org)->api('POST', 'price-lists', ['code' => 'STD', 'name' => 'Standard', 'is_default' => true,
            'items' => [['product_id' => $product->id, 'package_level_code' => 'capsule', 'unit_price' => '150']]])->assertCreated();
        app(TenantContext::class)->set($org, $ownerMembership);

        // Back office registers a terminal.
        Livewire::actingAs($owner)->test(Terminals::class)
            ->call('create')->set('terminal.warehouse_id', $main->id)->set('terminal.code', 'TILL1')->set('terminal.name', 'Front counter')
            ->call('save')->assertHasNoErrors()->assertSee('Front counter');
        $terminal = PosTerminalModel::acrossTenants()->where('organization_id', $org->id)->sole();

        // The cashier sells 4 capsules for cash and closes the shift 10 short.
        app(TenantContext::class)->set($org, $cashierMembership);
        Livewire::actingAs($cashier)->test(PosTerminal::class)
            ->set('terminalId', $terminal->id)->set('openingFloat', '1000')->call('openShift')
            ->call('add', $product->id, $product->display_name, 'capsule')->call('setQuantity', 0, '4')->call('checkout')->set('tendered', '600')->call('pay')
            ->set('countedCash', '1590')->call('closeShift')->assertHasNoErrors();
        $shift = PosShift::acrossTenants()->where('organization_id', $org->id)->sole();
        $sale = PosSale::acrossTenants()->where('organization_id', $org->id)->sole();

        // The supervisor reviews the variance.
        app(TenantContext::class)->set($org, $ownerMembership);
        Livewire::actingAs($owner)->test(Shifts::class)
            ->assertSee($shift->number)->assertSee('-10')
            ->call('open', $shift->id)->assertSee($sale->number)
            ->set('notes', 'Counted twice')->call('review')->assertHasNoErrors();
        $this->assertNotNull($shift->fresh()->reviewed_at);

        // Refund one capsule, then approve it.
        $refunds = Livewire::actingAs($owner)->test(Refunds::class)
            ->set('saleNumber', $sale->number)->call('findSale')->assertSee($product->display_name)
            ->set('request.lines.'.$sale->lines()->first()->id.'.quantity', '1')->set('request.reason', 'Customer changed mind')
            ->set('request.refund_method', 'bank_transfer')->call('sendRequest')->assertHasNoErrors()->assertSet('request', null);
        $refund = PosRefund::acrossTenants()->where('organization_id', $org->id)->sole();
        // The requester cannot approve their own refund; a second supervisor can.
        $refunds->assertSee($refund->number)->call('decide', $refund->id, 'approve')->assertDispatched('notify', type: 'error');
        $this->assertSame('pending_approval', $refund->fresh()->status);
        [$supervisor, , $supervisorMembership] = $this->organizationMember(['pos_supervisor'], organization: $org);
        app(TenantContext::class)->set($org, $supervisorMembership);
        Livewire::actingAs($supervisor)->test(Refunds::class)->call('decide', $refund->id, 'approve')->assertHasNoErrors();
        $this->assertSame('approved', $refund->fresh()->status);
        app(TenantContext::class)->set($org, $ownerMembership);

        Livewire::actingAs($owner)->test(Reports::class)->assertSee('Net sales')->set('report', 'by-cashier')->assertSee($cashier->name);

        $this->actingAs($owner)->withSession([config('platform.tenancy.session_key') => $org->id]);
        foreach (['pos.terminals' => 'New terminal', 'pos.shifts' => 'To review', 'pos.refunds' => 'Request a refund', 'pos.payments' => 'Nothing waiting', 'pos.reports' => 'Summary'] as $route => $text) {
            $this->get(route($route))->assertOk()->assertSee($text);
        }
    }

    public function test_shift_and_sale_lists_through_the_api(): void
    {
        [$owner, $org] = $this->stockedOrganization();
        [$cashier] = $this->organizationMember(['pos_cashier'], organization: $org);

        $this->actingAsApi($owner, $org)->api('GET', 'pos/shifts')->assertOk()->assertJsonPath('total', 0);
        $this->api('GET', 'pos/sales')->assertOk();
        $this->actingAsApi($cashier, $org)->api('GET', 'pos/shifts')->assertForbidden();
        $this->api('GET', 'pos/sales')->assertOk();
    }
}

<?php

namespace Tests\Feature;

use App\Domain\Catalogue\Models\ProductClassification;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Pos\Models\PosSale;
use App\Domain\Pos\Models\PosShift;
use App\Domain\Pos\Services\PosSessionService;
use App\Livewire\Pos\Terminal;
use App\Support\Tenancy\TenantContext;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithInventory;
use Tests\FeatureTestCase;

/** The desktop POS terminal (full-screen till on the web), driven through the POS API. */
class PosTerminalTest extends FeatureTestCase
{
    use InteractsWithInventory;

    private function setUpTill(array $roles = ['pos_cashier']): array
    {
        [$owner, $org, $product, $main] = $this->stockedOrganization();
        [$cashier, , $membership] = $this->organizationMember($roles, organization: $org);
        $this->receive($org, $main, $product, 'R1', '100', '+1 year', ['unit_cost' => '50']);
        $this->actingAsApi($owner, $org)->api('POST', 'price-lists', ['code' => 'STD', 'name' => 'Standard', 'is_default' => true,
            'items' => [['product_id' => $product->id, 'package_level_code' => 'capsule', 'unit_price' => '150']]])->assertCreated();
        $terminal = $this->inTenant($org, function () use ($main) {
            app(TenantContext::class)->set(app(TenantContext::class)->organization(), OrganizationMembership::query()->first());

            return app(PosSessionService::class)->registerTerminal($main, 'T1', 'Counter');
        });

        // Livewire::test bypasses route middleware: establish the tenant as ResolveTenant would.
        app(TenantContext::class)->set($org, $membership);

        return compact('org', 'product', 'cashier', 'terminal', 'membership', 'owner');
    }

    public function test_cashier_opens_a_shift_sells_for_cash_and_gets_change_and_a_receipt(): void
    {
        $t = $this->setUpTill();
        $till = Livewire::actingAs($t['cashier'])->test(Terminal::class)
            ->assertSee('Open a shift')
            ->set('openingFloat', '1000')->call('openShift')->assertHasNoErrors()
            ->assertSet('shift.pos_terminal_id', $t['terminal']->id)->assertSee('Drawer');

        $till->call('add', $t['product']->id, 'Amoxicillin', 'capsule', 'otc', [['code' => 'capsule', 'name' => 'Capsule', 'unit_price' => '150']])
            ->call('add', $t['product']->id, 'Amoxicillin', 'capsule', 'otc')
            ->assertCount('lines', 1)->assertSet('lines.0.quantity', '2.0000')
            ->call('setQuantity', 0, '4')
            ->assertSet('sale.total', '600.0000')
            ->call('checkout')->assertSet('sale.status', 'awaiting_payment')
            ->set('tendered', '1000')->call('pay')
            ->assertSet('completed.change_due', '400.0000')
            ->assertDispatched('pos-sale-completed')
            ->assertSee('completed')->assertSet('sale', null)->assertSet('lines', []);

        $sale = PosSale::query()->where('status', 'completed')->sole();
        $this->assertSame($t['cashier']->id, $sale->cashier_id);
        $this->actingAs($t['cashier'])->withSession([config('platform.tenancy.session_key') => $t['org']->id])
            ->get(route('pos.receipt', $sale->id))->assertOk()->assertSee($sale->number)->assertSee('Batch R1');
    }

    public function test_server_refusals_are_shown_and_the_sale_can_be_held_resumed_and_cancelled(): void
    {
        $t = $this->setUpTill();
        $till = Livewire::actingAs($t['cashier'])->test(Terminal::class)->call('openShift')
            ->call('add', $t['product']->id, 'Amoxicillin', 'capsule')
            ->call('setQuantity', 0, '500') // more than in stock
            ->call('checkout')
            ->assertDispatched('notify', fn ($name, $p) => $p['type'] === 'error' && str_contains($p['message'], 'Not enough sellable stock'))
            ->assertSet('sale.status', 'open');

        $saleId = $till->get('sale.id');
        $till->call('setQuantity', 0, '3')->call('hold')->assertSet('sale', null)->assertSet('lines', []);
        $this->assertSame('held', PosSale::query()->find($saleId)->status);

        $till->set('dialog', 'held')->assertSee(PosSale::query()->find($saleId)->number)
            ->call('resume', $saleId)->assertSet('sale.id', $saleId)->assertSet('lines.0.quantity', '3')
            ->set('cancelReason', 'Customer left')->call('cancelSale')->assertSet('sale', null);
        $this->assertSame(['cancelled', 'Customer left'], [PosSale::query()->find($saleId)->status, PosSale::query()->find($saleId)->cancel_reason]);
    }

    public function test_prescription_only_items_ask_the_pharmacist_for_the_prescription(): void
    {
        $t = $this->setUpTill(['pharmacist']);
        $this->inTenant($t['org'], fn () => ProductClassification::query()->where('product_id', $t['product']->id)->delete());
        ProductClassification::query()->create(['product_id' => $t['product']->id, 'country_code' => 'NG', 'classification' => 'prescription_only']);

        $till = Livewire::actingAs($t['cashier'])->test(Terminal::class)->call('openShift')
            ->call('add', $t['product']->id, 'Amoxicillin', 'capsule', 'prescription_only')
            ->assertSet('dialog', 'prescription')->assertSet('prescriptionLine', 0)->assertHasErrors('prescription')
            ->assertCount('lines', 1);

        $till->set('prescription.reference', 'RX-1')->set('prescription.prescriber_name', 'Dr Test')->call('savePrescription')
            ->assertSet('dialog', null)->assertSet('lines.0.prescription.reference', 'RX-1')->assertSet('sale.total', '150.0000');
    }

    public function test_supervisor_moves_cash_and_the_cashier_closes_the_shift_with_a_count(): void
    {
        $t = $this->setUpTill(['pos_supervisor']);
        $till = Livewire::actingAs($t['cashier'])->test(Terminal::class)->set('openingFloat', '500')->call('openShift')
            ->set('cash', ['direction' => 'out', 'amount' => '200', 'reason' => 'Banking'])->call('cashMovement')->assertHasNoErrors()
            ->assertSet('shift.drawer', '300.0000')
            ->set('countedCash', '290')->call('closeShift')
            ->assertSet('shift', null)->assertSet('closedShift.variance', '-10.0000')->assertSee('Open a shift');
        $this->assertSame('closed', PosShift::query()->sole()->status);

        // A cashier cannot move cash.
        $cashier = $this->setUpTill();
        Livewire::actingAs($cashier['cashier'])->test(Terminal::class)->call('openShift')->assertDontSee('Cash in/out')
            ->set('cash', ['direction' => 'in', 'amount' => '50', 'reason' => 'x'])->call('cashMovement')
            ->assertDispatched('notify', fn ($name, $p) => $p['type'] === 'error');
        unset($till);
    }

    public function test_the_catalogue_endpoint_serves_the_terminals_products_and_the_page_needs_pos_access(): void
    {
        $t = $this->setUpTill();
        app(TenantContext::class)->clear();
        $session = [config('platform.tenancy.session_key') => $t['org']->id];

        $this->actingAs($t['cashier'])->withSession($session)->getJson(route('pos.catalogue', $t['terminal']->id))->assertOk()
            ->assertJsonPath('data.items.0.product_id', $t['product']->id)->assertJsonPath('data.items.0.package_levels.0.code', 'capsule');
        $this->actingAs($t['cashier'])->withSession($session)->get('/pos')->assertOk()->assertSee('Open a shift')->assertDontSee('Workspace');

        $this->flushSession();
        $this->actingAs($t['cashier'])->get('/pos')->assertStatus(400); // no organization chosen
        [$auditor] = $this->organizationMember(['auditor'], organization: $t['org']);
        $this->actingAs($auditor)->withSession($session)->get('/pos')->assertForbidden();
        $this->actingAs($auditor)->withSession($session)->getJson(route('pos.catalogue', $t['terminal']->id))->assertForbidden();
    }
}

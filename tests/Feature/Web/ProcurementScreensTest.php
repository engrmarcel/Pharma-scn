<?php

namespace Tests\Feature\Web;

use App\Domain\Inventory\Models\Batch;
use App\Domain\Parties\Models\Supplier;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\PurchaseRequisition;
use App\Domain\Procurement\Models\SupplierInvoice;
use App\Livewire\Procurement\GoodsReceipts;
use App\Livewire\Procurement\PurchaseOrders;
use App\Livewire\Procurement\Requisitions;
use App\Livewire\Procurement\Settings;
use App\Livewire\Procurement\SupplierInvoices;
use App\Livewire\Procurement\SupplierReturns;
use App\Support\Tenancy\TenantContext;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithInventory;
use Tests\FeatureTestCase;

class ProcurementScreensTest extends FeatureTestCase
{
    use InteractsWithInventory;

    private function setUpBuying(): array
    {
        [$owner, $org, $product, $main, $annex, $membership] = $this->stockedOrganization();
        $supplier = $this->inTenant($org, fn () => Supplier::query()->create(['code' => 'SUP1', 'name' => 'Acme Distribution', 'type' => 'distributor', 'currency_code' => 'NGN']));
        app(TenantContext::class)->set($org, $membership);

        return compact('owner', 'org', 'product', 'main', 'supplier', 'membership');
    }

    public function test_pages_render_through_the_router(): void
    {
        $s = $this->setUpBuying();
        $this->actingAs($s['owner'])->withSession([config('platform.tenancy.session_key') => $s['org']->id]);

        foreach (['procurement.requisitions' => 'New requisition', 'procurement.orders' => 'New purchase order', 'procurement.receipts' => 'Receive against a purchase order',
            'procurement.invoices' => 'Record invoice', 'procurement.returns' => 'New return', 'procurement.suggestions' => 'Nothing needs reordering',
            'procurement.settings' => 'Approval threshold'] as $route => $text) {
            $this->get(route($route))->assertOk()->assertSee($text);
        }
    }

    public function test_procure_to_pay_through_the_screens(): void
    {
        $s = $this->setUpBuying();
        Livewire::actingAs($s['owner'])->test(Settings::class)->set('form.allow_self_approval', true)->call('save')->assertHasNoErrors();

        // Order: draft, submit, approve, send.
        $orders = Livewire::actingAs($s['owner'])->test(PurchaseOrders::class)
            ->call('create')->set('doc.supplier_id', $s['supplier']->id)->set('doc.warehouse_id', $s['main']->id)
            ->call('openPicker', 'line.0')->set('productSearch', 'AMX')->call('pick', $s['product']->id)
            ->set('doc.lines.0.package_level_code', 'pack')->set('doc.lines.0.quantity', '5')->set('doc.lines.0.unit_price', '2000')
            ->call('save')->assertHasNoErrors()->assertSet('doc', null)->assertSee('10,000');
        $po = PurchaseOrder::acrossTenants()->where('organization_id', $s['org']->id)->sole();
        $orders->call('act', 'submit')->call('act', 'approve')->call('act', 'send');
        $this->assertSame('sent', $po->fresh()->status);

        // Rejecting asks for a reason first.
        $orders->call('act', 'close')->assertSet('reasoned.action', 'close')->set('reasoned', null);

        // Receive 3 of 5 packs.
        Livewire::withQueryParams(['order' => $po->id])->actingAs($s['owner'])->test(GoodsReceipts::class)
            ->assertSee('Outstanding')->set('lines.0.batch_number', 'GRN-WEB')->set('lines.0.expiry_date', now()->addYear()->toDateString())->set('lines.0.quantity', '3')
            ->call('receive')->assertHasNoErrors()->assertSee('GRN-');
        $this->assertSame('partially_received', $po->fresh()->status);

        // Invoice what was received, matched, then approve.
        Livewire::withQueryParams(['order' => $po->id])->actingAs($s['owner'])->test(SupplierInvoices::class)
            ->assertSet('doc.lines.0.quantity', '3')->set('doc.supplier_invoice_number', 'ACME-77')->call('save')->assertHasNoErrors()
            ->assertSee('ACME-77')->call('approve')->assertHasNoErrors();
        $invoice = SupplierInvoice::acrossTenants()->where('organization_id', $s['org']->id)->sole();
        $this->assertSame('matched', $invoice->match_status);
        $this->assertSame('approved', $invoice->status);

        // Return one capsule pack's worth of base units from the received batch.
        $batch = Batch::acrossTenants()->where('batch_number', 'GRN-WEB')->sole();
        Livewire::actingAs($s['owner'])->test(SupplierReturns::class)
            ->call('create')->set('doc.supplier_id', $s['supplier']->id)->set('doc.warehouse_id', $s['main']->id)
            ->set('doc.lines.0.batch_id', $batch->id)->set('doc.lines.0.base_quantity', '20')->call('save')->assertHasNoErrors()->assertSee('damaged');
    }

    public function test_requisition_approval_needs_a_second_person_by_default(): void
    {
        $s = $this->setUpBuying();

        $req = Livewire::actingAs($s['owner'])->test(Requisitions::class)
            ->call('create')->set('doc.warehouse_id', $s['main']->id)
            ->call('openPicker', 'line.0')->set('productSearch', 'AMX')->call('pick', $s['product']->id)->set('doc.lines.0.quantity', '2')
            ->call('save')->assertHasNoErrors()->call('act', 'submit')->call('act', 'approve');
        $requisition = PurchaseRequisition::acrossTenants()->where('organization_id', $s['org']->id)->sole();
        $this->assertSame('submitted', $requisition->status, 'self-approval is refused by default');
        $req->assertDispatched('notify', type: 'error');
    }
}

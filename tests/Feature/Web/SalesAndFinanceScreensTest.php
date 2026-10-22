<?php

namespace Tests\Feature\Web;

use App\Domain\Finance\Models\Account;
use App\Domain\Finance\Models\JournalEntry;
use App\Domain\Parties\Models\Customer;
use App\Domain\Sales\Models\PriceList;
use App\Domain\Sales\Models\SalesInvoice;
use App\Domain\Sales\Models\SalesOrder;
use App\Livewire\Finance\Accounts;
use App\Livewire\Finance\Journals;
use App\Livewire\Finance\Reports;
use App\Livewire\Sales\Invoices;
use App\Livewire\Sales\Payments;
use App\Livewire\Sales\PriceLists;
use App\Livewire\Sales\Quotations;
use App\Livewire\Sales\SalesOrders;
use App\Support\Tenancy\TenantContext;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithInventory;
use Tests\FeatureTestCase;

class SalesAndFinanceScreensTest extends FeatureTestCase
{
    use InteractsWithInventory;

    private function setUpSelling(): array
    {
        [$owner, $org, $product, $main, $annex, $membership] = $this->stockedOrganization();
        $this->receive($org, $main, $product, 'SALE-1', '200', '+1 year', ['unit_cost' => '40']);
        $customer = $this->inTenant($org, fn () => Customer::query()->create(['code' => 'C1', 'name' => 'Lekki Clinic', 'type' => 'clinic', 'currency_code' => 'NGN', 'payment_terms_days' => 14]));
        app(TenantContext::class)->set($org, $membership);

        return compact('owner', 'org', 'product', 'main', 'customer');
    }

    public function test_pages_render_through_the_router(): void
    {
        $s = $this->setUpSelling();
        $this->actingAs($s['owner'])->withSession([config('platform.tenancy.session_key') => $s['org']->id]);

        foreach (['sales.orders' => 'New sales order', 'sales.quotations' => 'New quotation', 'sales.invoices' => 'Receive a payment', 'sales.payments' => 'Receive a payment',
            'sales.aging' => 'Not yet due', 'sales.price-lists' => 'New price list', 'sales.settings' => 'Largest discount',
            'finance.reports' => 'Profit and loss', 'finance.journals' => 'Manual entry', 'finance.payments' => 'Pay a supplier',
            'finance.accounts' => 'Chart of accounts', 'finance.periods' => 'closed period'] as $route => $text) {
            $this->get(route($route))->assertOk()->assertSee($text);
        }
    }

    public function test_order_to_cash_through_the_screens(): void
    {
        $s = $this->setUpSelling();

        Livewire::actingAs($s['owner'])->test(PriceLists::class)
            ->call('create')->set('list.code', 'STD')->set('list.name', 'Standard')->set('list.is_default', true)->call('save')->assertHasNoErrors()
            ->call('editItems', PriceList::acrossTenants()->where('organization_id', $s['org']->id)->sole()->id)
            ->call('addItem')->call('openPicker', 'item.0')->set('productSearch', 'AMX')->call('pick', $s['product']->id)
            ->set('editing.items.0.package_level_code', 'pack')->set('editing.items.0.unit_price', '3000')->call('saveItems')->assertHasNoErrors()->assertSee('3,000');

        $orders = Livewire::actingAs($s['owner'])->test(SalesOrders::class)
            ->call('create')->set('doc.customer_id', $s['customer']->id)->set('doc.warehouse_id', $s['main']->id)
            ->call('openPicker', 'line.0')->set('productSearch', 'AMX')->call('pick', $s['product']->id)
            ->set('doc.lines.0.package_level_code', 'pack')->set('doc.lines.0.quantity', '3')
            ->call('save')->assertHasNoErrors()->assertSee('9,000')
            ->call('act', 'confirm')->call('startDelivery')->assertSet('delivering', fn ($d) => in_array('3', $d, true))
            ->call('deliver')->assertHasNoErrors()->call('invoice');
        $order = SalesOrder::acrossTenants()->where('organization_id', $s['org']->id)->sole();
        $this->assertSame('delivered', $order->status);
        $invoice = SalesInvoice::acrossTenants()->where('organization_id', $s['org']->id)->sole();
        $this->assertSame('9000.0000', (string) $invoice->total);

        // Credit one pack for a price adjustment: 6,000 stays open.
        Livewire::withQueryParams(['open' => $invoice->id])->actingAs($s['owner'])->test(Invoices::class)
            ->call('startCredit')->set('credit.reason_code', 'price_adjustment')
            ->set('credit.lines.'.$invoice->lines()->first()->id.'.quantity', '1')->call('saveCredit')->assertHasNoErrors()
            ->assertSet('credit', null)->assertSee('CN-');

        // Payment allocated oldest-first, then the invoice shows as paid.
        Livewire::withQueryParams(['customer' => $s['customer']->id])->actingAs($s['owner'])->test(Payments::class)
            ->assertSee($invoice->number)->set('form.amount', '6000')->call('autoAllocate')->call('receive')->assertHasNoErrors();
        $this->assertSame('paid', $invoice->fresh()->payment_status);

        // Revenue reaches the profit and loss report.
        Livewire::actingAs($s['owner'])->test(Reports::class)->assertSee('Revenue')->assertSee('9,000');
    }

    public function test_quotation_converts_into_an_order(): void
    {
        $s = $this->setUpSelling();

        Livewire::actingAs($s['owner'])->test(Quotations::class)
            ->call('create')->set('doc.customer_id', $s['customer']->id)->set('doc.warehouse_id', $s['main']->id)
            ->call('openPicker', 'line.0')->set('productSearch', 'AMX')->call('pick', $s['product']->id)
            ->set('doc.lines.0.package_level_code', 'pack')->set('doc.lines.0.unit_price', '2500')->call('save')->assertHasNoErrors()
            ->call('convert')->assertRedirect();
        $this->assertSame(1, SalesOrder::acrossTenants()->where('organization_id', $s['org']->id)->count());
    }

    public function test_manual_journal_and_new_account(): void
    {
        $s = $this->setUpSelling();

        Livewire::actingAs($s['owner'])->test(Accounts::class)
            ->call('create')->set('account.code', '6100')->set('account.name', 'Courier costs')->set('account.type', 'expense')->call('save')->assertHasNoErrors()->assertSee('Courier costs');
        $courier = Account::acrossTenants()->where('organization_id', $s['org']->id)->where('code', '6100')->sole();
        $bank = Account::acrossTenants()->where('organization_id', $s['org']->id)->where('type', 'asset')->where('system_key', 'bank')->first()
            ?? Account::acrossTenants()->where('organization_id', $s['org']->id)->where('type', 'asset')->first();

        Livewire::actingAs($s['owner'])->test(Journals::class)
            ->call('create')->set('entry.description', 'Courier for March')
            ->set('entry.lines.0.account_id', $courier->id)->set('entry.lines.0.debit', '1500')
            ->set('entry.lines.1.account_id', $bank->id)->set('entry.lines.1.credit', '1400')
            ->call('save')->assertDispatched('notify', type: 'error')->assertSet('entry.description', 'Courier for March')
            ->set('entry.lines.1.credit', '1500')->call('save')->assertHasNoErrors()->assertSee('Courier for March');
        $this->assertSame(1, JournalEntry::acrossTenants()->where('organization_id', $s['org']->id)->where('source_type', 'manual')->count());
    }
}

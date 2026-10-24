<?php

namespace App\Livewire\Pos;

use App\Domain\Platform\Services\BrandingService;
use App\Livewire\WebPage;
use App\Support\Tenancy\TenantContext;
use App\Support\Web\ApiError;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;

/**
 * Desktop POS terminal: a full-screen, keyboard-first till for counters that sell from a computer
 * instead of the POS app. The product list is the terminal's catalogue, loaded once and searched in
 * the browser; the cart, prices, tax, stock reservation and payments are always the server's
 * (POS API, ADR-0024), so the desktop till follows exactly the same rules as the app.
 *
 * It needs a connection: selling through an outage is what the POS app is for (OFFLINE_OUTAGE_PLAYBOOK.md).
 */
#[Layout('layouts.pos')]
#[Title('POS terminal')]
class Terminal extends WebPage
{
    public const ROUTE = 'pos.register';

    /** @var array{id: string, number: string, pos_terminal_id: string, opened_at: string, opening_float: string, drawer: string}|null */
    public ?array $shift = null;

    public array $terminals = [];

    public array $permissions = [];

    public array $paymentMethods = [];

    public string $currency = '';

    public string $cashierName = '';

    // Opening a shift
    public string $terminalId = '';

    public string $openingFloat = '0';

    /**
     * The cart as the cashier built it; the server prices it.
     *
     * @var list<array{product_id: string, name: string, classification: ?string, package_level_code: ?string, quantity: string, discount_percent: string, prescription: ?array}>
     */
    public array $lines = [];

    /** The priced sale from the server (lines, totals, payments, status). */
    public ?array $sale = null;

    /** Customer the sale is for (null = walk-in). */
    public ?array $customer = null;

    /** The finished sale shown until the next one starts. */
    public ?array $completed = null;

    // Payment entry
    public string $payMethod = 'cash';

    public string $tendered = '';

    public string $approvalCode = '';

    /** What a standalone terminal took: card, bank_transfer or mobile_money. */
    public string $terminalMethod = 'card';

    /** Instructions from an electronic provider while its confirmation is awaited. */
    public ?array $providerInstructions = null;

    /** Open dialog: customer, prescription, cash, close, sales, held, cancel. */
    public ?string $dialog = null;

    public ?int $prescriptionLine = null;

    public array $prescription = ['reference' => '', 'prescriber_name' => '', 'prescribed_on' => ''];

    public string $customerSearch = '';

    public array $cash = ['direction' => 'in', 'amount' => '', 'reason' => ''];

    public string $countedCash = '';

    public string $closeNotes = '';

    public ?array $closedShift = null;

    public string $cancelReason = '';

    public function mount(): void
    {
        $this->loadMe();
        $methods = $this->read('organization/payment-methods', [], ['data' => [], 'meta' => []]);
        $this->paymentMethods = $methods['data'];
        $this->currency = (string) ($methods['meta']['currency_code'] ?? '');
    }

    private function loadMe(): void
    {
        $me = $this->api()->get('pos/me')['data'];
        $this->shift = $me['shift'];
        $this->terminals = $me['terminals'];
        $this->permissions = $me['permissions'];
        $this->cashierName = $me['user']['name'];
        if ($this->terminalId === '' && count($this->terminals) === 1) {
            $this->terminalId = $this->terminals[0]['id'];
        }
    }

    // ---- Shift -----------------------------------------------------------------------------

    public function openShift(): void
    {
        if ($this->submit('shift', 'pos/shifts', ['pos_terminal_id' => $this->terminalId, 'opening_float' => $this->openingFloat === '' ? '0' : $this->openingFloat], 'Shift opened. Ready to sell.') !== null) {
            $this->closedShift = null;
            $this->loadMe();
        }
    }

    public function cashMovement(): void
    {
        $amount = trim($this->cash['amount']);
        $signed = $this->cash['direction'] === 'out' && $amount !== '' ? '-'.ltrim($amount, '-') : $amount;
        if ($this->submit('cash', "pos/shifts/{$this->shift['id']}/cash-movements", ['amount' => $signed, 'reason' => $this->cash['reason']],
            $this->cash['direction'] === 'in' ? 'Cash added to the drawer.' : 'Cash taken from the drawer.') !== null) {
            $this->cash = ['direction' => 'in', 'amount' => '', 'reason' => ''];
            $this->dialog = null;
            $this->refreshShift();
        }
    }

    public function closeShift(): void
    {
        if ($this->sale !== null && in_array($this->sale['status'], ['open', 'awaiting_payment'], true)) {
            $this->notify('Finish, hold or cancel the current sale before closing the shift.', 'error');

            return;
        }
        $result = $this->submit('close', "pos/shifts/{$this->shift['id']}/close", ['counted_cash' => $this->countedCash === '' ? '0' : $this->countedCash, 'notes' => $this->closeNotes ?: null], 'Shift closed.');
        if ($result !== null) {
            $this->closedShift = $result['data'];
            $this->shift = null;
            $this->dialog = null;
            $this->countedCash = $this->closeNotes = '';
            $this->resetSale();
        }
    }

    private function refreshShift(): void
    {
        $this->shift = $this->read('pos/shifts/current', [], ['data' => null])['data'] ?? $this->shift;
        if ($this->shift !== null) {
            $this->shift = collect($this->shift)->only(['id', 'number', 'pos_terminal_id', 'opened_at', 'opening_float', 'drawer'])->all();
        }
    }

    // ---- Cart ------------------------------------------------------------------------------

    /** Add one unit of a product (from the catalogue in the browser); the same product and pack size adds up. */
    public function add(string $productId, string $name, ?string $level = null, ?string $classification = null, array $levels = [], string $quantity = '1'): void
    {
        if (! $this->editable()) {
            return;
        }
        $this->completed = null;
        $lines = $this->lines;
        foreach ($lines as $i => $line) {
            if ($line['product_id'] === $productId && $line['package_level_code'] === $level && $line['prescription'] === null) {
                $lines[$i]['quantity'] = bcadd($line['quantity'], $quantity, 4);
                $this->price($lines, $i);

                return;
            }
        }
        // Pack sizes the product sells in (code => name), for switching on the line.
        $sizes = collect($levels)->filter(fn ($l) => is_array($l) && isset($l['code']) && ($l['unit_price'] ?? null) !== null)->mapWithKeys(fn ($l) => [$l['code'] => (string) ($l['name'] ?? $l['code'])])->all();
        $lines[] = ['product_id' => $productId, 'name' => $name, 'classification' => $classification, 'package_level_code' => $level, 'levels' => $sizes,
            'quantity' => $quantity, 'discount_percent' => '0', 'prescription' => null];
        $this->price($lines, count($lines) - 1);
    }

    public function setQuantity(int $index, string $quantity): void
    {
        $quantity = trim($quantity);
        if (! isset($this->lines[$index]) || ! $this->editable()) {
            return;
        }
        if ($quantity === '' || ! is_numeric($quantity) || (float) $quantity <= 0) {
            $this->removeLine($index);

            return;
        }
        $lines = $this->lines;
        $lines[$index]['quantity'] = $quantity;
        $this->price($lines, $index);
    }

    public function step(int $index, int $delta): void
    {
        if (isset($this->lines[$index])) {
            $this->setQuantity($index, bcadd($this->lines[$index]['quantity'], (string) $delta, 4));
        }
    }

    public function setLevel(int $index, string $level): void
    {
        if (isset($this->lines[$index]) && $this->editable()) {
            $lines = $this->lines;
            $lines[$index]['package_level_code'] = $level;
            $this->price($lines, $index);
        }
    }

    public function setDiscount(int $index, string $percent): void
    {
        if (isset($this->lines[$index]) && $this->editable()) {
            $lines = $this->lines;
            $lines[$index]['discount_percent'] = is_numeric(trim($percent)) ? trim($percent) : '0';
            $this->price($lines, $index);
        }
    }

    public function removeLine(int $index): void
    {
        if (! isset($this->lines[$index]) || ! $this->editable()) {
            return;
        }
        $lines = $this->lines;
        array_splice($lines, $index, 1);
        $this->price($lines, null);
    }

    public function startPrescription(int $index): void
    {
        $this->prescriptionLine = $index;
        $this->prescription = ($this->lines[$index]['prescription'] ?? null) ?: ['reference' => '', 'prescriber_name' => '', 'prescribed_on' => business_today()->toDateString()];
        $this->resetErrorBag();
        $this->dialog = 'prescription';
    }

    public function savePrescription(): void
    {
        $lines = $this->lines;
        $lines[$this->prescriptionLine]['prescription'] = array_map('trim', $this->prescription);
        if ($this->price($lines, $this->prescriptionLine)) {
            $this->dialog = null;
        }
    }

    /**
     * Save the cart to the server, which prices it (or refuses it). Starts the sale on the first
     * line. On refusal the cart stays as it was, and a missing prescription opens its dialog.
     */
    private function price(array $lines, ?int $changed): bool
    {
        try {
            if ($this->sale === null) {
                if ($lines === []) {
                    $this->lines = [];

                    return true;
                }
                $this->sale = $this->api()->post('pos/sales', array_filter(['pos_shift_id' => $this->shift['id'], 'client_transaction_id' => strtolower((string) Str::ulid()),
                    'customer_id' => $this->customer['id'] ?? null]))['data'];
            }
            $payload = array_map(fn ($l) => array_filter([
                'product_id' => $l['product_id'], 'quantity' => $l['quantity'], 'package_level_code' => $l['package_level_code'],
                'discount_percent' => $l['discount_percent'] !== '0' ? $l['discount_percent'] : null, 'prescription' => $l['prescription'],
            ], fn ($v) => $v !== null), $lines);
            $this->sale = $this->api()->put("pos/sales/{$this->sale['id']}/lines", ['lines' => $payload])['data'];
            $this->lines = $lines;

            return true;
        } catch (ApiError $e) {
            $line = $e->details['line'] ?? $changed;
            if (in_array($e->errorCode, ['prescription_required'], true) && $line !== null && isset($lines[$line])) {
                // Keep the line so the pharmacist can record the prescription for it.
                $this->lines = $lines;
                $this->startPrescription((int) $line);
                $this->addError('prescription', $e->getMessage());

                return false;
            }
            $this->notify($e->getMessage(), 'error');

            return false;
        }
    }

    // ---- Customer --------------------------------------------------------------------------

    public function chooseCustomer(?string $id, ?string $name): void
    {
        if ($this->sale !== null) {
            $this->notify('The customer is set when the sale starts. Hold or cancel this sale to sell to someone else.', 'warning');

            return;
        }
        $this->customer = $id ? ['id' => $id, 'name' => $name] : null;
        $this->dialog = null;
    }

    // ---- Checkout and payment --------------------------------------------------------------

    public function checkout(): void
    {
        if ($this->sale === null || $this->lines === []) {
            $this->notify('The cart is empty.', 'warning');

            return;
        }
        $unrecorded = collect($this->lines)->search(fn ($l) => ($l['classification'] ?? null) === 'prescription_only' && empty($l['prescription']));
        if ($unrecorded !== false && $this->can('pos.dispense.prescription')) {
            $this->startPrescription($unrecorded);

            return;
        }
        $this->attempt(function ($api) {
            $this->sale = $api->post("pos/sales/{$this->sale['id']}/checkout")['data'];
            $this->tendered = '';
            $this->payMethod = 'cash';
        });
    }

    public function pay(?string $amount = null): void
    {
        if (($this->sale['status'] ?? null) !== 'awaiting_payment') {
            return;
        }
        $outstanding = $this->outstanding();
        $method = collect($this->paymentMethods)->firstWhere('channel', $this->payMethod) ?? ['channel' => 'cash', 'kind' => 'cash'];
        $payload = $method['channel'] === 'cash'
            ? ['method' => 'cash', 'amount' => $this->min($amount ?? ($this->tendered !== '' ? $this->tendered : $outstanding), $outstanding), 'tendered' => $amount ?? ($this->tendered !== '' ? $this->tendered : $outstanding)]
            : ['method' => $method['channel'] === 'manual_terminal' ? $this->terminalMethod : (in_array($method['kind'], ['card', 'mobile_money', 'bank_transfer'], true) ? $method['kind'] : 'mobile_money'), 'amount' => $amount ?? $outstanding,
                'channel' => $method['channel'], 'approval_code' => $method['channel'] === 'manual_terminal' ? trim($this->approvalCode) : null];

        $result = $this->submit('payment', "pos/sales/{$this->sale['id']}/payments", array_filter($payload, fn ($v) => $v !== null && $v !== ''));
        if ($result === null) {
            return;
        }
        $this->sale = $result['data']['sale'];
        $this->providerInstructions = $result['data']['instructions'] ?? null;
        $this->tendered = $this->approvalCode = '';
        $this->afterPayment();
    }

    /** While an electronic payment is awaited, the screen polls the sale. */
    public function refreshSale(): void
    {
        if ($this->sale !== null) {
            $this->sale = $this->read("pos/sales/{$this->sale['id']}", [], ['data' => $this->sale])['data'];
            $this->afterPayment();
        }
    }

    private function afterPayment(): void
    {
        if (($this->sale['status'] ?? null) === 'completed') {
            $this->completed = ['id' => $this->sale['id'], 'number' => $this->sale['number'], 'total' => $this->sale['total'], 'change_due' => $this->sale['change_due'],
                'payments' => collect($this->sale['payments'] ?? [])->where('status', 'confirmed')->map(fn ($p) => ['method' => $p['method'], 'amount' => $p['amount']])->values()->all()];
            $this->resetSale();
            $this->refreshShift();
            $this->dispatch('pos-sale-completed', receipt: route('pos.receipt', $this->completed['id']));
        }
    }

    public function hold(): void
    {
        if ($this->sale === null || $this->sale['status'] !== 'open') {
            return;
        }
        if ($this->attempt(fn ($api) => $api->post("pos/sales/{$this->sale['id']}/hold"), "Sale {$this->sale['number']} held.") !== null) {
            $this->resetSale();
        }
    }

    public function resume(string $saleId): void
    {
        if ($this->sale !== null) {
            $this->notify('Hold or finish the current sale first.', 'warning');

            return;
        }
        $sale = $this->read("pos/sales/{$saleId}", [], ['data' => null])['data'];
        if ($sale === null) {
            return;
        }
        $names = $this->productNames(collect($sale['lines'])->pluck('product_id')->all());
        $this->sale = $sale;
        $this->lines = collect($sale['lines'])->map(fn ($l) => ['product_id' => $l['product_id'], 'name' => $names[$l['product_id']] ?? $l['product_id'], 'levels' => [],
            'classification' => $l['prescription']['classification'] ?? null, 'package_level_code' => $l['package_level_code'],
            'quantity' => rtrim(rtrim((string) $l['quantity'], '0'), '.'), 'discount_percent' => rtrim(rtrim((string) $l['discount_percent'], '0'), '.') ?: '0',
            'prescription' => isset($l['prescription']['reference']) ? collect($l['prescription'])->only(['reference', 'prescriber_name', 'prescribed_on'])->all() : null])->all();
        $this->customer = $sale['customer_id'] ? ['id' => $sale['customer_id'], 'name' => $this->read("customers/{$sale['customer_id']}", [], ['data' => ['name' => 'Customer']])['data']['name']] : null;
        $this->completed = null;
        $this->dialog = null;
    }

    public function cancelSale(): void
    {
        if ($this->sale === null) {
            $this->resetSale();

            return;
        }
        if ($this->submit('cancel', "pos/sales/{$this->sale['id']}/cancel", ['reason' => $this->cancelReason ?: 'Cancelled at the till'], "Sale {$this->sale['number']} cancelled.") !== null) {
            $this->resetSale();
            $this->dialog = null;
            $this->cancelReason = '';
            $this->refreshShift();
        }
    }

    public function newSale(): void
    {
        if ($this->sale === null) {
            $this->resetSale();
            $this->completed = null;
        }
    }

    private function resetSale(): void
    {
        $this->sale = null;
        $this->lines = [];
        $this->customer = null;
        $this->providerInstructions = null;
        $this->tendered = $this->approvalCode = '';
        $this->payMethod = 'cash';
    }

    private function editable(): bool
    {
        if ($this->shift === null) {
            return false;
        }
        if ($this->sale !== null && ! in_array($this->sale['status'], ['open', 'held'], true)) {
            $this->notify('Payment has started: cancel the sale to change it.', 'warning');

            return false;
        }

        return true;
    }

    public function outstanding(): string
    {
        if ($this->sale === null) {
            return '0';
        }
        $committed = collect($this->sale['payments'] ?? [])->whereIn('status', ['confirmed', 'pending'])->reduce(fn ($s, $p) => bcadd($s, (string) $p['amount'], 4), '0');

        // A just-started sale has no total until its lines are priced.
        return bcsub((string) ($this->sale['total'] ?? '0'), $committed, 4);
    }

    private function min(string $a, string $b): string
    {
        return bccomp($a, $b, 4) <= 0 ? $a : $b;
    }

    public function render()
    {
        $held = $recent = [];
        if ($this->shift !== null && in_array($this->dialog, ['held', 'sales'], true)) {
            $rows = $this->read('pos/sales', ['pos_shift_id' => $this->shift['id']], ['data' => []])['data'];
            $held = collect($rows)->where('status', 'held')->values()->all();
            $recent = collect($rows)->whereIn('status', ['completed', 'cancelled'])->take(30)->values()->all();
        }
        $customers = $this->dialog === 'customer' && mb_strlen(trim($this->customerSearch)) >= 2
            ? $this->read('customers', ['q' => trim($this->customerSearch), 'per_page' => 15], ['data' => []])['data'] : [];

        return view('livewire.pos.terminal', [
            'brand' => app(BrandingService::class)->forOrganization(app(TenantContext::class)->organization()),
            'terminal' => collect($this->terminals)->firstWhere('id', $this->shift['pos_terminal_id'] ?? $this->terminalId),
            'outstanding' => $this->outstanding(),
            'held' => $held,
            'recent' => $recent,
            'customers' => $customers,
            'canCash' => in_array('pos.cash.manage', $this->permissions, true),
            'canDiscount' => in_array('sales.price.override', $this->permissions, true) || in_array('sales.approve', $this->permissions, true),
            'canDispense' => in_array('pos.dispense.prescription', $this->permissions, true),
        ]);
    }
}

<?php

namespace App\Livewire\Pos;

use App\Domain\Catalogue\Models\Product;
use App\Domain\Pos\Models\PosSale;
use App\Domain\Pos\Models\PosShift;
use App\Domain\Pos\Models\PosTerminal;
use App\Domain\Pos\Services\PosCatalogue;
use App\Domain\Pos\Services\PosSaleService;
use App\Domain\Pos\Services\PosSessionService;
use App\Support\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Cashier register. A thin client over PosSaleService / PosSessionService:
 * every action re-authorizes, and prices, totals, stock and payment state
 * always come back from the server. Optimized for keyboard and barcode
 * scanners (a scan is a code followed by Enter in the search box).
 */
#[Layout('layouts.app')]
#[Title('POS register')]
class Register extends Component
{
    public ?string $terminalId = null;

    public string $openingFloat = '0';

    public ?string $saleId = null;

    public string $search = '';

    /** @var list<array{product_id: string, name: string, package_level_code: ?string, quantity: string, batch_id: ?string, prescription: ?array}> */
    public array $cart = [];

    public string $tendered = '';

    public string $cardApprovalCode = '';

    public string $countedCash = '';

    public ?string $completedSaleId = null;

    public function mount(): void
    {
        Gate::authorize('pos.sell');
        $shift = $this->shift();
        $this->terminalId = $shift?->pos_terminal_id;
    }

    public function openShift(PosSessionService $sessions): void
    {
        $this->run(function () use ($sessions) {
            $this->validate(['terminalId' => ['required', 'ulid'], 'openingFloat' => ['required', 'numeric', 'min:0']]);
            $sessions->openShift(PosTerminal::query()->findOrFail($this->terminalId), auth()->user(), (string) $this->openingFloat);
        });
    }

    public function scan(PosCatalogue $catalogue): void
    {
        $this->run(function () use ($catalogue) {
            $q = trim($this->search);
            if (mb_strlen($q) < 2 || ! $this->shift()) {
                return;
            }
            $results = $catalogue->lookup(PosTerminal::query()->findOrFail($this->shift()->pos_terminal_id), $q, 5);
            $exact = $results->first(fn ($r) => $r->exact_code || $r->scanned_batch_id);
            if ($exact !== null) {
                $this->add($exact->product_id, $exact->scanned_batch_id);
                $this->search = '';
            }
        });
    }

    public function add(string $productId, ?string $batchId = null): void
    {
        $this->run(function () use ($productId, $batchId) {
            foreach ($this->cart as $i => $line) {
                if ($line['product_id'] === $productId && $line['batch_id'] === $batchId) {
                    $this->cart[$i]['quantity'] = bcadd($line['quantity'], '1', 4);
                    $this->sync();

                    return;
                }
            }
            $this->cart[] = ['product_id' => $productId, 'name' => (string) Product::query()->whereKey($productId)->value('display_name'),
                'package_level_code' => null, 'quantity' => '1', 'batch_id' => $batchId, 'prescription' => null];
            $this->sync();
        });
    }

    public function setQuantity(int $index, string $quantity): void
    {
        $this->run(function () use ($index, $quantity) {
            if (! isset($this->cart[$index]) || ! is_numeric($quantity) || bccomp($quantity, '0', 4) <= 0) {
                throw new BusinessRuleViolation('quantity_invalid', 'Enter a quantity above zero.');
            }
            $this->cart[$index]['quantity'] = $quantity;
            $this->sync();
        });
    }

    public function setPrescription(int $index, string $reference, string $prescriber, string $date): void
    {
        $this->run(function () use ($index, $reference, $prescriber, $date) {
            $this->cart[$index]['prescription'] = ['reference' => $reference, 'prescriber_name' => $prescriber, 'prescribed_on' => $date];
            $this->sync();
        });
    }

    public function remove(int $index): void
    {
        $this->run(function () use ($index) {
            unset($this->cart[$index]);
            $this->cart = array_values($this->cart);
            $this->sync();
        });
    }

    public function hold(PosSaleService $sales): void
    {
        $this->run(function () use ($sales) {
            $sales->hold($this->sale());
            $this->reset('saleId', 'cart', 'tendered', 'cardApprovalCode');
        });
    }

    public function resume(string $saleId): void
    {
        $this->run(function () use ($saleId) {
            $sale = $this->ownSale($saleId);
            $this->saleId = $sale->id;
            $this->cart = $sale->lines()->get()->map(fn ($l) => [
                'product_id' => $l->product_id, 'name' => (string) Product::query()->whereKey($l->product_id)->value('display_name'),
                'package_level_code' => $l->package_level_code, 'quantity' => (string) $l->quantity, 'batch_id' => $l->requested_batch_id, 'prescription' => $l->prescription,
            ])->all();
        });
    }

    public function checkout(PosSaleService $sales): void
    {
        $this->run(fn () => $sales->checkout($this->sale()));
    }

    public function payCash(PosSaleService $sales): void
    {
        $this->run(function () use ($sales) {
            $sale = $this->sale();
            $outstanding = $this->outstanding($sale);
            $tendered = $this->tendered === '' ? $outstanding : (string) $this->tendered;
            $this->finish($sales->addPayment($sale, 'cash', $outstanding, 'cash', $tendered)['sale']);
        });
    }

    public function payCard(PosSaleService $sales): void
    {
        $this->run(function () use ($sales) {
            $sale = $this->sale();
            $this->finish($sales->addPayment($sale, 'card', $this->outstanding($sale), 'manual_terminal', null, $this->cardApprovalCode)['sale']);
            $this->cardApprovalCode = '';
        });
    }

    public function cancel(PosSaleService $sales): void
    {
        $this->run(function () use ($sales) {
            $sale = $this->sale();
            if ($sale->payments()->whereIn('status', ['confirmed', 'pending'])->exists()) {
                Gate::authorize('pos.sale.cancel');
            }
            $sales->cancel($sale, 'Cancelled at register');
            $this->reset('saleId', 'cart', 'tendered', 'cardApprovalCode');
        });
    }

    public function newSale(): void
    {
        $this->reset('saleId', 'cart', 'tendered', 'cardApprovalCode', 'completedSaleId', 'search');
    }

    public function closeShift(PosSessionService $sessions): void
    {
        $this->run(function () use ($sessions) {
            $this->validate(['countedCash' => ['required', 'numeric', 'min:0']]);
            $shift = $sessions->closeShift($this->shift(), (string) $this->countedCash);
            session()->flash('status', "Shift {$shift->number} closed. Expected {$shift->expected_cash}, counted {$shift->counted_cash}, variance {$shift->variance}.");
            $this->reset();
        });
    }

    public function render(PosCatalogue $catalogue, PosSessionService $sessions)
    {
        $shift = $this->shift();
        $sale = $this->saleId ? PosSale::query()->with('lines', 'payments')->find($this->saleId) : null;

        return view('livewire.pos.register', [
            'shift' => $shift,
            'drawer' => $shift ? $sessions->drawer($shift) : null,
            'terminals' => PosTerminal::query()->where('status', 'active')->orderBy('code')->get(),
            'results' => $shift && mb_strlen(trim($this->search)) >= 2 ? $catalogue->lookup(PosTerminal::query()->findOrFail($shift->pos_terminal_id), $this->search, 10) : collect(),
            'sale' => $sale,
            'outstanding' => $sale ? $this->outstanding($sale) : null,
            'held' => $shift ? PosSale::query()->where('pos_shift_id', $shift->id)->where('status', 'held')->latest()->get(['id', 'number', 'total']) : collect(),
            'completed' => $this->completedSaleId ? PosSale::query()->find($this->completedSaleId) : null,
        ]);
    }

    /** Push the cart to the server; it returns the authoritative priced lines. */
    private function sync(): void
    {
        $sales = app(PosSaleService::class);
        if ($this->saleId === null) {
            $this->saleId = $sales->start($this->shift() ?? throw new BusinessRuleViolation('shift_not_open', 'Open a shift first.', 409), auth()->user(), strtolower((string) Str::ulid()))->id;
        }
        $sale = $sales->setLines($this->sale(), array_map(fn ($l) => array_filter([
            'product_id' => $l['product_id'], 'quantity' => $l['quantity'], 'package_level_code' => $l['package_level_code'],
            'batch_id' => $l['batch_id'], 'prescription' => $l['prescription'],
        ], fn ($v) => $v !== null), $this->cart));
        foreach ($sale->lines->values() as $i => $line) {
            $this->cart[$i]['package_level_code'] = $line->package_level_code;
        }
    }

    private function finish(PosSale $sale): void
    {
        if ($sale->status === 'completed') {
            $this->completedSaleId = $sale->id;
            $this->reset('saleId', 'cart', 'tendered');
        }
    }

    private function outstanding(PosSale $sale): string
    {
        return bcsub((string) $sale->total, bcadd((string) $sale->payments()->whereIn('status', ['confirmed', 'pending'])->sum('amount'), '0', 4), 4);
    }

    private function shift(): ?PosShift
    {
        return PosShift::query()->where('cashier_id', auth()->id())->where('status', 'open')->first();
    }

    private function sale(): PosSale
    {
        return $this->ownSale($this->saleId ?? throw new BusinessRuleViolation('no_sale', 'Start a sale first.', 409));
    }

    private function ownSale(string $id): PosSale
    {
        $sale = PosSale::query()->findOrFail($id);
        if ($sale->cashier_id !== auth()->id()) {
            throw new BusinessRuleViolation('pos_sale_not_yours', 'This sale belongs to another cashier.', 403);
        }

        return $sale;
    }

    private function run(callable $action): void
    {
        Gate::authorize('pos.sell');
        $this->resetErrorBag();
        try {
            $action();
        } catch (BusinessRuleViolation $e) {
            $this->addError('register', $e->getMessage());
        }
    }
}

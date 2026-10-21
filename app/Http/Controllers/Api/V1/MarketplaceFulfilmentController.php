<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Marketplace\Models\MarketplaceDispute;
use App\Domain\Marketplace\Models\MarketplaceFee;
use App\Domain\Marketplace\Models\MarketplaceInvoice;
use App\Domain\Marketplace\Models\MarketplaceOrder;
use App\Domain\Marketplace\Models\MarketplaceShipment;
use App\Domain\Marketplace\Services\DisputeService;
use App\Domain\Marketplace\Services\MarketplaceFulfilmentService;
use App\Domain\Marketplace\Services\MarketplaceOrderService;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Shipments, receipts, invoices, payment status, fees, disputes and history (spec §14.2, Phase 4c). */
class MarketplaceFulfilmentController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly MarketplaceOrderService $orders,
    ) {}

    public function ship(Request $request, string $id, MarketplaceFulfilmentService $fulfilment): JsonResponse
    {
        Gate::authorize('marketplace.orders.fulfil');
        $data = $request->validate([
            'carrier' => ['nullable', 'string', 'max:100'],
            'tracking_reference' => ['nullable', 'string', 'max:100'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.order_line_id' => ['required', 'integer', 'distinct'],
            'lines.*.quantity' => ['required', 'decimal:0,4', 'gt:0'],
        ]);

        return response()->json(['data' => $fulfilment->ship($this->order($id), $data['lines'], $data['carrier'] ?? null, $data['tracking_reference'] ?? null)], 201);
    }

    public function shipments(string $id): JsonResponse
    {
        $this->authorizeOrderAccess();

        return response()->json(['data' => MarketplaceShipment::query()->with('lines')->where('marketplace_order_id', $this->order($id)->id)->orderBy('shipped_at')->get()]);
    }

    public function receive(Request $request, string $id, MarketplaceFulfilmentService $fulfilment): JsonResponse
    {
        Gate::authorize('marketplace.orders.place');
        $shipment = MarketplaceShipment::query()->findOrFail($id);
        $this->order($shipment->marketplace_order_id);
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:2000'], 'lines' => ['sometimes', 'array'],
            'lines.*.shipment_line_id' => ['required', 'integer'], 'lines.*.quantity' => ['required', 'decimal:0,4', 'min:0']]);

        return response()->json(['data' => $fulfilment->receive($shipment, collect($data['lines'] ?? [])->pluck('quantity', 'shipment_line_id')->all(), $data['notes'] ?? null)]);
    }

    public function invoice(string $id, MarketplaceFulfilmentService $fulfilment): JsonResponse
    {
        Gate::authorize('marketplace.orders.fulfil');

        return response()->json(['data' => $fulfilment->invoice($this->order($id))], 201);
    }

    public function invoices(string $id, MarketplaceFulfilmentService $fulfilment): JsonResponse
    {
        $this->authorizeOrderAccess();

        return response()->json(['data' => $fulfilment->invoices($this->order($id))]);
    }

    public function recordSupplierInvoice(string $id, MarketplaceFulfilmentService $fulfilment): JsonResponse
    {
        Gate::authorize('marketplace.orders.place');
        Gate::authorize('procurement.invoice');
        $invoice = MarketplaceInvoice::query()->findOrFail($id);
        $this->order($invoice->marketplace_order_id);

        return response()->json(['data' => $fulfilment->recordSupplierInvoice($invoice)]);
    }

    /** Everything that happened to the order, from both sides' shared record, in time order. */
    public function history(string $id): JsonResponse
    {
        $this->authorizeOrderAccess();
        $order = $this->order($id);
        $events = collect([['at' => $order->created_at, 'event' => 'created']])
            ->when($order->submitted_at, fn ($c) => $c->push(['at' => $order->submitted_at, 'event' => 'submitted_to_supplier']))
            ->when($order->decided_at, fn ($c) => $c->push(['at' => $order->decided_at, 'event' => in_array($order->status, ['rejected'], true) ? 'rejected' : 'accepted', 'notes' => $order->decision_notes]))
            ->merge(MarketplaceShipment::query()->where('marketplace_order_id', $order->id)->get()->flatMap(fn ($s) => array_filter([
                ['at' => $s->shipped_at, 'event' => 'shipped', 'reference' => $s->number, 'tracking' => $s->tracking_reference],
                $s->received_at ? ['at' => $s->received_at, 'event' => $s->status === 'received_short' ? 'received_short' : 'received', 'reference' => $s->number] : null,
            ])))
            ->merge(MarketplaceInvoice::query()->where('marketplace_order_id', $order->id)->get()->map(fn ($i) => ['at' => $i->created_at, 'event' => 'invoiced', 'reference' => $i->number, 'total' => (string) $i->total]))
            ->merge(MarketplaceDispute::query()->where('marketplace_order_id', $order->id)->get()->flatMap(fn ($d) => array_filter([
                ['at' => $d->created_at, 'event' => 'dispute_raised', 'reference' => $d->number, 'by' => $d->raised_by_side],
                $d->escalated_at ? ['at' => $d->escalated_at, 'event' => 'dispute_escalated', 'reference' => $d->number] : null,
                $d->resolved_at ? ['at' => $d->resolved_at, 'event' => 'dispute_resolved', 'reference' => $d->number, 'resolution' => $d->resolution] : null,
            ])))
            ->when($order->status === 'cancelled', fn ($c) => $c->push(['at' => $order->updated_at, 'event' => 'cancelled', 'notes' => $order->cancel_reason]))
            ->sortBy('at')->values()->map(fn ($e) => ['at' => $e['at']?->toIso8601String()] + $e);

        return response()->json(['data' => ['order' => $order->number, 'status' => $order->status, 'events' => $events]]);
    }

    /** The supplier's own fee statement. */
    public function fees(Request $request): JsonResponse
    {
        Gate::authorize('marketplace.orders.fulfil');
        $d = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $query = MarketplaceFee::query()->where('supplier_organization_id', $this->tenant->id())
            ->when($d['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v.' 00:00:00'))
            ->when($d['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', $v.' 23:59:59'));

        return response()->json(['data' => (clone $query)->latest()->get(), 'meta' => [
            'accrued_total' => bcadd((string) (clone $query)->where('status', 'accrued')->sum('amount'), '0', 4),
            'waived_total' => bcadd((string) (clone $query)->where('status', 'waived')->sum('amount'), '0', 4),
            'rate_percent' => (string) config('marketplace.fee_percent'),
        ]]);
    }

    // ---- Disputes ---------------------------------------------------------------------

    public function disputes(Request $request): JsonResponse
    {
        Gate::authorize('marketplace.disputes.manage');

        return response()->json(MarketplaceDispute::query()->involving($this->tenant->id())->with('messages')
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))->latest()->paginate(50));
    }

    public function raiseDispute(Request $request, string $id, DisputeService $disputes): JsonResponse
    {
        Gate::authorize('marketplace.disputes.manage');
        $data = $request->validate([
            'type' => ['required', Rule::in(MarketplaceDispute::TYPES)],
            'requested_resolution' => ['required', Rule::in(MarketplaceDispute::REQUESTS)],
            'description' => ['required', 'string', 'max:5000'],
            'lines' => ['nullable', 'array'],
            'lines.*.marketplace_order_line_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'decimal:0,4', 'gt:0'],
            'lines.*.batch_number' => ['nullable', 'string', 'max:64'],
        ]);

        return response()->json(['data' => $disputes->raise($this->order($id), $data)], 201);
    }

    public function dispute(string $id): JsonResponse
    {
        Gate::authorize('marketplace.disputes.manage');

        return response()->json(['data' => MarketplaceDispute::query()->involving($this->tenant->id())->with('messages')->findOrFail($id)]);
    }

    public function disputeAction(Request $request, string $id, string $action, DisputeService $disputes): JsonResponse
    {
        Gate::authorize('marketplace.disputes.manage');
        $dispute = MarketplaceDispute::query()->involving($this->tenant->id())->findOrFail($id);
        $side = $this->orders->side(MarketplaceOrder::query()->findOrFail($dispute->marketplace_order_id));

        $result = match ($action) {
            'messages' => $disputes->message($dispute, $side, $request->validate(['body' => ['required', 'string', 'max:5000']])['body']),
            'escalate' => $disputes->escalate($dispute, $side, $request->validate(['reason' => ['required', 'string', 'max:2000']])['reason']),
            'resolve' => (function () use ($request, $dispute, $disputes) {
                $d = $request->validate([
                    'resolution' => ['required', Rule::in(['credit_note', 'replacement', 'rejected', 'other'])],
                    'notes' => ['required', 'string', 'max:5000'],
                    'goods_returned' => ['sometimes', 'boolean'],
                    'credit_lines' => ['required_if:resolution,credit_note', 'array'],
                    'credit_lines.*.marketplace_order_line_id' => ['required', 'integer'],
                    'credit_lines.*.quantity' => ['required', 'decimal:0,4', 'gt:0'],
                    'credit_lines.*.batch_number' => ['nullable', 'string', 'max:64'],
                ]);

                return $disputes->resolve($dispute, $d['resolution'], $d['notes'], $d['credit_lines'] ?? [], (bool) ($d['goods_returned'] ?? false));
            })(),
            'return' => (function () use ($request, $dispute, $disputes) {
                Gate::authorize('procurement.return');
                $d = $request->validate(['lines' => ['required', 'array', 'min:1'], 'lines.*.marketplace_order_line_id' => ['required', 'integer'],
                    'lines.*.quantity' => ['required', 'decimal:0,4', 'gt:0'], 'lines.*.batch_number' => ['required', 'string', 'max:64']]);

                return $disputes->returnGoods($dispute, $d['lines']);
            })(),
        };

        return response()->json(['data' => $result]);
    }

    private function order(string $id): MarketplaceOrder
    {
        return MarketplaceOrder::query()->involving($this->tenant->id())->findOrFail($id);
    }

    private function authorizeOrderAccess(): void
    {
        abort_unless(Gate::allows('marketplace.orders.place') || Gate::allows('marketplace.orders.fulfil') || Gate::allows('marketplace.orders.approve'), 403);
    }
}

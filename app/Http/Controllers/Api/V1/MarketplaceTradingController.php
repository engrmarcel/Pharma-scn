<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Marketplace\Models\MarketplaceOrder;
use App\Domain\Marketplace\Models\MarketplaceQuote;
use App\Domain\Marketplace\Models\Rfq;
use App\Domain\Marketplace\Services\MarketplaceOrderService;
use App\Domain\Marketplace\Services\RfqService;
use App\Domain\Organizations\Models\Organization;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** RFQs, quotations and B2B orders (spec §14.2, Phase 4b). */
class MarketplaceTradingController extends Controller
{
    public function __construct(private readonly TenantContext $tenant) {}

    // ---- RFQs ----------------------------------------------------------------------

    public function rfqs(Request $request): JsonResponse
    {
        abort_unless(Gate::allows('marketplace.rfq.manage') || Gate::allows('marketplace.quotes.respond'), 403);
        $as = $request->validate(['as' => ['nullable', Rule::in(['buyer', 'supplier'])]])['as'] ?? null;
        $org = $this->tenant->id();

        return response()->json(Rfq::query()->visibleTo($org)->with('lines')
            ->when($as === 'buyer', fn ($q) => $q->where('buyer_organization_id', $org))
            ->when($as === 'supplier', fn ($q) => $q->where('buyer_organization_id', '!=', $org))
            ->latest()->paginate(50)->through(fn (Rfq $r) => $this->presentRfq($r)));
    }

    public function storeRfq(Request $request, RfqService $rfqs): JsonResponse
    {
        Gate::authorize('marketplace.rfq.manage');
        $org = $this->tenant->id();
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'response_deadline' => ['required', 'date', 'after:now'],
            'delivery_warehouse_id' => ['required', 'ulid', Rule::exists('warehouses', 'id')->where('organization_id', $org)],
            'notes' => ['nullable', 'string', 'max:5000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.product_id' => ['required', 'ulid', 'exists:products,id'],
            'lines.*.package_level_code' => ['required', 'string', 'max:32'],
            'lines.*.quantity' => ['required', 'decimal:0,4', 'gt:0'],
            'lines.*.notes' => ['nullable', 'string', 'max:255'],
            'supplier_organization_ids' => ['required', 'array', 'min:1', 'max:50'],
            'supplier_organization_ids.*' => ['ulid', 'distinct', 'exists:organizations,id', Rule::notIn([$org])],
        ]);

        return response()->json(['data' => $this->presentRfq($rfqs->create($data))], 201);
    }

    public function rfq(string $id): JsonResponse
    {
        abort_unless(Gate::allows('marketplace.rfq.manage') || Gate::allows('marketplace.quotes.respond'), 403);

        return response()->json(['data' => $this->presentRfq(Rfq::query()->visibleTo($this->tenant->id())->findOrFail($id))]);
    }

    public function rfqAction(Request $request, string $id, string $action, RfqService $rfqs): JsonResponse
    {
        Gate::authorize('marketplace.rfq.manage');
        $reason = $request->validate(['reason' => [$action === 'cancel' ? 'required' : 'nullable', 'string', 'max:2000']])['reason'] ?? null;
        $rfq = Rfq::query()->where('buyer_organization_id', $this->tenant->id())->findOrFail($id);

        return response()->json(['data' => $this->presentRfq($rfqs->transition($rfq, ['open' => 'open', 'close' => 'closed', 'cancel' => 'cancelled'][$action], $reason))]);
    }

    public function comparison(string $id, RfqService $rfqs): JsonResponse
    {
        Gate::authorize('marketplace.rfq.manage');

        return response()->json(['data' => $rfqs->comparison(Rfq::query()->where('buyer_organization_id', $this->tenant->id())->findOrFail($id))]);
    }

    public function quoteRfq(Request $request, string $id, RfqService $rfqs): JsonResponse
    {
        Gate::authorize('marketplace.quotes.respond');
        $rfq = Rfq::query()->visibleTo($this->tenant->id())->findOrFail($id);
        $data = $request->validate($this->quoteRules() + [
            'lines.*.rfq_line_id' => ['required', 'integer'],
        ]);

        return response()->json(['data' => $rfqs->submitQuote($rfq, null, $data)], 201);
    }

    public function declineRfq(Request $request, string $id, RfqService $rfqs): JsonResponse
    {
        Gate::authorize('marketplace.quotes.respond');
        $rfq = Rfq::query()->visibleTo($this->tenant->id())->findOrFail($id);

        return response()->json(['data' => $rfqs->declineInvitation($rfq, $request->validate(['reason' => ['required', 'string', 'max:2000']])['reason'])]);
    }

    // ---- Quotations -------------------------------------------------------------------

    public function quotes(Request $request): JsonResponse
    {
        abort_unless(Gate::allows('marketplace.rfq.manage') || Gate::allows('marketplace.quotes.respond') || Gate::allows('marketplace.orders.place'), 403);
        $f = $request->validate(['rfq_id' => ['nullable', 'ulid'], 'status' => ['nullable', 'string', 'max:16']]);

        return response()->json(MarketplaceQuote::query()->involving($this->tenant->id())->with('lines')
            ->when($f['rfq_id'] ?? null, fn ($q, $v) => $q->where('rfq_id', $v))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->latest()->paginate(50));
    }

    /** A quotation sent directly to a buyer the supplier already trades with. */
    public function storeQuote(Request $request, RfqService $rfqs): JsonResponse
    {
        Gate::authorize('marketplace.quotes.respond');
        $data = $request->validate($this->quoteRules() + [
            'buyer_organization_id' => ['required', 'ulid', 'exists:organizations,id'],
            'lines.*.product_id' => ['required', 'ulid', Rule::exists('organization_products', 'product_id')->where('organization_id', $this->tenant->id())],
            'lines.*.package_level_code' => ['required', 'string', 'max:32'],
        ]);

        return response()->json(['data' => $rfqs->submitQuote(null, Organization::query()->findOrFail($data['buyer_organization_id']), $data)], 201);
    }

    public function quoteAction(Request $request, string $id, string $action, RfqService $rfqs, MarketplaceOrderService $orders): JsonResponse
    {
        $quote = MarketplaceQuote::query()->involving($this->tenant->id())->findOrFail($id);

        return match ($action) {
            'accept' => $this->authorized('marketplace.orders.place', fn () => response()->json(['data' => $orders->present($rfqs->accept($quote,
                $request->validate(['delivery_warehouse_id' => ['nullable', 'ulid', Rule::exists('warehouses', 'id')->where('organization_id', $this->tenant->id())]])['delivery_warehouse_id'] ?? null))], 201)),
            'reject' => $this->authorized('marketplace.rfq.manage', fn () => response()->json(['data' => $rfqs->reject($quote, $request->validate(['notes' => ['required', 'string', 'max:2000']])['notes'])])),
            'withdraw' => $this->authorized('marketplace.quotes.respond', fn () => response()->json(['data' => $rfqs->withdraw($quote)])),
        };
    }

    // ---- Orders ------------------------------------------------------------------------

    public function orders(Request $request, MarketplaceOrderService $orders): JsonResponse
    {
        abort_unless(Gate::allows('marketplace.orders.place') || Gate::allows('marketplace.orders.fulfil') || Gate::allows('marketplace.orders.approve'), 403);
        $f = $request->validate(['as' => ['nullable', Rule::in(['buyer', 'supplier'])], 'status' => ['nullable', 'string', 'max:24']]);
        $org = $this->tenant->id();

        return response()->json(MarketplaceOrder::query()->involving($org)
            ->when(($f['as'] ?? null) === 'buyer', fn ($q) => $q->where('buyer_organization_id', $org))
            ->when(($f['as'] ?? null) === 'supplier', fn ($q) => $q->where('supplier_organization_id', $org))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->latest()->paginate(50)->through(fn (MarketplaceOrder $o) => $orders->present($o)));
    }

    public function order(string $id, MarketplaceOrderService $orders): JsonResponse
    {
        abort_unless(Gate::allows('marketplace.orders.place') || Gate::allows('marketplace.orders.fulfil') || Gate::allows('marketplace.orders.approve'), 403);

        return response()->json(['data' => $orders->present(MarketplaceOrder::query()->involving($this->tenant->id())->findOrFail($id))]);
    }

    public function storeOrder(Request $request, MarketplaceOrderService $orders): JsonResponse
    {
        Gate::authorize('marketplace.orders.place');
        $data = $request->validate([
            'supplier_organization_id' => ['required', 'ulid', 'exists:organizations,id'],
            'delivery_warehouse_id' => ['required', 'ulid', Rule::exists('warehouses', 'id')->where('organization_id', $this->tenant->id())],
            'requested_date' => ['nullable', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.listing_id' => ['required', 'ulid', 'distinct'],
            'lines.*.quantity' => ['required', 'decimal:0,4', 'gt:0'],
        ]);
        $order = $orders->createFromListings(Organization::query()->findOrFail($data['supplier_organization_id']), $data['lines'], $data['delivery_warehouse_id'], $data['requested_date'] ?? null, $data['notes'] ?? null);

        return response()->json(['data' => $orders->present($order)], 201);
    }

    public function orderAction(Request $request, string $id, string $action, MarketplaceOrderService $orders): JsonResponse
    {
        $order = MarketplaceOrder::query()->involving($this->tenant->id())->findOrFail($id);
        $reason = fn (bool $required = true) => $request->validate(['reason' => [$required ? 'required' : 'nullable', 'string', 'max:2000']])['reason'] ?? null;

        $result = match ($action) {
            'submit' => $this->authorized('marketplace.orders.place', fn () => $orders->submit($order, $request->user())),
            'approve' => $this->authorized('marketplace.orders.approve', fn () => $orders->decideInternally($order, true, $request->user(), null)),
            'disapprove' => $this->authorized('marketplace.orders.approve', fn () => $orders->decideInternally($order, false, $request->user(), $reason())),
            'accept' => $this->authorized('marketplace.orders.fulfil', fn () => $orders->accept($order,
                $request->validate(['warehouse_id' => ['nullable', 'ulid', Rule::exists('warehouses', 'id')->where('organization_id', $this->tenant->id())]])['warehouse_id'] ?? null, $reason(false))),
            'reject' => $this->authorized('marketplace.orders.fulfil', fn () => $orders->reject($order, $reason())),
            'cancel' => $this->authorized($orders->side($order) === 'buyer' ? 'marketplace.orders.place' : 'marketplace.orders.fulfil', fn () => $orders->cancel($order, $reason())),
        };

        return response()->json(['data' => $orders->present($result->fresh())]);
    }

    private function presentRfq(Rfq $rfq): array
    {
        $rfq->loadMissing(['lines', 'invitations.supplier:id,name']);
        $isBuyer = $rfq->buyer_organization_id === $this->tenant->id();

        return $rfq->only(['id', 'number', 'title', 'status', 'response_deadline', 'currency_code', 'notes', 'opened_at', 'closed_at']) + [
            'your_side' => $isBuyer ? 'buyer' : 'supplier',
            'lines' => $rfq->lines->map(fn ($l) => $l->only(['id', 'product_id', 'package_level_code', 'quantity', 'notes']))->values(),
            // Suppliers do not see who else was invited.
            'invitations' => $rfq->invitations->filter(fn ($i) => $isBuyer || $i->supplier_organization_id === $this->tenant->id())
                ->map(fn ($i) => ['supplier' => $i->supplier?->only(['id', 'name']), 'status' => $i->status, 'decline_reason' => $i->decline_reason, 'responded_at' => $i->responded_at])->values(),
        ];
    }

    private function quoteRules(): array
    {
        return [
            'valid_until' => ['required', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.quantity' => ['required', 'decimal:0,4', 'gt:0'],
            'lines.*.unit_price' => ['required', 'decimal:0,4', 'min:0'],
            'lines.*.lead_time_days' => ['nullable', 'integer', 'between:0,365'],
        ];
    }

    private function authorized(string $ability, callable $action): mixed
    {
        Gate::authorize($ability);

        return $action();
    }
}

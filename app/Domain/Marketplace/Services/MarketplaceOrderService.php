<?php

namespace App\Domain\Marketplace\Services;

use App\Domain\Catalogue\Models\OrganizationProduct;
use App\Domain\Catalogue\Models\Product;
use App\Domain\Inventory\Services\DocumentNumberer;
use App\Domain\Marketplace\Models\MarketplaceListing;
use App\Domain\Marketplace\Models\MarketplaceOrder;
use App\Domain\Marketplace\Models\MarketplaceQuote;
use App\Domain\Marketplace\Models\TradingRelationship;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\Warehouse;
use App\Domain\Parties\Models\Customer;
use App\Domain\Parties\Models\Supplier;
use App\Domain\Platform\Services\AuditLogger;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Services\PurchaseOrderService;
use App\Domain\Sales\Models\SalesOrder;
use App\Domain\Sales\Services\SalesOrderService;
use App\Models\User;
use App\Support\Exceptions\BusinessRuleViolation;
use App\Support\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * B2B orders connected to both ERPs (spec §14.2; ADR-0012).
 *
 *   draft ──submit──▶ pending_approval ──approve──▶ submitted ──accept──▶ accepted
 *     │                  └─reject─▶ draft             ├─reject─▶ rejected
 *     └──────────── cancel (buyer, before acceptance) ┴─ cancel (supplier, before shipment)
 *
 * Buyer side: submitting creates the buyer's purchase order; the buyer's own
 * procurement approval rules (threshold, segregation of duties) are the
 * order's approval workflow. Supplier side: accepting creates the supplier's
 * sales order at the agreed prices and confirms it, reserving stock FEFO.
 * Each side creates its own ERP documents. The only cross-tenant writes are
 * consequences the counterpart cannot refuse: a rejection or a cancellation
 * by one side cancels the other side's open document, recorded in its audit
 * trail as done through the marketplace.
 */
class MarketplaceOrderService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly MarketplaceAccess $access,
        private readonly RelationshipService $relationships,
        private readonly PurchaseOrderService $purchaseOrders,
        private readonly SalesOrderService $salesOrders,
        private readonly DocumentNumberer $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /** @param list<array{listing_id: string, quantity: string}> $lines */
    public function createFromListings(Organization $supplier, array $lines, string $deliveryWarehouseId, ?string $requestedDate, ?string $notes): MarketplaceOrder
    {
        $buyer = $this->tenant->organization();
        $prepared = [];
        foreach ($lines as $i => $line) {
            $listing = MarketplaceListing::acrossTenants()->where('organization_id', $supplier->id)->where('status', 'active')->find($line['listing_id'])
                ?? throw new BusinessRuleViolation('listing_unavailable', 'That listing is not available from this supplier.', 422, ['line' => $i]);
            if (bccomp((string) $line['quantity'], (string) $listing->minimum_order_quantity, 4) < 0) {
                throw new BusinessRuleViolation('below_minimum_order_quantity', "The minimum order is {$listing->minimum_order_quantity}.", 422, ['line' => $i]);
            }
            $this->access->assertMayObtain($buyer, $supplier, $listing->product_id, $listing->allowed_buyer_types);
            $prepared[] = ['marketplace_listing_id' => $listing->id, 'product_id' => $listing->product_id, 'package_level_code' => $listing->package_level_code,
                'quantity' => (string) $line['quantity'], 'unit_price' => (string) $listing->unit_price, 'currency_code' => $listing->currency_code];
        }

        return $this->create($supplier, $prepared, $deliveryWarehouseId, $requestedDate, $notes, null);
    }

    public function createFromQuote(MarketplaceQuote $quote, string $deliveryWarehouseId): MarketplaceOrder
    {
        $supplier = Organization::query()->findOrFail($quote->supplier_organization_id);
        $lines = $quote->lines()->get()->map(fn ($l) => ['marketplace_quote_line_id' => $l->id, 'product_id' => $l->product_id, 'package_level_code' => $l->package_level_code,
            'quantity' => (string) $l->quantity, 'unit_price' => (string) $l->unit_price, 'currency_code' => $quote->currency_code])->all();
        foreach ($lines as $line) {
            $this->access->assertMayObtain($this->tenant->organization(), $supplier, $line['product_id']);
        }

        return $this->create($supplier, $lines, $deliveryWarehouseId, null, "From quotation {$quote->number}", $quote->id);
    }

    private function create(Organization $supplier, array $lines, string $deliveryWarehouseId, ?string $requestedDate, ?string $notes, ?string $quoteId): MarketplaceOrder
    {
        $buyer = $this->tenant->organization();
        $this->access->assertParticipates($buyer);
        $this->access->assertParticipates($supplier, 'The supplier');
        $relationship = $this->relationships->approved($buyer->id, $supplier->id);
        $currency = $buyer->country->currency_code;
        if (collect($lines)->pluck('currency_code')->unique()->diff([$currency])->isNotEmpty()) {
            throw new BusinessRuleViolation('currency_mismatch', "Marketplace orders must be in {$currency}.", 422);
        }
        Warehouse::query()->findOrFail($deliveryWarehouseId);

        return DB::transaction(function () use ($buyer, $supplier, $lines, $deliveryWarehouseId, $requestedDate, $notes, $quoteId, $relationship, $currency) {
            $subtotal = '0';
            $order = MarketplaceOrder::query()->create([
                'number' => $this->numbers->next('MPO'), 'buyer_organization_id' => $buyer->id, 'supplier_organization_id' => $supplier->id,
                'trading_relationship_id' => $relationship->id, 'marketplace_quote_id' => $quoteId, 'currency_code' => $currency,
                'delivery_warehouse_id' => $deliveryWarehouseId, 'requested_date' => $requestedDate, 'notes' => $notes, 'created_by' => Auth::id(),
            ]);
            foreach ($lines as $line) {
                $total = Money::mul($line['quantity'], $line['unit_price']);
                $order->lines()->create(array_diff_key($line, ['currency_code' => 1]) + ['line_total' => $total]);
                $subtotal = Money::add($subtotal, $total);
                $this->ensureCatalogued($line['product_id'], $currency);
            }
            $order->forceFill(['subtotal' => $subtotal])->save();
            $this->audit->record('marketplace_order.created', null, new: ['order_id' => $order->id, 'number' => $order->number, 'supplier' => $supplier->name, 'subtotal' => $subtotal]);

            return $order->load('lines');
        });
    }

    /** Buyer: raise the purchase order; it goes to the supplier once the buyer's approval rules are met. */
    public function submit(MarketplaceOrder $order, User $user): MarketplaceOrder
    {
        return DB::transaction(function () use ($order, $user) {
            $locked = $this->lockAs($order, 'buyer', ['draft']);
            $relationship = $this->relationships->approved($locked->buyer_organization_id, $locked->supplier_organization_id);

            $po = $locked->buyer_purchase_order_id ? PurchaseOrder::query()->find($locked->buyer_purchase_order_id) : null;
            if ($po === null) {
                $po = $this->purchaseOrders->createOrder(Supplier::query()->findOrFail($relationship->buyer_supplier_id), Warehouse::query()->findOrFail($locked->delivery_warehouse_id),
                    $locked->lines()->get()->map(fn ($l) => ['product_id' => $l->product_id, 'package_level_code' => $l->package_level_code, 'quantity' => (string) $l->quantity, 'unit_price' => (string) $l->unit_price])->all(),
                    $locked->requested_date?->toDateString(), "Marketplace order {$locked->number}");
            } elseif ($po->status === 'rejected') {
                $po = $this->purchaseOrders->updateOrder($po, []);
            }
            $po = $this->purchaseOrders->submitOrder($po, $user);
            $locked->forceFill(['buyer_purchase_order_id' => $po->id])->save();

            return $this->afterBuyerApproval($locked, $po);
        });
    }

    /** Buyer approver: the purchase order's approval decides the marketplace order. */
    public function decideInternally(MarketplaceOrder $order, bool $approve, User $approver, ?string $reason): MarketplaceOrder
    {
        return DB::transaction(function () use ($order, $approve, $approver, $reason) {
            $locked = $this->lockAs($order, 'buyer', ['pending_approval']);
            $po = $this->purchaseOrders->decideOrder(PurchaseOrder::query()->findOrFail($locked->buyer_purchase_order_id), $approve, $approver, $reason);
            if (! $approve) {
                $locked->forceFill(['status' => 'draft', 'decision_notes' => "Not approved internally: {$reason}"])->save();
                $this->audit->record('marketplace_order.not_approved', $po, new: ['order' => $locked->number, 'reason' => $reason]);

                return $locked;
            }

            return $this->afterBuyerApproval($locked, $po);
        });
    }

    private function afterBuyerApproval(MarketplaceOrder $order, PurchaseOrder $po): MarketplaceOrder
    {
        if ($po->status === 'pending_approval') {
            $order->forceFill(['status' => 'pending_approval'])->save();

            return $order;
        }
        $this->purchaseOrders->markSent($po);
        $order->forceFill(['status' => 'submitted', 'submitted_at' => now(), 'decision_notes' => null])->save();
        $this->audit->record('marketplace_order.submitted', $po, new: ['order' => $order->number, 'purchase_order' => $po->number]);

        return $order;
    }

    /** Supplier: accept. The sales order is created at the agreed prices and confirmed (stock reserved FEFO). */
    public function accept(MarketplaceOrder $order, ?string $warehouseId, ?string $notes): MarketplaceOrder
    {
        return DB::transaction(function () use ($order, $warehouseId, $notes) {
            $locked = $this->lockAs($order, 'supplier', ['submitted']);
            $relationship = TradingRelationship::query()->findOrFail($locked->trading_relationship_id);
            if ($relationship->status !== 'approved' || $relationship->supplier_customer_id === null) {
                throw new BusinessRuleViolation('relationship_not_approved', 'The trading relationship is not approved.', 409);
            }
            $buyer = Organization::query()->findOrFail($locked->buyer_organization_id);
            foreach ($locked->lines()->get() as $line) {
                $this->access->assertMayObtain($buyer, $this->tenant->organization(), $line->product_id); // re-checked: verification may have changed
            }

            $warehouse = $warehouseId ? Warehouse::query()->findOrFail($warehouseId)
                : Warehouse::query()->whereIn('id', MarketplaceListing::query()->whereIn('id', $locked->lines()->pluck('marketplace_listing_id')->filter())->pluck('warehouse_id')->filter())->first()
                ?? Warehouse::query()->orderBy('code')->firstOrFail();
            $sales = $this->salesOrders->createOrder(Customer::query()->findOrFail($relationship->supplier_customer_id), $warehouse,
                $locked->lines()->get()->map(fn ($l) => ['product_id' => $l->product_id, 'package_level_code' => $l->package_level_code, 'quantity' => (string) $l->quantity,
                    'unit_price' => (string) $l->unit_price, '_quoted' => true])->all(),
                $locked->requested_date?->toDateString(), "Marketplace order {$locked->number} from {$buyer->name}");
            $sales = $this->salesOrders->confirm($sales);

            $locked->forceFill(['status' => 'accepted', 'supplier_sales_order_id' => $sales->id, 'decided_by' => Auth::id(), 'decided_at' => now(), 'decision_notes' => $notes])->save();
            $this->audit->record('marketplace_order.accepted', $sales, new: ['order' => $locked->number, 'sales_order' => $sales->number, 'sales_order_status' => $sales->status]);

            return $locked;
        });
    }

    public function reject(MarketplaceOrder $order, string $reason): MarketplaceOrder
    {
        return DB::transaction(function () use ($order, $reason) {
            $locked = $this->lockAs($order, 'supplier', ['submitted']);
            $locked->forceFill(['status' => 'rejected', 'decided_by' => Auth::id(), 'decided_at' => now(), 'decision_notes' => $reason])->save();
            $this->cancelBuyerPurchaseOrder($locked, "Rejected by the supplier: {$reason}");
            $this->audit->record('marketplace_order.rejected', null, new: ['order' => $locked->number, 'reason' => $reason]);

            return $locked;
        });
    }

    /** Buyer before acceptance; supplier after acceptance but before anything has shipped. */
    public function cancel(MarketplaceOrder $order, string $reason): MarketplaceOrder
    {
        return DB::transaction(function () use ($order, $reason) {
            $side = $this->side($order);
            $locked = $this->lockAs($order, $side, $side === 'buyer' ? ['draft', 'pending_approval', 'submitted'] : ['accepted']);
            if ($side === 'supplier' && $locked->lines()->where('shipped_quantity', '>', 0)->exists()) {
                throw new BusinessRuleViolation('order_already_shipped', 'Goods have already shipped; resolve the rest through a return or dispute.', 409);
            }

            if ($side === 'buyer' && $locked->buyer_purchase_order_id) {
                $this->purchaseOrders->cancel(PurchaseOrder::query()->findOrFail($locked->buyer_purchase_order_id), "Marketplace order cancelled: {$reason}");
            }
            if ($side === 'supplier') {
                $this->salesOrders->cancel(SalesOrder::query()->findOrFail($locked->supplier_sales_order_id), "Marketplace order cancelled: {$reason}");
                $this->cancelBuyerPurchaseOrder($locked, "Cancelled by the supplier: {$reason}");
            }
            $locked->forceFill(['status' => 'cancelled', 'cancel_reason' => $reason])->save();
            $this->audit->record('marketplace_order.cancelled', null, new: ['order' => $locked->number, 'by' => $side, 'reason' => $reason]);

            return $locked;
        });
    }

    /** What each side may see: the order, its lines, the counterpart, and its own ERP document only. */
    public function present(MarketplaceOrder $order): array
    {
        $order->loadMissing(['lines', 'buyer', 'supplier']);
        $side = $this->side($order);
        $names = Product::query()->whereIn('id', $order->lines->pluck('product_id'))->pluck('display_name', 'id');
        $own = $side === 'buyer'
            ? ($order->buyer_purchase_order_id ? PurchaseOrder::query()->find($order->buyer_purchase_order_id, ['id', 'number', 'status']) : null)
            : ($order->supplier_sales_order_id ? SalesOrder::query()->find($order->supplier_sales_order_id, ['id', 'number', 'status', 'total']) : null);
        $counterpart = $side === 'buyer' ? $order->supplier : $order->buyer;

        return $order->only(['id', 'number', 'status', 'currency_code', 'subtotal', 'requested_date', 'notes', 'submitted_at', 'decided_at', 'decision_notes', 'cancel_reason', 'marketplace_quote_id', 'created_at'])
            + [
                'your_side' => $side,
                'counterpart' => ['id' => $counterpart->id, 'name' => $counterpart->name, 'type' => $counterpart->type->value, 'verification' => MarketplaceAccess::verificationLabel($counterpart)],
                'erp_document' => $own?->toArray(),
                'lines' => $order->lines->map(fn ($l) => $l->only(['id', 'product_id', 'package_level_code', 'quantity', 'unit_price', 'line_total', 'shipped_quantity', 'received_quantity'])
                    + ['product_name' => $names[$l->product_id] ?? null])->values(),
                'note' => 'Subtotal is before tax; the supplier\'s invoice is the amount due.',
            ];
    }

    public function side(MarketplaceOrder $order): string
    {
        return match ($this->tenant->id()) {
            $order->buyer_organization_id => 'buyer',
            $order->supplier_organization_id => 'supplier',
            default => throw new BusinessRuleViolation('order_not_found', 'Order not found.', 404),
        };
    }

    private function lockAs(MarketplaceOrder $order, string $side, array $statuses): MarketplaceOrder
    {
        $locked = MarketplaceOrder::query()->lockForUpdate()->findOrFail($order->id);
        if ($this->side($locked) !== $side) {
            throw new BusinessRuleViolation('order_wrong_side', "Only the {$side} can do this.", 403);
        }
        if (! in_array($locked->status, $statuses, true)) {
            throw new BusinessRuleViolation('marketplace_order_state_invalid', "The order is {$locked->status}.", 409);
        }

        return $locked;
    }

    /** The buyer's open purchase order follows a supplier rejection or cancellation (marked as done via the marketplace). */
    private function cancelBuyerPurchaseOrder(MarketplaceOrder $order, string $reason): void
    {
        if ($order->buyer_purchase_order_id === null) {
            return;
        }
        $this->tenant->runAs(Organization::query()->findOrFail($order->buyer_organization_id), function () use ($order, $reason) {
            $po = PurchaseOrder::query()->find($order->buyer_purchase_order_id);
            if ($po !== null && ! in_array($po->status, ['cancelled', 'closed', 'received', 'partially_received'], true)) {
                $this->purchaseOrders->cancel($po, "[via marketplace] {$reason}");
            }
        });
    }

    /** The buyer receives into its own catalogue, so the product is added there if missing. */
    private function ensureCatalogued(string $productId, string $currency): void
    {
        if (! OrganizationProduct::query()->where('product_id', $productId)->exists()) {
            OrganizationProduct::query()->create(['product_id' => $productId, 'sku' => Product::query()->whereKey($productId)->value('platform_sku'), 'currency_code' => $currency]);
        }
    }
}

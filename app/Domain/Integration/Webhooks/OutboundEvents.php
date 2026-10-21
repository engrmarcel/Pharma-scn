<?php

namespace App\Domain\Integration\Webhooks;

use App\Domain\Finance\Events\PaymentConfirmed;
use App\Domain\Inventory\Events\BatchCreated;
use App\Domain\Inventory\Events\InventoryAdjusted;
use App\Domain\Inventory\Events\StockReceived;
use App\Domain\Inventory\Events\StockTransferred;
use App\Domain\Organizations\Events\OrganizationVerificationChanged;
use App\Domain\Procurement\Events\PurchaseOrderApproved;
use App\Domain\Procurement\Events\PurchaseOrderCreated;
use App\Domain\Sales\Events\SaleCompleted;
use App\Domain\Sales\Events\SalesOrderConfirmed;
use App\Domain\Sales\Events\SalesOrderCreated;
use App\Support\Events\DomainEvent;
use Illuminate\Support\Facades\DB;

/**
 * The outbound event catalogue: which domain events an organization can
 * subscribe to, and the payload each carries. Payloads are built inside the
 * business transaction, so they describe exactly the committed change, and
 * they carry the data a receiving ERP needs without calling back (it has no
 * user session). Product lines carry our product id, SKU and GTIN; a
 * subscription linked to a connector also gets that connector's own product
 * keys (product_external_id) at delivery.
 */
final class OutboundEvents
{
    public const TYPES = [
        StockReceived::class => 'inventory.stock_received',
        StockTransferred::class => 'inventory.stock_transferred',
        InventoryAdjusted::class => 'inventory.adjusted',
        BatchCreated::class => 'inventory.batch_created',
        PurchaseOrderCreated::class => 'procurement.purchase_order_created',
        PurchaseOrderApproved::class => 'procurement.purchase_order_approved',
        SalesOrderCreated::class => 'sales.order_created',
        SalesOrderConfirmed::class => 'sales.order_confirmed',
        SaleCompleted::class => 'sales.invoice_issued',
        PaymentConfirmed::class => 'finance.payment_confirmed',
        OrganizationVerificationChanged::class => 'organization.verification_changed',
    ];

    public const DESCRIPTIONS = [
        'inventory.stock_received' => 'Stock received into a warehouse (purchase receipt, direct receipt, transfer arrival, return). Data: posting with movement lines.',
        'inventory.stock_transferred' => 'Stock moved between warehouses. Data: posting with movement lines.',
        'inventory.adjusted' => 'Stock adjusted, counted, issued or changed state (sale, write-off, quarantine…). Data: posting with movement lines.',
        'inventory.batch_created' => 'A new batch was recorded. Data: batch with product, expiry and quality status.',
        'procurement.purchase_order_created' => 'A purchase order was created. Data: order with supplier and lines.',
        'procurement.purchase_order_approved' => 'A purchase order was approved. Data: order with supplier and lines.',
        'sales.order_created' => 'A sales order was created. Data: order with customer and lines.',
        'sales.order_confirmed' => 'A sales order was confirmed. Data: order with customer and lines.',
        'sales.invoice_issued' => 'A sales invoice was issued (from a sales order or POS). Data: invoice header.',
        'finance.payment_confirmed' => 'A payment was confirmed. Data: payment.',
        'organization.verification_changed' => 'The organization\'s platform verification status changed. Data: from and to status.',
        'webhook.test' => 'Sent on request to check a subscription. Data: a message.',
    ];

    public static function typeFor(DomainEvent $event): ?string
    {
        return self::TYPES[$event::class] ?? null;
    }

    /** @return list<string> */
    public static function types(): array
    {
        return array_keys(self::DESCRIPTIONS);
    }

    public static function describe(): array
    {
        return array_map(fn ($type, $description) => ['type' => $type, 'description' => $description], array_keys(self::DESCRIPTIONS), self::DESCRIPTIONS);
    }

    /** @return array{0: ?string, 1: ?string, 2: array} aggregate type, aggregate id, payload */
    public static function payload(DomainEvent $event): array
    {
        return match (true) {
            $event instanceof StockReceived, $event instanceof StockTransferred, $event instanceof InventoryAdjusted => ['stock_posting', $event->postingId, [
                'posting_id' => $event->postingId,
                'reference_type' => $event->referenceType,
                'reference_id' => $event->referenceId,
                'movements' => self::movements($event->organizationId, $event->postingId),
            ]],
            $event instanceof BatchCreated => ['batch', $event->batchId, self::batch($event->organizationId, $event->batchId)],
            $event instanceof PurchaseOrderCreated, $event instanceof PurchaseOrderApproved => ['purchase_order', $event->purchaseOrderId, self::purchaseOrder($event->organizationId, $event->purchaseOrderId)],
            $event instanceof SalesOrderCreated, $event instanceof SalesOrderConfirmed => ['sales_order', $event->salesOrderId, self::salesOrder($event->organizationId, $event->salesOrderId)],
            $event instanceof SaleCompleted => ['sales_invoice', $event->salesInvoiceId, self::invoice($event->organizationId, $event->salesInvoiceId)],
            $event instanceof PaymentConfirmed => ['payment', $event->paymentId, self::payment($event->organizationId, $event->paymentId)],
            $event instanceof OrganizationVerificationChanged => ['organization', $event->organizationId, ['from' => $event->fromStatus, 'to' => $event->toStatus]],
            default => [null, null, []],
        };
    }

    private static function movements(string $organizationId, string $postingId): array
    {
        return DB::table('inventory_movements as m')
            ->join('warehouses as w', 'w.id', '=', 'm.warehouse_id')
            ->join('batches as b', 'b.id', '=', 'm.batch_id')
            ->leftJoin('organization_products as op', fn ($j) => $j->on('op.product_id', '=', 'm.product_id')->on('op.organization_id', '=', 'm.organization_id'))
            ->where('m.organization_id', $organizationId)->where('m.posting_id', $postingId)
            ->orderBy('w.code')->orderBy('op.sku')->orderBy('b.batch_number')
            ->get(['w.code as warehouse_code', 'm.product_id', 'op.sku', 'b.batch_number', 'b.expiry_date', 'm.stock_state', 'm.quantity', 'm.movement_type', 'm.reason_code', 'm.posted_at'])
            ->map(fn ($r) => (array) $r + ['gtin' => self::gtin($r->product_id)])->all();
    }

    private static function batch(string $organizationId, string $batchId): array
    {
        $b = DB::table('batches as b')->leftJoin('organization_products as op', fn ($j) => $j->on('op.product_id', '=', 'b.product_id')->on('op.organization_id', '=', 'b.organization_id'))
            ->where('b.organization_id', $organizationId)->where('b.id', $batchId)
            ->first(['b.id as batch_id', 'b.product_id', 'op.sku', 'b.batch_number', 'b.expiry_date', 'b.manufacturing_date', 'b.quality_status']);

        return $b ? (array) $b + ['gtin' => self::gtin($b->product_id)] : ['batch_id' => $batchId];
    }

    private static function purchaseOrder(string $organizationId, string $id): array
    {
        $o = DB::table('purchase_orders as o')->join('suppliers as s', 's.id', '=', 'o.supplier_id')->join('warehouses as w', 'w.id', '=', 'o.warehouse_id')
            ->where('o.organization_id', $organizationId)->where('o.id', $id)
            ->first(['o.id', 'o.number', 'o.status', 'o.supplier_id', 's.name as supplier_name', 'w.code as warehouse_code', 'o.currency_code', 'o.order_date', 'o.expected_date', 'o.subtotal', 'o.tax_total', 'o.total']);

        return $o ? (array) $o + ['lines' => self::lines('purchase_order_lines', 'purchase_order_id', $organizationId, $id)] : ['id' => $id];
    }

    private static function salesOrder(string $organizationId, string $id): array
    {
        $o = DB::table('sales_orders as o')->join('customers as c', 'c.id', '=', 'o.customer_id')->join('warehouses as w', 'w.id', '=', 'o.warehouse_id')
            ->where('o.organization_id', $organizationId)->where('o.id', $id)
            ->first(['o.id', 'o.number', 'o.status', 'o.customer_id', 'c.name as customer_name', 'w.code as warehouse_code', 'o.currency_code', 'o.order_date', 'o.requested_date', 'o.subtotal', 'o.discount_total', 'o.tax_total', 'o.total']);

        return $o ? (array) $o + ['lines' => self::lines('sales_order_lines', 'sales_order_id', $organizationId, $id)] : ['id' => $id];
    }

    private static function lines(string $table, string $foreignKey, string $organizationId, string $documentId): array
    {
        return DB::table("{$table} as l")
            ->leftJoin('organization_products as op', fn ($j) => $j->on('op.product_id', '=', 'l.product_id')->where('op.organization_id', '=', $organizationId))
            ->where("l.{$foreignKey}", $documentId)->orderBy('l.id')
            ->get(['l.product_id', 'op.sku', 'l.package_level_code', 'l.quantity', 'l.base_quantity', 'l.unit_price'])
            ->map(fn ($r) => (array) $r + ['gtin' => self::gtin($r->product_id)])->all();
    }

    private static function invoice(string $organizationId, string $id): array
    {
        $i = DB::table('sales_invoices as i')->join('customers as c', 'c.id', '=', 'i.customer_id')
            ->where('i.organization_id', $organizationId)->where('i.id', $id)
            ->first(['i.id', 'i.number', 'i.source', 'i.sales_order_id', 'i.customer_id', 'c.name as customer_name', 'i.invoice_date', 'i.due_date', 'i.currency_code', 'i.subtotal', 'i.discount_total', 'i.tax_total', 'i.total', 'i.payment_status']);

        return $i ? (array) $i : ['id' => $id];
    }

    private static function payment(string $organizationId, string $id): array
    {
        $p = DB::table('payments')->where('organization_id', $organizationId)->where('id', $id)
            ->first(['id', 'number', 'direction', 'party_type', 'party_id', 'method', 'amount', 'currency_code', 'payment_date', 'reference', 'status']);

        return $p ? (array) $p : ['id' => $id];
    }

    private static function gtin(string $productId): ?string
    {
        return DB::table('product_identifiers')->where('product_id', $productId)->where('type', 'gtin')
            ->orderByDesc('is_primary')->orderBy('value')->value('value');
    }
}

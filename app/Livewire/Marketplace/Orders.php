<?php

namespace App\Livewire\Marketplace;

use App\Domain\Marketplace\Models\MarketplaceDispute;
use App\Livewire\WebPage;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/**
 * Marketplace orders from either side. Buyers submit, approve internally, receive shipments and
 * record the supplier's invoice; suppliers accept into their ERP, ship by batch and invoice.
 */
#[Title('Marketplace orders')]
class Orders extends WebPage
{
    public const ROUTE = 'marketplace.orders';

    #[Url]
    public string $as = '';

    #[Url]
    public string $status = '';

    #[Url(as: 'open')]
    public ?string $openId = null;

    /** Action that needs input: reason, warehouse, shipment lines, dispute. */
    public ?array $action = null;

    protected array $filters = ['as', 'status'];

    public function open(string $id): void
    {
        $this->openId = $id;
        $this->action = null;
    }

    public function start(string $type, array $extra = []): void
    {
        $this->resetErrorBag();
        $this->action = $extra + ['type' => $type, 'reason' => '', 'warehouse_id' => '', 'carrier' => '', 'tracking_reference' => '', 'lines' => [],
            'dispute_type' => 'damaged', 'requested_resolution' => 'credit', 'description' => ''];
    }

    public function startShipment(array $order): void
    {
        $this->start('ship', ['lines' => collect($order['lines'])->mapWithKeys(fn ($l) => [$l['id'] => (string) max(0, (float) $l['quantity'] - (float) $l['shipped_quantity'])])->all()]);
    }

    public function simple(string $action): void
    {
        $messages = ['submit' => 'Order sent to the supplier.', 'approve' => 'Order approved internally.', 'invoice' => 'Invoice issued.'];
        $uri = $action === 'invoice' ? "marketplace/orders/{$this->openId}/invoices" : "marketplace/orders/{$this->openId}/{$action}";
        $this->attempt(fn ($api) => $api->post($uri), $messages[$action] ?? null);
    }

    public function apply(): void
    {
        $a = $this->action;
        $id = $this->openId;
        $result = match ($a['type']) {
            'accept' => $this->submit('action', "marketplace/orders/{$id}/accept", array_filter(['warehouse_id' => $a['warehouse_id'], 'reason' => $a['reason']]), 'Order accepted: a sales order was created in your ERP.'),
            'reject', 'cancel', 'disapprove' => $this->submit('action', "marketplace/orders/{$id}/{$a['type']}", ['reason' => $a['reason']], 'Order '.$a['type'].($a['type'] === 'cancel' ? 'led.' : 'ed.')),
            'ship' => $this->submit('action', "marketplace/orders/{$id}/shipments", array_filter(['carrier' => $a['carrier'], 'tracking_reference' => $a['tracking_reference']])
                + ['lines' => collect($a['lines'])->filter(fn ($q) => (float) $q > 0)->map(fn ($q, $line) => ['order_line_id' => (int) $line, 'quantity' => $q])->values()->all()], 'Shipment recorded: stock issued by first expiry.'),
            'dispute' => $this->submit('action', "marketplace/orders/{$id}/disputes", ['type' => $a['dispute_type'], 'requested_resolution' => $a['requested_resolution'], 'description' => $a['description']], 'Dispute raised.'),
        };
        if ($result !== null) {
            $this->action = null;
        }
    }

    public function receive(string $shipmentId): void
    {
        $this->attempt(fn ($api) => $api->post("marketplace/shipments/{$shipmentId}/receive"), 'Shipment received into your warehouse.');
    }

    public function recordInvoice(string $invoiceId): void
    {
        $this->attempt(fn ($api) => $api->post("marketplace/invoices/{$invoiceId}/record"), 'Invoice recorded in your payables for matching.');
    }

    public function render()
    {
        $result = $this->api()->get('marketplace/orders', ['as' => $this->as, 'status' => $this->status, 'page' => $this->page]);
        $detail = $this->openId ? ($this->read("marketplace/orders/{$this->openId}")['data'] ?? null) : null;

        return view('livewire.marketplace.orders', [
            'rows' => $result['data'] ?? [],
            'meta' => $result,
            'detail' => $detail,
            'shipments' => $detail ? ($this->read("marketplace/orders/{$this->openId}/shipments")['data'] ?? []) : [],
            'invoices' => $detail ? ($this->read("marketplace/orders/{$this->openId}/invoices")['data'] ?? []) : [],
            'history' => $detail ? ($this->read("marketplace/orders/{$this->openId}/history")['data']['events'] ?? []) : [],
            'warehouses' => $this->warehouseOptions(),
            'disputeTypes' => MarketplaceDispute::TYPES,
            'disputeRequests' => MarketplaceDispute::REQUESTS,
            'can' => ['place' => $this->can('marketplace.orders.place'), 'approve' => $this->can('marketplace.orders.approve'), 'fulfil' => $this->can('marketplace.orders.fulfil'),
                'dispute' => $this->can('marketplace.disputes.manage'), 'invoice' => $this->can('procurement.invoice')],
        ]);
    }
}

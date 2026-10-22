<?php

namespace App\Domain\Marketplace\Services;

use App\Domain\Inventory\Services\DocumentNumberer;
use App\Domain\Marketplace\Models\MarketplaceOrder;
use App\Domain\Marketplace\Models\MarketplaceQuote;
use App\Domain\Marketplace\Models\Rfq;
use App\Domain\Marketplace\Models\RfqInvitation;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Platform\Services\AuditLogger;
use App\Support\Exceptions\BusinessRuleViolation;
use App\Support\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Requests for quotation and supplier quotations (spec §14.2).
 *
 *   rfq:   draft → open (invited suppliers see it) → closed | awarded | cancelled
 *   quote: submitted → accepted (becomes a marketplace order) | rejected | withdrawn | expired
 *
 * Restrictions are checked per supplier and product when inviting and quoting,
 * so a supplier is never asked for (and cannot offer) what the buyer may not buy.
 */
class RfqService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly MarketplaceAccess $access,
        private readonly RelationshipService $relationships,
        private readonly MarketplaceOrderService $orders,
        private readonly DocumentNumberer $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array{title: string, response_deadline: string, delivery_warehouse_id: string, notes?: ?string, lines: array, supplier_organization_ids: array} $data */
    public function create(array $data): Rfq
    {
        $buyer = $this->tenant->organization();
        $this->access->assertParticipates($buyer);
        $suppliers = Organization::query()->whereIn('id', $data['supplier_organization_ids'])->get();
        foreach ($suppliers as $supplier) {
            $this->access->assertParticipates($supplier, $supplier->name);
            foreach ($data['lines'] as $i => $line) {
                if ($block = $this->access->productBlock($buyer, $supplier, $line['product_id'])) {
                    throw new BusinessRuleViolation($block['code'], "{$supplier->name}: {$block['message']}", 403, ['line' => $i, 'supplier_organization_id' => $supplier->id]);
                }
            }
        }

        return DB::transaction(function () use ($buyer, $data, $suppliers) {
            $rfq = Rfq::query()->create([
                'buyer_organization_id' => $buyer->id, 'number' => $this->numbers->next('RFQ'), 'title' => $data['title'],
                'response_deadline' => $data['response_deadline'], 'delivery_warehouse_id' => $data['delivery_warehouse_id'],
                'currency_code' => $buyer->country->currency_code, 'notes' => $data['notes'] ?? null, 'created_by' => Auth::id(),
            ]);
            foreach ($data['lines'] as $line) {
                $rfq->lines()->create(['product_id' => $line['product_id'], 'package_level_code' => $line['package_level_code'], 'quantity' => $line['quantity'], 'notes' => $line['notes'] ?? null]);
            }
            foreach ($suppliers as $supplier) {
                $rfq->invitations()->create(['supplier_organization_id' => $supplier->id]);
            }
            $this->audit->record('rfq.created', null, new: ['rfq_id' => $rfq->id, 'number' => $rfq->number, 'suppliers' => $suppliers->count()]);

            return $rfq->load('lines', 'invitations');
        });
    }

    public function transition(Rfq $rfq, string $to, ?string $reason = null): Rfq
    {
        return DB::transaction(function () use ($rfq, $to, $reason) {
            $locked = Rfq::query()->lockForUpdate()->findOrFail($rfq->id);
            $this->assertBuyer($locked);
            $allowed = ['open' => ['draft'], 'closed' => ['open'], 'cancelled' => ['draft', 'open', 'closed']][$to];
            if (! in_array($locked->status, $allowed, true)) {
                throw new BusinessRuleViolation('rfq_state_invalid', "The RFQ is {$locked->status}.", 409);
            }
            if ($to === 'open' && $locked->response_deadline->isPast()) {
                throw new BusinessRuleViolation('rfq_deadline_past', 'Set a response deadline in the future before opening the RFQ.', 422);
            }
            $locked->forceFill(['status' => $to] + ($to === 'open' ? ['opened_at' => now()] : ['closed_at' => now()]))->save();
            if ($to === 'cancelled') {
                MarketplaceQuote::query()->where('rfq_id', $locked->id)->where('status', 'submitted')->update(['status' => 'rejected', 'decision_notes' => 'RFQ cancelled: '.$reason, 'updated_at' => now()]);
            }
            $this->audit->record("rfq.{$to}", null, new: ['rfq_id' => $locked->id, 'reason' => $reason]);

            return $locked;
        });
    }

    /** Supplier: answer an open RFQ (or, with $rfq null, quote directly to a buyer it has a relationship with). */
    public function submitQuote(?Rfq $rfq, ?Organization $buyer, array $data): MarketplaceQuote
    {
        $supplier = $this->tenant->organization();
        $this->access->assertParticipates($supplier);

        return DB::transaction(function () use ($rfq, $buyer, $data, $supplier) {
            $invitation = null;
            $rfqLines = collect();
            if ($rfq !== null) {
                $rfq = Rfq::query()->lockForUpdate()->findOrFail($rfq->id);
                $invitation = RfqInvitation::query()->where('rfq_id', $rfq->id)->where('supplier_organization_id', $supplier->id)->first()
                    ?? throw new BusinessRuleViolation('rfq_not_invited', 'Your organization was not invited to this RFQ.', 403);
                if ($rfq->status !== 'open' || $rfq->response_deadline->isPast()) {
                    throw new BusinessRuleViolation('rfq_closed', 'This RFQ is no longer accepting quotations.', 409);
                }
                $buyer = Organization::query()->findOrFail($rfq->buyer_organization_id);
                $rfqLines = $rfq->lines()->get()->keyBy('id');
            } else {
                $this->relationships->approved($buyer->id, $supplier->id);
            }

            $lines = [];
            $subtotal = '0';
            foreach ($data['lines'] as $i => $line) {
                $rfqLine = isset($line['rfq_line_id']) ? ($rfqLines[$line['rfq_line_id']] ?? throw new BusinessRuleViolation('rfq_line_unknown', 'That line is not part of the RFQ.', 422, ['line' => $i])) : null;
                if ($rfq !== null && $rfqLine === null) {
                    throw new BusinessRuleViolation('rfq_line_required', 'Each quoted line must answer an RFQ line.', 422, ['line' => $i]);
                }
                $productId = $rfqLine?->product_id ?? $line['product_id'];
                if ($block = $this->access->productBlock($buyer, $supplier, $productId)) {
                    throw new BusinessRuleViolation($block['code'], $block['message'], 403, ['line' => $i]);
                }
                $total = Money::mul((string) $line['quantity'], (string) $line['unit_price']);
                $lines[] = ['rfq_line_id' => $rfqLine?->id, 'product_id' => $productId, 'package_level_code' => $rfqLine?->package_level_code ?? $line['package_level_code'],
                    'quantity' => $line['quantity'], 'unit_price' => $line['unit_price'], 'line_total' => $total, 'lead_time_days' => $line['lead_time_days'] ?? null];
                $subtotal = Money::add($subtotal, $total);
            }

            if ($rfq !== null) {
                // A new quotation replaces this supplier's earlier one for the same RFQ.
                MarketplaceQuote::query()->where('rfq_id', $rfq->id)->where('supplier_organization_id', $supplier->id)->where('status', 'submitted')
                    ->update(['status' => 'withdrawn', 'decision_notes' => 'Replaced by a newer quotation.', 'updated_at' => now()]);
            }
            $quote = MarketplaceQuote::query()->create([
                'number' => $this->numbers->next('MQ'), 'rfq_id' => $rfq?->id, 'buyer_organization_id' => $buyer->id, 'supplier_organization_id' => $supplier->id,
                'valid_until' => $data['valid_until'], 'currency_code' => $supplier->country->currency_code, 'subtotal' => $subtotal,
                'notes' => $data['notes'] ?? null, 'submitted_by' => Auth::id(),
            ]);
            $quote->lines()->createMany($lines);
            $invitation?->forceFill(['status' => 'quoted', 'responded_at' => now()])->save();
            $this->audit->record('marketplace_quote.submitted', null, new: ['quote_id' => $quote->id, 'number' => $quote->number, 'subtotal' => $subtotal]);

            return $quote->load('lines');
        });
    }

    public function declineInvitation(Rfq $rfq, string $reason): RfqInvitation
    {
        $invitation = RfqInvitation::query()->where('rfq_id', $rfq->id)->where('supplier_organization_id', $this->tenant->id())->firstOrFail();
        $invitation->forceFill(['status' => 'declined', 'decline_reason' => $reason, 'responded_at' => now()])->save();

        return $invitation;
    }

    public function withdraw(MarketplaceQuote $quote): MarketplaceQuote
    {
        return $this->decideQuote($quote, 'supplier', ['submitted'], 'withdrawn', null);
    }

    public function reject(MarketplaceQuote $quote, string $notes): MarketplaceQuote
    {
        return $this->decideQuote($quote, 'buyer', ['submitted'], 'rejected', $notes);
    }

    /** Buyer: accept a quotation. It becomes a draft marketplace order at the quoted prices. */
    public function accept(MarketplaceQuote $quote, ?string $deliveryWarehouseId): MarketplaceOrder
    {
        return DB::transaction(function () use ($quote, $deliveryWarehouseId) {
            $locked = $this->decideQuote($quote, 'buyer', ['submitted'], 'accepted', null);
            if ($locked->valid_until->lt(business_today())) {
                throw new BusinessRuleViolation('quote_expired', 'This quotation has expired.', 409);
            }
            $rfq = $locked->rfq_id ? Rfq::query()->lockForUpdate()->find($locked->rfq_id) : null;
            $order = $this->orders->createFromQuote($locked, $deliveryWarehouseId ?? $rfq?->delivery_warehouse_id
                ?? throw new BusinessRuleViolation('delivery_warehouse_required', 'Choose the warehouse to deliver to.', 422));
            $rfq?->forceFill(['status' => 'awarded', 'closed_at' => $rfq->closed_at ?? now()])->save();

            return $order;
        });
    }

    /** Per RFQ line, every live offer side by side, cheapest first. */
    public function comparison(Rfq $rfq): array
    {
        $this->assertBuyer($rfq);
        $quotes = MarketplaceQuote::query()->with(['lines', 'supplier'])->where('rfq_id', $rfq->id)->whereIn('status', ['submitted', 'accepted'])->get();

        return ['rfq' => $rfq->only(['id', 'number', 'title', 'status', 'response_deadline']), 'lines' => $rfq->lines()->get()->map(function ($line) use ($quotes) {
            $offers = $quotes->flatMap(fn ($q) => $q->lines->where('rfq_line_id', $line->id)->map(fn ($ql) => [
                'quote_id' => $q->id, 'quote_number' => $q->number, 'status' => $q->status, 'valid_until' => $q->valid_until->toDateString(),
                'supplier' => ['id' => $q->supplier->id, 'name' => $q->supplier->name, 'verification' => MarketplaceAccess::verificationLabel($q->supplier)],
                'quantity' => (string) $ql->quantity, 'unit_price' => (string) $ql->unit_price, 'line_total' => (string) $ql->line_total, 'lead_time_days' => $ql->lead_time_days,
            ]))->sortBy(fn ($o) => (float) $o['unit_price'])->values();

            return $line->only(['id', 'product_id', 'package_level_code', 'quantity']) + ['offers' => $offers, 'best_unit_price' => $offers->first()['unit_price'] ?? null];
        })->values(), 'note' => 'Prices are before tax; tax is added by the supplier\'s ERP when an order is accepted.'];
    }

    private function decideQuote(MarketplaceQuote $quote, string $side, array $from, string $to, ?string $notes): MarketplaceQuote
    {
        $locked = MarketplaceQuote::query()->lockForUpdate()->findOrFail($quote->id);
        $owner = $side === 'buyer' ? $locked->buyer_organization_id : $locked->supplier_organization_id;
        if ($owner !== $this->tenant->id()) {
            throw new BusinessRuleViolation('quote_not_yours', "Only the {$side} can do this.", 403);
        }
        if (! in_array($locked->status, $from, true)) {
            throw new BusinessRuleViolation('quote_state_invalid', "The quotation is {$locked->status}.", 409);
        }
        $locked->forceFill(['status' => $to, 'decided_by' => Auth::id(), 'decided_at' => now(), 'decision_notes' => $notes])->save();
        $this->audit->record("marketplace_quote.{$to}", null, new: ['quote_id' => $locked->id, 'notes' => $notes]);

        return $locked;
    }

    private function assertBuyer(Rfq $rfq): void
    {
        if ($rfq->buyer_organization_id !== $this->tenant->id()) {
            throw new BusinessRuleViolation('rfq_not_yours', 'Only the buyer manages this RFQ.', 403);
        }
    }
}

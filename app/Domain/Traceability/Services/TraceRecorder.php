<?php

namespace App\Domain\Traceability\Services;

use App\Domain\Inventory\Events\BatchCreated;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Traceability\Models\TraceEvent;
use App\Domain\Traceability\Models\TraceEventVerification;
use App\Support\Events\DomainEvent;
use App\Support\Events\EventRecorder;
use Illuminate\Support\Facades\DB;

/**
 * Records chain-of-custody events from what the organization actually does
 * (spec §16.1: actual events, not an assumed route). Called inside the stock
 * posting's transaction, so an event exists exactly when the movement does.
 *
 * One event per batch and warehouse leg of a posting:
 *
 *   receipt (goods receipt)       stock_receipt; pharmacy_receipt at pharmacies and hospitals;
 *                                 import_receipt when the supplier is in another country
 *   receipt (direct)              stock_receipt (opening balance, found stock…)
 *   sale (sales order)            shipment_dispatch: custody passes to the customer
 *   sale (POS)                    dispensing_or_sale (no patient data is recorded)
 *   sale_return / supplier_return return_receipt / return_dispatch
 *   transfer_out / transfer_in    transfer_dispatch / transfer_receipt
 *   state_change                  quality_release, quarantine, quality_rejection, recall
 *   adjustment, write_off, count  stock_adjustment, stock_count_correction
 *
 * Counterparties come from the document: supplier or customer, and its platform
 * organization when the party is linked to one. A recorded counterparty is the
 * recorder's statement; it becomes "corroborated" only when that organization
 * records the matching movement (see corroborate()).
 */
class TraceRecorder implements EventRecorder
{
    public function __construct(private readonly TraceChain $chain) {}

    /** BatchCreated (raised inside the receiving or integration transaction). */
    public function record(DomainEvent $event): void
    {
        if (! $event instanceof BatchCreated) {
            return;
        }
        $batch = DB::table('batches')->where('id', $event->batchId)->first();
        $org = Organization::query()->find($event->organizationId);
        if ($batch === null || $org === null) {
            return;
        }
        $this->chain->append($org->id, [
            'event_type' => $org->type?->value === 'manufacturer' ? 'production' : 'batch_registered',
            'product_id' => $batch->product_id, 'batch_number' => $batch->batch_number, 'batch_id' => $batch->id,
            'country_code' => $org->country_code,
            'details' => array_filter(['expiry_date' => $batch->expiry_date, 'manufacturing_date' => $batch->manufacturing_date, 'quality_status' => $batch->quality_status]),
        ]);
    }

    /** @param list<array> $movements the rows the ledger just inserted */
    public function recordPosting(Organization $org, string $postingId, array $movements, ?string $reasonCode, ?string $referenceType, ?string $referenceId): void
    {
        $batches = DB::table('batches')->whereIn('id', array_unique(array_column($movements, 'batch_id')))->get()->keyBy('id');
        $warehouses = DB::table('warehouses')->whereIn('id', array_unique(array_column($movements, 'warehouse_id')))->get(['id', 'code', 'gln'])->keyBy('id');
        $party = $this->counterparty($referenceType, $referenceId);

        $legs = [];
        foreach ($movements as $m) {
            $key = "{$m['batch_id']}|{$m['warehouse_id']}|{$m['movement_type']}";
            $legs[$key] ??= ['batch_id' => $m['batch_id'], 'warehouse_id' => $m['warehouse_id'], 'type' => $m['movement_type'], 'net' => '0', 'in' => '0', 'states' => []];
            $legs[$key]['net'] = bcadd($legs[$key]['net'], (string) $m['quantity'], 4);
            if (bccomp((string) $m['quantity'], '0', 4) > 0) {
                $legs[$key]['in'] = bcadd($legs[$key]['in'], (string) $m['quantity'], 4);
            }
            $legs[$key]['states'][] = $m['stock_state'];
        }

        foreach ($legs as $leg) {
            $batch = $batches[$leg['batch_id']];
            $location = $warehouses[$leg['warehouse_id']]->code ?? null;
            $locationGln = $warehouses[$leg['warehouse_id']]->gln ?? null;
            [$type, $outbound] = $this->classify($org, $leg['type'], $reasonCode, $referenceType, $party);
            $quantity = $leg['type'] === 'state_change' ? $leg['in'] : ltrim($leg['net'], '-');

            $event = $this->chain->append($org->id, [
                'event_type' => $type,
                'product_id' => $batch->product_id,
                'batch_number' => $batch->batch_number,
                'batch_id' => $batch->id,
                'quantity' => $quantity,
                'source_organization_id' => $outbound ? $org->id : ($party['organization_id'] ?? null),
                'destination_organization_id' => $outbound ? ($party['organization_id'] ?? null) : $org->id,
                'source_location' => $outbound ? $location : null,
                'destination_location' => $outbound ? null : $location,
                'counterparty_name' => $party['name'] ?? null,
                'related_type' => $referenceType,
                'related_id' => $referenceId,
                'posting_id' => $postingId,
                'country_code' => $org->country_code,
                'details' => array_filter(['movement_type' => $leg['type'], 'reason_code' => $reasonCode, 'signed_quantity' => $leg['net'], 'location_gln' => $locationGln,
                    'organization_gln' => $org->gln, 'counterparty_gln' => $party['gln'] ?? null,
                    'stock_states' => array_values(array_unique($leg['states']))]),
            ]);
            if (in_array($type, ['stock_receipt', 'pharmacy_receipt', 'import_receipt', 'return_receipt'], true)) {
                $this->corroborate($event);
            }
        }
    }

    /**
     * A receipt from a platform organization corroborates that organization's dispatch of the
     * same lot to this one (and the other way round). This is two independent records agreeing,
     * not proof of authenticity.
     */
    private function corroborate(TraceEvent $receipt): void
    {
        if ($receipt->source_organization_id === null) {
            return;
        }
        $dispatch = TraceEvent::query()->where('organization_id', $receipt->source_organization_id)
            ->where('destination_organization_id', $receipt->organization_id)->whereIn('event_type', ['shipment_dispatch', 'return_dispatch'])
            ->where('product_id', $receipt->product_id)->where('batch_number', $receipt->batch_number)
            ->whereNotExists(fn ($q) => $q->from('trace_event_verifications as v')->whereColumn('v.trace_event_id', 'trace_events.id')->where('v.level', 'corroborated'))
            ->orderBy('event_time')->first();
        if ($dispatch === null) {
            return;
        }
        foreach ([[$receipt, $dispatch], [$dispatch, $receipt]] as [$event, $other]) {
            TraceEventVerification::query()->create(['trace_event_id' => $event->id, 'level' => 'corroborated', 'method' => 'counterparty_record',
                'corroborating_event_id' => $other->id, 'verified_by_organization_id' => $other->organization_id,
                'evidence' => 'The counterparty recorded the matching movement of this lot.']);
        }
    }

    /** @return array{0: string, 1: bool} event type and whether the leg leaves this organization */
    private function classify(Organization $org, string $movementType, ?string $reasonCode, ?string $referenceType, ?array $party): array
    {
        $dispensary = in_array($org->type?->value, ['pharmacy', 'pharmacy_chain', 'hospital', 'healthcare_facility'], true);

        return match ($movementType) {
            'receipt' => [match (true) {
                $referenceType === 'goods_receipt' && ($party['country_code'] ?? null) !== null && $party['country_code'] !== $org->country_code => 'import_receipt',
                $referenceType === 'goods_receipt' && $dispensary => 'pharmacy_receipt',
                default => 'stock_receipt',
            }, false],
            'sale' => [$referenceType === 'pos_sale_line' ? 'dispensing_or_sale' : 'shipment_dispatch', true],
            'sale_return' => ['return_receipt', false],
            'supplier_return' => ['return_dispatch', true],
            'transfer_out' => ['transfer_dispatch', true],
            'transfer_in' => ['transfer_receipt', false],
            'state_change' => [match (true) {
                $reasonCode === 'quality_released' => 'quality_release',
                $reasonCode === 'quality_quarantined' => 'quarantine',
                $reasonCode === 'quality_rejected' => 'quality_rejection',
                str_starts_with((string) $reasonCode, 'recall') => 'recall',
                default => 'state_change',
            }, false],
            'count' => ['stock_count_correction', false],
            default => ['stock_adjustment', false],
        };
    }

    /** @return array{name: string, organization_id: ?string, country_code: ?string}|null */
    private function counterparty(?string $type, ?string $id): ?array
    {
        if ($id === null) {
            return null;
        }
        $party = match ($type) {
            'goods_receipt' => DB::table('goods_receipts as g')->join('suppliers as p', 'p.id', '=', 'g.supplier_id')->where('g.id', $id),
            'supplier_return' => DB::table('supplier_returns as r')->join('suppliers as p', 'p.id', '=', 'r.supplier_id')->where('r.id', $id),
            'sales_order_line' => DB::table('sales_order_lines as l')->join('sales_orders as o', 'o.id', '=', 'l.sales_order_id')
                ->join('customers as p', 'p.id', '=', 'o.customer_id')->where('l.id', $id),
            'credit_note' => DB::table('credit_notes as n')->join('customers as p', 'p.id', '=', 'n.customer_id')->where('n.id', $id),
            default => null,
        };
        $row = $party?->first(['p.name', 'p.linked_organization_id', 'p.country_code']);
        $gln = $row?->linked_organization_id ? DB::table('organizations')->where('id', $row->linked_organization_id)->value('gln') : null;

        return $row ? ['name' => $row->name, 'organization_id' => $row->linked_organization_id, 'country_code' => $row->country_code, 'gln' => $gln] : null;
    }
}

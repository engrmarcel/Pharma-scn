<?php

namespace App\Domain\Traceability\Services;

use App\Domain\Organizations\Models\Organization;
use App\Domain\Traceability\Models\TraceEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Exports an organization's own traceability events as a GS1 EPCIS 2.0 JSON-LD
 * document, the GS1 standard for sharing event data. The NAFDAC Pharmaceutical
 * Products (Traceability) Regulations 2024 (reg. 12) require stakeholders to
 * capture and share events with the Agency, but no submission interface is
 * published; this export is the data the stakeholder would submit.
 *
 * Identifiers use GS1 Digital Link URIs (class level: GTIN + lot; instance
 * level: GTIN + serial; locations and parties: GLN). Products without a GTIN or
 * locations without a GLN fall back to platform URNs and are counted in
 * meta.incomplete: such events must be completed before submission.
 *
 * Corrections and reversals become an errorDeclaration on the original event,
 * as EPCIS prescribes, so the original stays as recorded.
 */
class EpcisExporter
{
    private const STEPS = [
        'production' => ['ADD', 'commissioning', 'active'],
        'identifiers_commissioned' => ['ADD', 'commissioning', 'active'],
        'stock_receipt' => ['OBSERVE', 'receiving', 'sellable_accessible'],
        'pharmacy_receipt' => ['OBSERVE', 'receiving', 'sellable_accessible'],
        'import_receipt' => ['OBSERVE', 'receiving', 'sellable_accessible'],
        'transfer_receipt' => ['OBSERVE', 'receiving', 'sellable_accessible'],
        'return_receipt' => ['OBSERVE', 'receiving', 'returned'],
        'shipment_dispatch' => ['OBSERVE', 'shipping', 'in_transit'],
        'transfer_dispatch' => ['OBSERVE', 'shipping', 'in_transit'],
        'return_dispatch' => ['OBSERVE', 'shipping', 'returned'],
        'dispensing_or_sale' => ['OBSERVE', 'dispensing', 'dispensed'],
        'quarantine' => ['OBSERVE', 'holding', 'non_sellable_other'],
        'recall' => ['OBSERVE', 'holding', 'recalled'],
        'quality_release' => ['OBSERVE', 'inspecting', 'sellable_accessible'],
        'quality_rejection' => ['OBSERVE', 'inspecting', 'non_sellable_other'],
        'stock_count_correction' => ['OBSERVE', 'cycle_counting', null],
    ];

    public function export(Organization $org, ?string $from, ?string $to, ?string $productId, ?string $batchNumber): array
    {
        $events = TraceEvent::query()->where('organization_id', $org->id)
            ->when($from, fn ($q, $v) => $q->where('event_time', '>=', $v.' 00:00:00'))
            ->when($to, fn ($q, $v) => $q->where('event_time', '<=', $v.' 23:59:59'))
            ->when($productId, fn ($q, $v) => $q->where('product_id', $v))
            ->when($batchNumber, fn ($q, $v) => $q->where('batch_number', strtoupper($v)))
            ->orderBy('sequence')->get();

        $amendments = TraceEvent::query()->where('organization_id', $org->id)
            ->where(fn ($q) => $q->whereIn('corrects_event_id', $events->pluck('id'))->orWhereIn('reverses_event_id', $events->pluck('id')))->get();
        $gtins = DB::table('product_identifiers')->whereIn('product_id', $events->pluck('product_id')->unique())->where('type', 'gtin')
            ->orderByDesc('is_primary')->get(['product_id', 'value'])->unique('product_id')->pluck('value', 'product_id');
        $orgGlns = DB::table('organizations')->whereIn('id', $events->pluck('source_organization_id')->merge($events->pluck('destination_organization_id'))->filter()->unique())
            ->pluck('gln', 'id');
        $offset = $this->offset($org);
        $incomplete = 0;

        $list = [];
        foreach ($events as $e) {
            if (! isset(self::STEPS[$e->event_type])) {
                continue; // batch registrations, adjustments, corrections themselves: not EPCIS object events
            }
            [$action, $bizStep, $disposition] = self::STEPS[$e->event_type];
            $gtin = isset($gtins[$e->product_id]) ? str_pad($gtins[$e->product_id], 14, '0', STR_PAD_LEFT) : null;
            $locationGln = $e->details['location_gln'] ?? null;
            if ($gtin === null || $locationGln === null) {
                $incomplete++;
            }

            $event = [
                'type' => 'ObjectEvent',
                'eventTime' => Carbon::parse($e->event_time)->setTimezone($offset['zone'])->format('Y-m-d\TH:i:s.vP'),
                'eventTimeZoneOffset' => $offset['offset'],
                'action' => $action,
                'bizStep' => $bizStep,
                'pharma:eventId' => $e->id,
                'pharma:recordedAt' => $e->recorded_at->toIso8601String(),
            ];
            if ($e->serial !== null && $gtin !== null) {
                $event['epcList'] = ["https://id.gs1.org/01/{$gtin}/21/".rawurlencode($e->serial)];
            } else {
                $event['quantityList'] = [array_filter(['epcClass' => $gtin !== null
                    ? "https://id.gs1.org/01/{$gtin}/10/".rawurlencode($e->batch_number)
                    : "urn:pharma-network:product:{$e->product_id}:lot:".rawurlencode($e->batch_number),
                    'quantity' => $e->quantity === null ? null : (float) $e->quantity, 'uom' => $e->quantity === null ? null : 'H87'])];
            }
            if ($disposition !== null) {
                $event['disposition'] = $disposition;
            }
            $location = $locationGln ? "https://id.gs1.org/414/{$locationGln}" : 'urn:pharma-network:location:'.$org->id.':'.rawurlencode((string) ($e->source_location ?? $e->destination_location ?? 'unknown'));
            $event['readPoint'] = ['id' => $location];
            $event['bizLocation'] = ['id' => $location];
            foreach (['source' => 'source_organization_id', 'destination' => 'destination_organization_id'] as $kind => $column) {
                if ($e->{$column} && ($gln = $orgGlns[$e->{$column}] ?? null)) {
                    $event["{$kind}List"] = [['type' => 'owning_party', $kind => "https://id.gs1.org/417/{$gln}"]];
                }
            }
            if ($e->related_type) {
                $event['bizTransactionList'] = [['type' => in_array($e->related_type, ['goods_receipt', 'sales_order_line'], true) ? 'po' : 'desadv',
                    'bizTransaction' => "urn:pharma-network:{$e->related_type}:{$e->related_id}"]];
            }
            $fix = $amendments->first(fn ($a) => $a->corrects_event_id === $e->id || $a->reverses_event_id === $e->id);
            if ($fix !== null) {
                $event['errorDeclaration'] = ['declarationTime' => $fix->recorded_at->toIso8601String(), 'reason' => 'incorrect_data',
                    'correctiveEventIDs' => $fix->event_type === 'correction' ? ["urn:pharma-network:event:{$fix->id}"] : []];
            }
            $list[] = $event;
        }

        return [
            '@context' => ['https://ref.gs1.org/standards/epcis/2.0.0/epcis-context.jsonld', ['pharma' => 'urn:pharma-network:']],
            'type' => 'EPCISDocument',
            'schemaVersion' => '2.0',
            'creationDate' => now()->toIso8601String(),
            'epcisBody' => ['eventList' => $list],
            'pharma:meta' => ['organization_gln' => $org->gln, 'events' => count($list), 'incomplete' => $incomplete,
                'note' => 'Events marked incomplete lack a product GTIN or a location GLN and use platform URNs; complete the master data before submitting them to a regulator.'],
        ];
    }

    /** @return array{zone: string, offset: string} */
    private function offset(Organization $org): array
    {
        $zone = DB::table('countries')->where('code', $org->country_code)->value('timezone') ?: config('app.timezone');

        return ['zone' => $zone, 'offset' => now($zone)->format('P')];
    }
}

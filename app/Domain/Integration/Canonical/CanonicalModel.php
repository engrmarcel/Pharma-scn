<?php

namespace App\Domain\Integration\Canonical;

use App\Support\Exceptions\BusinessRuleViolation;
use Illuminate\Validation\Rule;

/**
 * The Canonical Pharmaceutical Data Model (spec §15.2): the normalized shape
 * every connector maps its source records into, whatever the ERP.
 *
 * Each field is [type, requirement, description]:
 *   type         string:N | date | datetime | decimal | quantity (>= 0) | integer | boolean | enum:a,b | gtin
 *                | country | currency | array | list (array of scalars)
 *   requirement  true (required) | false (optional) | 'without:<field>' (required unless that field is given)
 *
 * External identifiers (external_id, product_external_id…) are the source
 * system's own keys. They are resolved through integration_external_references
 * and never assumed to equal our ids.
 *
 * "ingest" says whether the gateway applies the entity to business records
 * today. Every entity can be mapped and validated (mapping preview); the
 * others are applied as their modules land (see docs/ENTERPRISE_INTEGRATIONS.md).
 */
final class CanonicalModel
{
    public const VERSION = 1;

    private const ENTITIES = [
        'product' => ['ingest' => true, 'description' => 'An organization\'s product record, matched to the shared Product Master by GTIN, registration number or an existing reference. Upserts the organization\'s catalogue item (SKU, names, prices); never creates or edits a canonical product.', 'fields' => [
            'external_id' => ['string:128', true, 'The source system\'s product key (e.g. SAP material number).'],
            'name' => ['string:255', true, 'Product description in the source system; stored as the local name.'],
            'sku' => ['string:64', false, 'The organization\'s SKU. Defaults to external_id.'],
            'gtin' => ['gtin', false, 'GS1 GTIN-8/12/13/14 with a valid check digit. Used for matching.'],
            'registration_number' => ['string:64', false, 'Regulatory registration number (e.g. NAFDAC number). Used for matching.'],
            'registration_authority' => ['string:32', false, 'Authority code for the registration number, e.g. NAFDAC.'],
            'generic_name' => ['string:255', false, 'Used for match suggestions only.'],
            'brand_name' => ['string:255', false, 'Used for match suggestions only.'],
            'strength' => ['string:100', false, 'Informational.'],
            'dosage_form' => ['string:64', false, 'Informational.'],
            'manufacturer_name' => ['string:255', false, 'Informational.'],
            'purchase_cost' => ['quantity', false, 'Default purchase cost per base unit.'],
            'selling_price' => ['quantity', false, 'Default selling price per base unit.'],
            'currency_code' => ['currency', false, 'ISO 4217. Defaults to the organization\'s currency.'],
            'reorder_level' => ['quantity', false, 'In base units.'],
            'status' => ['enum:active,inactive', false, 'Catalogue item status.'],
        ]],
        'product_identifier' => ['ingest' => false, 'description' => 'An identifier of a product or one of its package levels.', 'fields' => [
            'product_external_id' => ['string:128', true, 'Source product key.'],
            'type' => ['enum:gtin,manufacturer_code,other', true, 'Identifier scheme.'],
            'value' => ['string:64', true, 'Identifier value.'],
            'package_level' => ['string:32', false, 'Package level code the identifier applies to.'],
        ]],
        'regulatory_identifier' => ['ingest' => false, 'description' => 'A regulatory registration of a product. Recorded as declared; never treated as verified.', 'fields' => [
            'product_external_id' => ['string:128', true, 'Source product key.'],
            'authority' => ['string:32', true, 'Authority code, e.g. NAFDAC.'],
            'identifier_type' => ['enum:registration_number,listing_number,licence_number', true, 'Kind of identifier.'],
            'value' => ['string:64', true, 'Identifier value.'],
            'country_code' => ['country', false, 'ISO 3166-1 alpha-2.'],
            'expiry_date' => ['date', false, 'Registration expiry as declared.'],
            'holder_name' => ['string:255', false, 'Registration holder as declared.'],
        ]],
        'organization' => ['ingest' => false, 'description' => 'A trading partner (supplier or customer) in the source system.', 'fields' => [
            'external_id' => ['string:128', true, 'Source partner key (e.g. SAP vendor/customer number).'],
            'name' => ['string:255', true, 'Legal or trading name.'],
            'role' => ['enum:supplier,customer,both', true, 'Relationship to the organization.'],
            'country_code' => ['country', false, 'ISO 3166-1 alpha-2.'],
            'registration_number' => ['string:64', false, 'Company registration number.'],
            'tax_id' => ['string:64', false, 'Tax identification number.'],
            'licence_number' => ['string:64', false, 'Pharmaceutical premises licence, as declared.'],
        ]],
        'facility' => ['ingest' => false, 'description' => 'A physical site: plant, distribution centre, pharmacy or hospital.', 'fields' => [
            'external_id' => ['string:128', true, 'Source site key (e.g. SAP plant).'],
            'name' => ['string:255', true, 'Site name.'],
            'type' => ['enum:manufacturing_site,distribution_centre,warehouse,pharmacy,hospital,other', true, 'Site type.'],
            'address' => ['string:255', false, 'Street address.'],
            'city' => ['string:100', false, 'City.'],
            'country_code' => ['country', false, 'ISO 3166-1 alpha-2.'],
            'licence_number' => ['string:64', false, 'Premises licence, as declared.'],
        ]],
        'warehouse' => ['ingest' => false, 'description' => 'A stock-holding location within a facility. Mapped to a platform warehouse through an external reference.', 'fields' => [
            'external_id' => ['string:128', true, 'Source location key (e.g. SAP plant/storage location).'],
            'name' => ['string:255', true, 'Location name.'],
            'facility_external_id' => ['string:128', false, 'Source site key.'],
            'type' => ['enum:general,cold_room,quarantine,bonded,retail_store', false, 'Location type.'],
        ]],
        'batch' => ['ingest' => true, 'description' => 'A manufacturer lot of a product, with its expiry. Creates the organization\'s batch record (no stock). An expiry that differs from an existing batch is quarantined, never merged.', 'fields' => [
            'product_external_id' => ['string:128', 'without:gtin', 'Source product key.'],
            'gtin' => ['gtin', 'without:product_external_id', 'Product GTIN, when the source has no product key.'],
            'batch_number' => ['string:64', true, 'Lot / batch number.'],
            'expiry_date' => ['date', true, 'Expiry date.'],
            'manufacturing_date' => ['date', false, 'Manufacturing date.'],
            'quality_status' => ['enum:released,quarantined,rejected', false, 'Quality status in the source system. Quarantine and rejection are applied; a release is only reported, never applied automatically.'],
        ]],
        'serialized_item' => ['ingest' => false, 'description' => 'One serialized trade item (GTIN + serial), where serialization applies.', 'fields' => [
            'gtin' => ['gtin', true, 'GTIN of the trade item.'],
            'serial_number' => ['string:64', true, 'Serial number.'],
            'batch_number' => ['string:64', true, 'Lot / batch number.'],
            'expiry_date' => ['date', true, 'Expiry date.'],
            'status' => ['enum:commissioned,packed,shipped,received,dispensed,decommissioned', true, 'Current status.'],
            'parent_sscc' => ['string:18', false, 'SSCC of the logistic unit containing the item.'],
        ]],
        'inventory_snapshot' => ['ingest' => true, 'description' => 'Stock on hand in the source system at a point in time. Stored for reconciliation against platform stock; never posted to the stock ledger.', 'fields' => [
            'warehouse_code' => ['string:128', true, 'Source location key; resolved by external reference, else by platform warehouse code.'],
            'product_external_id' => ['string:128', 'without:gtin', 'Source product key.'],
            'gtin' => ['gtin', 'without:product_external_id', 'Product GTIN.'],
            'batch_number' => ['string:64', false, 'Omit for stock not tracked by batch.'],
            'quantity' => ['quantity', true, 'Quantity on hand.'],
            'package_level_code' => ['string:32', false, 'Package level of the quantity; base units when omitted.'],
            'as_of' => ['datetime', true, 'When the source system counted this quantity.'],
        ]],
        'inventory_movement' => ['ingest' => false, 'description' => 'A stock movement in the source system.', 'fields' => [
            'external_id' => ['string:128', true, 'Source document/line key.'],
            'warehouse_code' => ['string:128', true, 'Source location key.'],
            'product_external_id' => ['string:128', true, 'Source product key.'],
            'batch_number' => ['string:64', false, 'Lot / batch number.'],
            'movement_type' => ['enum:receipt,issue,transfer_in,transfer_out,adjustment,return', true, 'Kind of movement.'],
            'quantity' => ['decimal', true, 'Signed quantity in base units.'],
            'occurred_at' => ['datetime', true, 'When the movement happened.'],
            'reference' => ['string:128', false, 'Source document number.'],
        ]],
        'purchase_order' => ['ingest' => false, 'description' => 'A purchase order raised in the source system.', 'fields' => [
            'external_id' => ['string:128', true, 'Source PO number.'],
            'supplier_external_id' => ['string:128', true, 'Source supplier key.'],
            'order_date' => ['date', true, 'Order date.'],
            'expected_date' => ['date', false, 'Expected delivery date.'],
            'currency_code' => ['currency', false, 'ISO 4217.'],
            'lines' => ['array', true, 'Order lines.'],
            'lines.*.line_number' => ['string:16', true, 'Source line number.'],
            'lines.*.product_external_id' => ['string:128', true, 'Source product key.'],
            'lines.*.quantity' => ['quantity', true, 'Ordered quantity.'],
            'lines.*.unit' => ['string:32', false, 'Package level of the quantity.'],
            'lines.*.unit_price' => ['quantity', false, 'Price per unit.'],
        ]],
        'sales_order' => ['ingest' => false, 'description' => 'A sales order in the source system.', 'fields' => [
            'external_id' => ['string:128', true, 'Source order number.'],
            'customer_external_id' => ['string:128', true, 'Source customer key.'],
            'order_date' => ['date', true, 'Order date.'],
            'currency_code' => ['currency', false, 'ISO 4217.'],
            'lines' => ['array', true, 'Order lines.'],
            'lines.*.line_number' => ['string:16', true, 'Source line number.'],
            'lines.*.product_external_id' => ['string:128', true, 'Source product key.'],
            'lines.*.quantity' => ['quantity', true, 'Ordered quantity.'],
            'lines.*.unit' => ['string:32', false, 'Package level of the quantity.'],
            'lines.*.unit_price' => ['quantity', false, 'Price per unit.'],
        ]],
        'shipment' => ['ingest' => false, 'description' => 'A dispatch of goods; its items are shipment_item records.', 'fields' => [
            'external_id' => ['string:128', true, 'Source delivery number.'],
            'order_external_id' => ['string:128', false, 'Source order the shipment fulfils.'],
            'ship_from_code' => ['string:128', true, 'Source location key.'],
            'ship_to_external_id' => ['string:128', true, 'Source partner or site key.'],
            'shipped_at' => ['datetime', true, 'Dispatch time.'],
            'carrier' => ['string:100', false, 'Carrier name.'],
            'tracking_reference' => ['string:100', false, 'Carrier tracking reference.'],
        ]],
        'shipment_item' => ['ingest' => false, 'description' => 'One batch line of a shipment.', 'fields' => [
            'shipment_external_id' => ['string:128', true, 'Source delivery number.'],
            'product_external_id' => ['string:128', 'without:gtin', 'Source product key.'],
            'gtin' => ['gtin', 'without:product_external_id', 'Product GTIN.'],
            'batch_number' => ['string:64', true, 'Lot / batch number.'],
            'expiry_date' => ['date', true, 'Expiry date.'],
            'quantity' => ['quantity', true, 'Shipped quantity in base units.'],
            'sscc' => ['string:18', false, 'SSCC of the logistic unit.'],
        ]],
        'receipt' => ['ingest' => false, 'description' => 'A goods receipt in the source system.', 'fields' => [
            'external_id' => ['string:128', true, 'Source receipt number.'],
            'purchase_order_external_id' => ['string:128', false, 'Source PO number.'],
            'warehouse_code' => ['string:128', true, 'Source location key.'],
            'received_at' => ['datetime', true, 'Receipt time.'],
            'lines' => ['array', true, 'Received lines.'],
            'lines.*.product_external_id' => ['string:128', true, 'Source product key.'],
            'lines.*.batch_number' => ['string:64', true, 'Lot / batch number.'],
            'lines.*.expiry_date' => ['date', true, 'Expiry date.'],
            'lines.*.quantity' => ['quantity', true, 'Received quantity in base units.'],
        ]],
        'traceability_event' => ['ingest' => false, 'description' => 'A chain-of-custody event (EPCIS-style). Applied from Phase 7 (traceability).', 'fields' => [
            'event_id' => ['string:128', true, 'Unique event id in the source system.'],
            'event_type' => ['enum:commission,pack,ship,receive,dispense,decommission,destroy,return', true, 'Business step.'],
            'occurred_at' => ['datetime', true, 'Event time.'],
            'location_code' => ['string:128', true, 'Source location key.'],
            'gtin' => ['gtin', false, 'Product GTIN.'],
            'batch_number' => ['string:64', false, 'Lot / batch number.'],
            'serial_numbers' => ['list', false, 'Serials covered by the event.'],
            'quantity' => ['quantity', false, 'Quantity for batch-level events.'],
            'source_document' => ['string:128', false, 'Related document number.'],
        ]],
        'recall_event' => ['ingest' => false, 'description' => 'A product recall notice. Applied from Phase 7 (recall management).', 'fields' => [
            'external_id' => ['string:128', true, 'Source recall id.'],
            'product_external_id' => ['string:128', 'without:gtin', 'Source product key.'],
            'gtin' => ['gtin', 'without:product_external_id', 'Product GTIN.'],
            'batch_numbers' => ['list', true, 'Affected batches.'],
            'recall_class' => ['enum:class_i,class_ii,class_iii', false, 'Severity class.'],
            'reason' => ['string:2000', true, 'Reason for the recall.'],
            'issued_at' => ['datetime', true, 'When the recall was issued.'],
            'authority' => ['string:32', false, 'Issuing authority, if regulatory.'],
        ]],
    ];

    /** @return list<string> */
    public static function entities(): array
    {
        return array_keys(self::ENTITIES);
    }

    /** @return list<string> */
    public static function ingestible(): array
    {
        return array_keys(array_filter(self::ENTITIES, fn ($e) => $e['ingest']));
    }

    public static function exists(string $entity): bool
    {
        return isset(self::ENTITIES[$entity]);
    }

    /** @return array<string, array{0: string, 1: bool|string, 2: string}> */
    public static function fields(string $entity): array
    {
        return self::definition($entity)['fields'];
    }

    /** Top-level field names (no line-item paths), the targets a field mapping may use. */
    public static function topLevelFields(string $entity): array
    {
        return array_values(array_filter(array_keys(self::fields($entity)), fn ($f) => ! str_contains($f, '.')));
    }

    /** Laravel validation rules for one canonical record. */
    public static function rules(string $entity): array
    {
        $rules = [];
        foreach (self::fields($entity) as $field => [$type, $requirement]) {
            $presence = match (true) {
                $requirement === true => ['required'],
                is_string($requirement) && str_starts_with($requirement, 'without:') => ['nullable', 'required_without:'.substr($requirement, 8)],
                default => ['nullable'],
            };
            $rules[$field] = [...$presence, ...self::typeRules($type)];
            if ($type === 'list') {
                $rules[$field.'.*'] = ['string', 'max:128'];
            }
        }
        if (isset($rules['manufacturing_date'], $rules['expiry_date'])) {
            $rules['manufacturing_date'][] = 'before_or_equal:expiry_date';
        }

        return $rules;
    }

    /** The model as documentation (served by GET /integration/canonical-model). */
    public static function describe(): array
    {
        $out = [];
        foreach (self::ENTITIES as $entity => $definition) {
            $out[] = [
                'entity' => $entity,
                'ingestible' => $definition['ingest'],
                'description' => $definition['description'],
                'fields' => array_map(fn ($field, $spec) => [
                    'name' => $field,
                    'type' => $spec[0],
                    'required' => $spec[1] === true ? true : (is_string($spec[1]) ? 'unless '.substr($spec[1], 8).' is given' : false),
                    'description' => $spec[2],
                ], array_keys($definition['fields']), $definition['fields']),
            ];
        }

        return ['version' => self::VERSION, 'entities' => $out];
    }

    private static function definition(string $entity): array
    {
        return self::ENTITIES[$entity] ?? throw new BusinessRuleViolation('entity_unknown', "Unknown canonical entity '{$entity}'.", 422, ['entities' => self::entities()]);
    }

    private static function typeRules(string $type): array
    {
        [$base, $arg] = array_pad(explode(':', $type, 2), 2, null);

        return match ($base) {
            'string' => ['string', 'max:'.$arg],
            'date', 'datetime' => ['date'],
            'decimal' => ['numeric', 'between:-999999999999,999999999999'],
            'quantity' => ['numeric', 'min:0', 'max:999999999999'],
            'integer' => ['integer'],
            'boolean' => ['boolean'],
            'enum' => [Rule::in(explode(',', $arg))],
            'gtin' => ['string', 'regex:/^(\d{8}|\d{12,14})$/'],
            'country' => ['string', 'size:2', 'alpha'],
            'currency' => ['string', 'size:3', 'alpha'],
            'array' => ['array', 'min:1', 'max:1000'],
            'list' => ['array', 'max:10000'],
        };
    }
}

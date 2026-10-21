<?php

namespace App\Domain\Integration\Canonical;

use App\Support\Exceptions\BusinessRuleViolation;

/**
 * Starting-point field mappings for common source systems. Applying one copies
 * its mappings to the connector, where they can be edited. Field names follow
 * the standard objects named in each template; customer systems often add or
 * rename fields, so every template must be checked against the customer's own
 * export or IDoc definition (and with the mapping preview) before go-live.
 */
final class MappingTemplates
{
    private const DATE = ['transform' => 'date', 'options' => ['format' => 'Ymd']];

    private const SAP_DECIMAL = ['transform' => 'decimal', 'options' => ['decimal_separator' => ',', 'thousands_separator' => '.']];

    private const MATNR = ['transform' => 'strip_leading_zeros'];

    public static function all(): array
    {
        return [
            'sap_idoc_matmas' => ['entity' => 'product', 'system' => 'SAP (ECC, S/4HANA)', 'label' => 'SAP IDoc MATMAS (material master)',
                'notes' => 'Segments E1MARAM (MATNR, EAN11 = GTIN of the base unit) and E1MAKTM (MAKTX, first description). Add the registration-number field if the customer keeps it in a Z-segment or classification.',
                'mappings' => [
                    ['target_field' => 'external_id', 'source_path' => 'MATNR'] + self::MATNR,
                    ['target_field' => 'name', 'source_path' => 'E1MAKTM.0.MAKTX'],
                    ['target_field' => 'gtin', 'source_path' => 'EAN11'],
                ]],
            'sap_idoc_batmas' => ['entity' => 'batch', 'system' => 'SAP (ECC, S/4HANA)', 'label' => 'SAP IDoc BATMAS (batch master)',
                'notes' => 'Segment E1BATMAS (MATERIAL, BATCH) with child E1BPBATCHATT (EXPIRYDATE = shelf-life expiry, PROD_DATE). Confirm the segment version in WE60.',
                'mappings' => [
                    ['target_field' => 'product_external_id', 'source_path' => 'MATERIAL'] + self::MATNR,
                    ['target_field' => 'batch_number', 'source_path' => 'BATCH', 'transform' => 'upper'],
                    ['target_field' => 'expiry_date', 'source_path' => 'E1BPBATCHATT.0.EXPIRYDATE'] + self::DATE,
                    ['target_field' => 'manufacturing_date', 'source_path' => 'E1BPBATCHATT.0.PROD_DATE'] + self::DATE,
                ]],
            'sap_table_material' => ['entity' => 'product', 'system' => 'SAP (ECC, S/4HANA)', 'label' => 'SAP material extract (MARA/MAKT/MBEW fields)',
                'notes' => 'Flat file or API extract with MATNR, MAKTX, EAN11, VERPR (moving price, comma decimals) and WAERS. ZZREGNO stands for a customer field holding the NAFDAC number.',
                'mappings' => [
                    ['target_field' => 'external_id', 'source_path' => 'MATNR'] + self::MATNR,
                    ['target_field' => 'name', 'source_path' => 'MAKTX'],
                    ['target_field' => 'gtin', 'source_path' => 'EAN11'],
                    ['target_field' => 'registration_number', 'source_path' => 'ZZREGNO'],
                    ['target_field' => 'registration_authority', 'default_value' => 'NAFDAC'],
                    ['target_field' => 'purchase_cost', 'source_path' => 'VERPR'] + self::SAP_DECIMAL,
                    ['target_field' => 'currency_code', 'source_path' => 'WAERS'],
                ]],
            'sap_table_batch' => ['entity' => 'batch', 'system' => 'SAP (ECC, S/4HANA)', 'label' => 'SAP batch extract (MCH1 fields)',
                'notes' => 'MATNR, CHARG, VFDAT (expiry), HSDAT (production date). ZUSTD stands for a status code mapped F/Q/S to released/quarantined/rejected.',
                'mappings' => [
                    ['target_field' => 'product_external_id', 'source_path' => 'MATNR'] + self::MATNR,
                    ['target_field' => 'batch_number', 'source_path' => 'CHARG', 'transform' => 'upper'],
                    ['target_field' => 'expiry_date', 'source_path' => 'VFDAT'] + self::DATE,
                    ['target_field' => 'manufacturing_date', 'source_path' => 'HSDAT'] + self::DATE,
                    ['target_field' => 'quality_status', 'source_path' => 'ZUSTD', 'transform' => 'map', 'options' => ['values' => ['F' => 'released', 'Q' => 'quarantined', 'S' => 'rejected']]],
                ]],
            'sap_table_stock' => ['entity' => 'inventory_snapshot', 'system' => 'SAP (ECC, S/4HANA)', 'label' => 'SAP batch stock extract (MCHB fields)',
                'notes' => 'LOCATION = plant and storage location (map it to a warehouse), MATNR, CHARG, CLABS (unrestricted stock, comma decimals), MEINS, STAND (count date).',
                'mappings' => [
                    ['target_field' => 'warehouse_code', 'source_path' => 'LOCATION'],
                    ['target_field' => 'product_external_id', 'source_path' => 'MATNR'] + self::MATNR,
                    ['target_field' => 'batch_number', 'source_path' => 'CHARG', 'transform' => 'upper'],
                    ['target_field' => 'quantity', 'source_path' => 'CLABS'] + self::SAP_DECIMAL,
                    ['target_field' => 'as_of', 'source_path' => 'STAND'] + self::DATE,
                ]],
            'dynamics_365_released_products' => ['entity' => 'product', 'system' => 'Microsoft Dynamics 365 Finance & Operations', 'label' => 'Released products (data entity export)',
                'notes' => 'ItemNumber and ProductName from the released products entity. GTINs usually come from a separate barcode entity; add them through a combined export or the mapping.',
                'mappings' => [
                    ['target_field' => 'external_id', 'source_path' => 'ItemNumber'],
                    ['target_field' => 'name', 'source_path' => 'ProductName'],
                    ['target_field' => 'sku', 'source_path' => 'ItemNumber'],
                ]],
            'oracle_ebs_items' => ['entity' => 'product', 'system' => 'Oracle E-Business Suite', 'label' => 'Inventory items (MTL_SYSTEM_ITEMS fields)',
                'notes' => 'SEGMENT1 (item number) and DESCRIPTION. Add GTIN and registration fields from the customer\'s cross-references or descriptive flexfields.',
                'mappings' => [
                    ['target_field' => 'external_id', 'source_path' => 'SEGMENT1'],
                    ['target_field' => 'name', 'source_path' => 'DESCRIPTION'],
                    ['target_field' => 'sku', 'source_path' => 'SEGMENT1'],
                ]],
        ];
    }

    public static function get(string $code): array
    {
        return self::all()[$code] ?? throw new BusinessRuleViolation('mapping_template_unknown', "Unknown mapping template '{$code}'.", 422, ['templates' => array_keys(self::all())]);
    }

    public static function describe(): array
    {
        return array_map(fn ($code, $t) => ['code' => $code] + array_intersect_key($t, array_flip(['entity', 'system', 'label', 'notes', 'mappings'])),
            array_keys(self::all()), self::all());
    }
}

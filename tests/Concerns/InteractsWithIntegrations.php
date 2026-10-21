<?php

namespace Tests\Concerns;

use App\Domain\Catalogue\Models\Product;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\Warehouse;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/** A manufacturer with an integration administrator, a connector and the sample ERP dataset's products. */
trait InteractsWithIntegrations
{
    use InteractsWithInventory;

    /** @return array{0: User, 1: Organization} */
    protected function integrationOrg(): array
    {
        [, $org] = $this->organizationMember(orgAttributes: ['type' => 'manufacturer', 'country_code' => 'NG'], verified: true);
        [$admin, $org] = $this->organizationMember(['integration_admin'], organization: $org);

        return [$admin, $org];
    }

    /** Registers, keys and activates a connector. @return array{0: string, 1: string} connector id and inbound key */
    protected function activeConnector(User $admin, Organization $org, array $attributes = []): array
    {
        $id = $this->actingAsApi($admin, $org)->api('POST', 'integration/connectors', $attributes + [
            'code' => 'S4', 'name' => 'Demo S/4HANA', 'system_type' => 'sap_s4hana', 'system_version' => '2023 FPS02',
            'deployment_model' => 'on_premise', 'inbound_transport' => 'rest_push',
            'interface_notes' => 'Confirmed with the customer: custom REST push from SAP Integration Suite.',
        ])->assertCreated()->json('data.id');
        $key = $this->api('POST', "integration/connectors/{$id}/key")->assertCreated()->json('data.key');
        $this->api('POST', "integration/connectors/{$id}/activate")->assertOk();

        return [$id, $key];
    }

    protected function inbound(string $key, array $messages, array $headers = []): TestResponse
    {
        return $this->postJson('/api/v1/integration/inbound/messages', ['messages' => $messages], ['Authorization' => "Bearer {$key}"] + $headers);
    }

    protected function sampleDataset(): array
    {
        return json_decode(file_get_contents(base_path('tests/fixtures/integration/sample_erp_dataset.json')), true);
    }

    /** The Product Master entries the sample dataset refers to (Amoxicillin by GTIN, Paracetamol by NAFDAC number). */
    protected function sampleProducts(): array
    {
        $amox = $this->makeProduct(['identifiers' => [['type' => 'gtin', 'value' => '6901234000016', 'package_level_code' => 'pack']]]);
        $pcm = $this->makeProduct(['generic_name' => 'Paracetamol', 'brand_name' => 'Calmapyre', 'dosage_form_code' => 'tablet',
            'ingredients' => [['name' => 'Paracetamol', 'strength_value' => 500, 'strength_unit' => 'mg']],
            'regulatory_identifiers' => [['authority_code' => 'NAFDAC', 'country_code' => 'NG', 'identifier_type' => 'registration_number', 'value' => 'A4-0457']]]);

        return [$amox, $pcm];
    }

    /** SAP-style field mappings for the sample dataset. */
    protected function sapMappings(string $connectorId): void
    {
        $matnr = ['source_path' => 'MATNR', 'transform' => 'strip_leading_zeros'];
        $this->api('PUT', "integration/connectors/{$connectorId}/mappings/product", ['mappings' => [
            ['target_field' => 'external_id'] + $matnr,
            ['target_field' => 'name', 'source_path' => 'MAKTX'],
            ['target_field' => 'gtin', 'source_path' => 'EAN11'],
            ['target_field' => 'registration_number', 'source_path' => 'ZZREGNO'],
            ['target_field' => 'registration_authority', 'default_value' => 'NAFDAC'],
            ['target_field' => 'purchase_cost', 'source_path' => 'VERPR', 'transform' => 'decimal', 'options' => ['decimal_separator' => ',', 'thousands_separator' => '.']],
            ['target_field' => 'currency_code', 'source_path' => 'WAERS'],
        ]])->assertOk();
        $this->api('PUT', "integration/connectors/{$connectorId}/mappings/batch", ['mappings' => [
            ['target_field' => 'product_external_id'] + $matnr,
            ['target_field' => 'batch_number', 'source_path' => 'CHARG', 'transform' => 'upper'],
            ['target_field' => 'expiry_date', 'source_path' => 'VFDAT', 'transform' => 'date', 'options' => ['format' => 'Ymd']],
            ['target_field' => 'manufacturing_date', 'source_path' => 'HSDAT', 'transform' => 'date', 'options' => ['format' => 'Ymd']],
            ['target_field' => 'quality_status', 'source_path' => 'ZUSTD', 'transform' => 'map', 'options' => ['values' => ['F' => 'released', 'Q' => 'quarantined', 'S' => 'rejected']]],
        ]])->assertOk();
        $this->api('PUT', "integration/connectors/{$connectorId}/mappings/inventory_snapshot", ['mappings' => [
            ['target_field' => 'warehouse_code', 'source_path' => 'LOCATION'],
            ['target_field' => 'product_external_id'] + $matnr,
            ['target_field' => 'batch_number', 'source_path' => 'CHARG', 'transform' => 'upper'],
            ['target_field' => 'quantity', 'source_path' => 'CLABS', 'transform' => 'decimal', 'options' => ['decimal_separator' => ',', 'thousands_separator' => '.']],
            ['target_field' => 'package_level_code', 'source_path' => 'MEINS', 'transform' => 'map', 'options' => ['values' => ['ST' => 'capsule', 'PAK' => 'pack']]],
            ['target_field' => 'as_of', 'source_path' => 'STAND', 'transform' => 'date', 'options' => ['format' => 'Ymd']],
        ]])->assertOk();
    }

    /** @return list<array> envelopes for one section of the sample dataset */
    protected function envelopes(string $section, string $entity, string $prefix): array
    {
        return array_map(fn ($record, $i) => ['message_id' => "{$prefix}-{$i}", 'entity_type' => $entity, 'record' => $record],
            $this->sampleDataset()[$section], array_keys($this->sampleDataset()[$section]));
    }

    protected function warehouse(Organization $org, string $code = 'MAIN'): Warehouse
    {
        return $this->inTenant($org, fn () => Warehouse::query()->create(['code' => $code, 'name' => "Warehouse {$code}"]));
    }

    protected function messageStatus(string $key, string $messageId): TestResponse
    {
        return $this->getJson('/api/v1/integration/inbound/messages/'.$messageId, ['Authorization' => "Bearer {$key}"]);
    }

    protected function product(string $id): Product
    {
        return Product::query()->findOrFail($id);
    }
}

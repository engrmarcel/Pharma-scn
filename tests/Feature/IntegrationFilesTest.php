<?php

namespace Tests\Feature;

use App\Domain\Catalogue\Models\OrganizationProduct;
use App\Domain\Integration\Files\RemoteDisks;
use App\Domain\Integration\Models\IntegrationConnector;
use App\Domain\Integration\Models\IntegrationMessage;
use App\Domain\Integration\Models\IntegrationRun;
use App\Domain\Inventory\Models\Batch;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithIntegrations;
use Tests\FeatureTestCase;

/**
 * Phase 5b: secure file import and export, SFTP where configured, SAP IDoc
 * intake and mapping templates, on the same durable inbox as the REST API.
 */
class IntegrationFilesTest extends FeatureTestCase
{
    use InteractsWithIntegrations;

    public function test_csv_json_and_xml_files_are_imported_once_through_the_inbox(): void
    {
        [$admin, $org] = $this->integrationOrg();
        $this->sampleProducts();
        [$connectorId, $key] = $this->activeConnector($admin, $org, ['inbound_transport' => 'file_upload']);
        foreach (['product' => 'sap_table_material', 'batch' => 'sap_table_batch'] as $entity => $template) {
            $this->api('POST', "integration/connectors/{$connectorId}/mappings/{$entity}/template", ['template' => $template])->assertOk();
        }

        // Semicolon CSV with a byte-order mark, as Excel writes it.
        $csv = $this->csv($this->sampleDataset()['materials'], ';', bom: true);
        $upload = $this->api('POST', "integration/connectors/{$connectorId}/files", ['file' => UploadedFile::fake()->createWithContent('MAT_20261021.csv', $csv), 'entity_type' => 'product'])
            ->assertStatus(202);
        $this->assertSame([3, 'file', 'product', 'MAT_20261021.csv'], [$upload->json('run.counts.received'), $upload->json('run.source'), $upload->json('run.dataset'), $upload->json('run.file_name')]);
        $this->assertSame(['processed' => 2, 'quarantined' => 1], array_intersect_key($upload->json('run.counts'), ['processed' => 0, 'quarantined' => 0]));
        $this->assertSame(2, $this->inTenant($org, fn () => OrganizationProduct::query()->count()));

        // The same file again is recognised by its content and stores nothing.
        $again = $this->api('POST', "integration/connectors/{$connectorId}/files", ['file' => UploadedFile::fake()->createWithContent('copy.csv', $csv), 'entity_type' => 'product'])->assertStatus(202);
        $this->assertTrue($again->json('duplicate_file'));
        $this->assertSame($upload->json('run.id'), $again->json('run.id'));
        $this->assertSame(3, IntegrationMessage::acrossTenants()->count());

        // The source system pushes a JSON file.
        $json = json_encode(['records' => $this->sampleDataset()['batches']]);
        $pushed = $this->raw($key, 'integration/inbound/files?entity_type=batch&format=json&file_name=BAT_1.json', $json, 'application/json')->assertStatus(202);
        $this->assertSame(['processed' => 2, 'quarantined' => 1], array_intersect_key($pushed->json('run.counts'), ['processed' => 0, 'quarantined' => 0]));

        // XML: the record element is a connector setting.
        $this->api('PATCH', "integration/connectors/{$connectorId}", ['settings' => ['xml_record_element' => 'Batch']])->assertOk();
        $xml = '<?xml version="1.0"?><Batches><Batch><MATNR>000000000000100234</MATNR><CHARG>amx-xml</CHARG><VFDAT>20290630</VFDAT><HSDAT>20260601</HSDAT><ZUSTD>F</ZUSTD></Batch></Batches>';
        $this->api('POST', "integration/connectors/{$connectorId}/files", ['file' => UploadedFile::fake()->createWithContent('batches.xml', $xml), 'entity_type' => 'batch'])
            ->assertStatus(202)->assertJsonPath('run.counts.processed', 1);
        $this->assertSame('2029-06-30', $this->inTenant($org, fn () => Batch::query()->where('batch_number', 'AMX-XML')->first()->expiry_date->toDateString()));

        // Rows of a file get message ids from the file hash and row number, unless the file names its own id column.
        $this->assertStringStartsWith(substr(hash('sha256', $csv), 0, 16).':', IntegrationMessage::acrossTenants()->where('entity_type', 'product')->value('message_id'));
    }

    public function test_unreadable_files_are_rejected_with_a_recorded_run_and_show_in_health(): void
    {
        [$admin, $org] = $this->integrationOrg();
        [$connectorId, $key] = $this->activeConnector($admin, $org);

        $bad = "MATNR,MAKTX\n100234,Amoxicillin\n100235,Paracetamol,extra\n";
        $response = $this->api('POST', "integration/connectors/{$connectorId}/files", ['file' => UploadedFile::fake()->createWithContent('bad.csv', $bad), 'entity_type' => 'product'])
            ->assertStatus(422)->assertJsonPath('error.code', 'file_unreadable')->assertJsonPath('error.details.line', 3);
        $runId = $response->json('error.details.run_id');
        $this->api('GET', "integration/runs/{$runId}")->assertOk()->assertJsonPath('data.state', 'file_rejected');
        $this->assertSame(0, IntegrationMessage::acrossTenants()->count());

        $health = collect($this->api('GET', 'integration/health')->json('data'))->firstWhere('connector_id', $connectorId);
        $this->assertSame('failing', $health['health']);
        $this->assertStringContainsString('bad.csv', $health['last_error']);

        // XML entity attacks are refused before parsing; other formats and settings are checked.
        $this->api('PATCH', "integration/connectors/{$connectorId}", ['settings' => ['xml_record_element' => 'Item']])->assertOk();
        $xxe = '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x SYSTEM "file:///etc/passwd">]><Items><Item><MATNR>&x;</MATNR></Item></Items>';
        $this->raw($key, 'integration/inbound/files?entity_type=product&format=xml', $xxe, 'application/xml')->assertStatus(422)->assertJsonPath('error.code', 'file_unreadable');
        $this->raw($key, 'integration/inbound/files?entity_type=product&format=json', '{"records": [1, 2]}', 'application/json')->assertStatus(422);
        $this->raw($key, 'integration/inbound/files?entity_type=purchase_order&format=json', '[{"a":1}]', 'application/json')->assertStatus(422)->assertJsonPath('error.code', 'entity_not_ingestible');
        $this->raw($key, 'integration/inbound/files?entity_type=product', '[{"a":1}]', 'application/json')->assertStatus(422)->assertJsonPath('error.code', 'file_format_unknown');

        // A readable file clears the transport error.
        $this->raw($key, 'integration/inbound/files?entity_type=product&format=csv', "external_id,name\nX1,Something\n", 'text/csv')->assertStatus(202);
        $this->assertNull(IntegrationConnector::acrossTenants()->find($connectorId)->last_error);
    }

    public function test_sftp_poll_imports_archives_rejects_and_pushes_exports(): void
    {
        [$admin, $org] = $this->integrationOrg();
        [$amox] = $this->sampleProducts();
        $main = $this->warehouse($org);
        [$connectorId] = $this->activeConnector($admin, $org, ['inbound_transport' => 'sftp', 'settings' => ['sftp' => [
            'host' => 'sftp.erp.example', 'host_fingerprint' => 'SHA256:synthetic-test-fingerprint',
            'files' => [['pattern' => 'MAT_*.csv', 'entity_type' => 'product'], ['pattern' => 'BAT_*.csv', 'entity_type' => 'batch']],
            'exports' => [['dataset' => 'inventory', 'path' => 'outbound', 'interval_minutes' => 60]],
        ]]]);
        foreach (['product' => 'sap_table_material', 'batch' => 'sap_table_batch'] as $entity => $template) {
            $this->api('POST', "integration/connectors/{$connectorId}/mappings/{$entity}/template", ['template' => $template])->assertOk();
        }

        // Without a pinned host key, credentials or reachable server the poll refuses and health shows why.
        $this->api('POST', "integration/connectors/{$connectorId}/sftp/poll")->assertStatus(422)->assertJsonPath('error.code', 'sftp_credentials_missing');
        $this->api('PUT', "integration/connectors/{$connectorId}/credentials", ['credentials' => ['sftp_username' => 'pharma', 'sftp_password' => 'not-a-real-password']])->assertOk();

        $disk = Storage::fake('sftp-test');
        $this->app->instance(RemoteDisks::class, new class($disk) extends RemoteDisks
        {
            public function __construct(private readonly Filesystem $disk) {}

            public function for(IntegrationConnector $connector): Filesystem
            {
                return $this->disk;
            }
        });
        $materials = $this->csv($this->sampleDataset()['materials']);
        $disk->put('inbound/MAT_001.csv', $materials);
        $disk->put('inbound/BAT_001.csv', $this->csv($this->sampleDataset()['batches']));
        $disk->put('inbound/MAT_broken.csv', "MATNR,MAKTX\n1,2,3\n");
        $disk->put('inbound/readme.txt', 'not for us');
        $this->inTenant($org, fn () => OrganizationProduct::query()->create(['product_id' => $amox->id, 'sku' => 'AMX', 'currency_code' => 'NGN']));
        $this->receive($org, $main, $amox, 'LOT-SFTP', '500', '+2 years');

        $this->artisan('integration:sftp-poll')->assertSuccessful();

        $this->assertSame(['inbound/readme.txt'], $disk->files('inbound'));
        $this->assertCount(2, $disk->files('archive'));
        $this->assertCount(1, $disk->files('error'));
        $this->assertSame(1, IntegrationRun::acrossTenants()->whereNotNull('error_code')->count());
        $this->assertSame(2, $this->inTenant($org, fn () => Batch::query()->whereIn('batch_number', ['AMX2026A', 'PCM-77'])->count()));

        // The export carries the ERP's own material number for each product.
        $export = $disk->files('outbound');
        $this->assertCount(1, $export);
        $lines = array_map(fn ($l) => str_getcsv($l, ',', '"', ''), array_filter(explode("\n", $disk->get($export[0]))));
        $header = array_shift($lines);
        $row = collect($lines)->map(fn ($l) => array_combine($header, $l))->firstWhere('batch_number', 'LOT-SFTP');
        $this->assertSame(['MAIN', '100234', '6901234000016', '500.0000'], [$row['warehouse_code'], $row['product_external_id'], $row['gtin'], $row['quantity_base_units']]);

        // A copy of an imported file is archived without importing it again; the export is not yet due.
        $disk->put('inbound/MAT_002.csv', $materials);
        $this->api('POST', "integration/connectors/{$connectorId}/sftp/poll")->assertOk()
            ->assertJsonPath('data.duplicates', 1)->assertJsonPath('data.imported', 0)->assertJsonPath('data.exported', 0)->assertJsonPath('data.skipped', 1);
        $this->assertCount(3, $disk->files('archive'));
        $this->assertCount(1, $disk->files('outbound'));
    }

    public function test_sap_idocs_are_received_through_the_adapter_and_mapping_templates(): void
    {
        [$admin, $org] = $this->integrationOrg();
        [$amox] = $this->sampleProducts();
        [$connectorId, $key] = $this->activeConnector($admin, $org, ['inbound_transport' => 'sap_idoc']);
        $this->api('POST', "integration/connectors/{$connectorId}/mappings/product/template", ['template' => 'sap_idoc_matmas'])->assertOk();
        $this->api('POST', "integration/connectors/{$connectorId}/mappings/batch/template", ['template' => 'sap_idoc_batmas'])->assertOk();
        $this->api('POST', "integration/connectors/{$connectorId}/mappings/batch/template", ['template' => 'sap_idoc_matmas'])
            ->assertStatus(422)->assertJsonPath('error.code', 'mapping_template_entity_mismatch');

        $matmas = '<?xml version="1.0" encoding="UTF-8"?>
<MATMAS05>
  <IDOC BEGIN="1">
    <EDI_DC40 SEGMENT="1"><TABNAM>EDI_DC40</TABNAM><DOCNUM>0000000000418201</DOCNUM><IDOCTYP>MATMAS05</IDOCTYP><MESTYP>MATMAS</MESTYP><SNDPRN>S4HCLNT100</SNDPRN></EDI_DC40>
    <E1MARAM SEGMENT="1"><MSGFN>005</MSGFN><MATNR>000000000000100234</MATNR><MEINS>ST</MEINS><EAN11>6901234000016</EAN11>
      <E1MAKTM SEGMENT="1"><SPRAS>E</SPRAS><MAKTX>AMOXICILLIN 500MG CAPS 2X10</MAKTX></E1MAKTM>
    </E1MARAM>
  </IDOC>
  <IDOC BEGIN="1">
    <EDI_DC40 SEGMENT="1"><DOCNUM>0000000000418202</DOCNUM><IDOCTYP>DEBMAS07</IDOCTYP><MESTYP>DEBMAS</MESTYP></EDI_DC40>
    <E1KNA1M SEGMENT="1"><KUNNR>0000004711</KUNNR></E1KNA1M>
  </IDOC>
</MATMAS05>';
        $response = $this->raw($key, 'integration/inbound/idoc', $matmas, 'application/xml')->assertStatus(202);
        $this->assertSame(['IDOC-418201' => 'accepted', 'IDOC-418202' => 'rejected'], collect($response->json('data'))->pluck('status', 'message_id')->all());
        $this->assertSame('idoc_type_unsupported', collect($response->json('data'))->firstWhere('message_id', 'IDOC-418202')['error']['code']);
        $this->assertSame(1, $response->json('run.counts.rejected_at_intake'));

        $ack = $this->messageStatus($key, 'IDOC-418201')->json('data');
        $this->assertSame(['processed', 'gtin', $amox->id], [$ack['status'], $ack['result']['matched_by'], $ack['result']['product_id']]);
        $this->assertSame('AMOXICILLIN 500MG CAPS 2X10', $this->inTenant($org, fn () => OrganizationProduct::query()->where('product_id', $amox->id)->value('local_name')));

        $batmas = '<BATMAS03><IDOC BEGIN="1"><EDI_DC40 SEGMENT="1"><DOCNUM>418300</DOCNUM><IDOCTYP>BATMAS03</IDOCTYP><MESTYP>BATMAS</MESTYP></EDI_DC40>
            <E1BATMAS SEGMENT="1"><MATERIAL>000000000000100234</MATERIAL><BATCH>s4-lot-9</BATCH><PLANT>1000</PLANT>
            <E1BPBATCHATT SEGMENT="1"><EXPIRYDATE>20290331</EXPIRYDATE><PROD_DATE>20260301</PROD_DATE></E1BPBATCHATT></E1BATMAS></IDOC></BATMAS03>';
        $this->raw($key, 'integration/inbound/idoc', $batmas, 'application/xml')->assertStatus(202)->assertJsonPath('data.0.status', 'accepted');
        $batch = $this->inTenant($org, fn () => Batch::query()->where('batch_number', 'S4-LOT-9')->firstOrFail());
        $this->assertSame(['2029-03-31', '2026-03-01'], [$batch->expiry_date->toDateString(), $batch->manufacturing_date->toDateString()]);

        // SAP resends an IDoc after a timeout: the stored outcome, not a second record.
        $this->raw($key, 'integration/inbound/idoc', $batmas, 'application/xml')->assertJsonPath('data.0.duplicate', true)->assertJsonPath('data.0.status', 'processed');
        $this->raw($key, 'integration/inbound/idoc', '<nothing/>', 'application/xml')->assertStatus(422)->assertJsonPath('error.code', 'idoc_empty');

        $templates = collect($this->api('GET', 'integration/mapping-templates')->assertOk()->json('data'))->pluck('entity', 'code');
        $this->assertSame('product', $templates['sap_idoc_matmas']);
        $this->assertTrue($templates->has('dynamics_365_released_products') && $templates->has('oracle_ebs_items'));
    }

    public function test_catalogue_export_is_downloadable_audited_and_safe_for_spreadsheets(): void
    {
        [$admin, $org] = $this->integrationOrg();
        [$amox] = $this->sampleProducts();
        [$connectorId, $key] = $this->activeConnector($admin, $org);
        $this->inbound($key, [['message_id' => 'P-1', 'entity_type' => 'product', 'record' => ['external_id' => 'MAT-77', 'name' => '=HYPERLINK("http://evil.example")', 'gtin' => '6901234000016', 'selling_price' => '12.5']]]);

        $response = $this->actingAsApi($admin, $org)->get('/api/v1/integration/connectors/'.$connectorId.'/exports/catalogue', ['X-Organization-Id' => $org->id])->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $rows = array_map(fn ($l) => str_getcsv($l, ',', '"', ''), array_filter(explode("\n", $response->getContent())));
        $row = array_combine($rows[0], $rows[1]);
        $this->assertSame(['MAT-77', 'MAT-77', '6901234000016', '12.5000'], [$row['sku'], $row['product_external_id'], $row['gtin'], $row['default_selling_price']]);
        $this->assertSame("'=HYPERLINK(\"http://evil.example\")", $row['local_name']);

        $run = IntegrationRun::acrossTenants()->find($response->headers->get('X-Integration-Run-Id'));
        $this->assertSame(['outbound', 'catalogue', 1], [$run->direction, $run->dataset, $run->record_count]);
        $this->assertNotNull($amox);

        [$viewer] = $this->organizationMember(['auditor'], organization: $org);
        $this->actingAsApi($viewer, $org)->api('GET', "integration/connectors/{$connectorId}/exports/inventory")->assertForbidden();
    }

    private function csv(array $records, string $delimiter = ',', bool $bom = false): string
    {
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, array_keys($records[0]), $delimiter, '"', '');
        foreach ($records as $record) {
            fputcsv($stream, array_values($record), $delimiter, '"', '');
        }
        rewind($stream);

        return ($bom ? "\xEF\xBB\xBF" : '').stream_get_contents($stream);
    }

    private function raw(string $key, string $uri, string $body, string $contentType): TestResponse
    {
        return $this->call('POST', '/api/v1/'.$uri, [], [], [],
            $this->transformHeadersToServerVars(['Authorization' => "Bearer {$key}", 'Content-Type' => $contentType, 'Accept' => 'application/json']), $body);
    }
}

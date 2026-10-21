<?php

namespace App\Domain\Integration\Files;

use App\Domain\Integration\Models\IntegrationConnector;
use App\Domain\Integration\Models\IntegrationRun;
use App\Domain\Platform\Services\AuditLogger;
use App\Models\User;
use App\Support\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Facades\DB;

/**
 * Outbound files for an external system (spec §15.2: map permitted outbound
 * fields to the target system). Product columns carry the target system's own
 * key from the connector's external references, next to our SKU and GTIN.
 *
 *   inventory   stock per warehouse, product, batch and state (base units), with expiry
 *   catalogue   the organization's catalogue items with identifiers and prices
 *
 * Cells that a spreadsheet would run as a formula are prefixed with an apostrophe.
 */
class ExportService
{
    public const DATASETS = ['inventory', 'catalogue'];

    /** Per-export lookups (reset on every export). */
    private ?array $externalIds = null;

    private ?array $gtins = null;

    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array{file_name: string, content: string, run: IntegrationRun} */
    public function export(IntegrationConnector $connector, string $dataset, string $destination, ?User $user = null): array
    {
        $this->externalIds = $this->gtins = null;
        $rows = match ($dataset) {
            'inventory' => $this->inventory($connector),
            'catalogue' => $this->catalogue($connector),
            default => throw new BusinessRuleViolation('export_dataset_unknown', 'Datasets: '.implode(', ', self::DATASETS).'.', 422),
        };
        $header = $rows === [] ? $this->emptyHeader($dataset) : array_keys($rows[0]);

        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, $header, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($stream, array_map(fn ($v) => $this->cell($v), array_values($row)), ',', '"', '');
        }
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        $fileName = "{$connector->code}-{$dataset}-".now()->format('Ymd-His').'.csv';
        $run = IntegrationRun::query()->create(['connector_id' => $connector->id, 'source' => $destination, 'direction' => 'outbound', 'dataset' => $dataset,
            'file_name' => $fileName, 'file_sha256' => hash('sha256', $content), 'record_count' => count($rows), 'created_by' => $user?->id, 'started_at' => now()]);
        $this->audit->record('integration.export.created', $run, new: ['dataset' => $dataset, 'records' => count($rows), 'destination' => $destination],
            metadata: ['connector_id' => $connector->id]);

        return ['file_name' => $fileName, 'content' => $content, 'run' => $run];
    }

    private function inventory(IntegrationConnector $connector): array
    {
        return DB::table('inventory_balances as b')
            ->join('batches as t', 't.id', '=', 'b.batch_id')
            ->join('warehouses as w', 'w.id', '=', 'b.warehouse_id')
            ->leftJoin('organization_products as op', fn ($j) => $j->on('op.product_id', '=', 't.product_id')->on('op.organization_id', '=', 'b.organization_id'))
            ->where('b.organization_id', $connector->organization_id)
            ->where('b.quantity', '!=', 0)
            ->orderBy('w.code')->orderBy('op.sku')->orderBy('t.batch_number')->orderBy('b.stock_state')
            ->get(['w.code as warehouse_code', 'op.sku', 't.product_id', 't.batch_number', 't.expiry_date', 'b.stock_state', 'b.quantity', 'b.reserved_quantity'])
            ->map(fn ($r) => [
                'warehouse_code' => $r->warehouse_code,
                'sku' => $r->sku,
                'product_external_id' => $this->externalIds($connector)[$r->product_id] ?? '',
                'gtin' => $this->gtins()[$r->product_id] ?? '',
                'batch_number' => $r->batch_number,
                'expiry_date' => $r->expiry_date,
                'stock_state' => $r->stock_state,
                'quantity_base_units' => $r->quantity,
                'reserved_base_units' => $r->reserved_quantity,
                'as_of' => now()->toIso8601String(),
            ])->all();
    }

    private function catalogue(IntegrationConnector $connector): array
    {
        $registrations = DB::table('regulatory_identifiers')->where('subject_type', 'product')->orderBy('value')->get(['subject_id', 'value'])
            ->groupBy('subject_id')->map(fn ($rows) => $rows->pluck('value')->implode(';'));

        return DB::table('organization_products as op')
            ->join('products as p', 'p.id', '=', 'op.product_id')
            ->where('op.organization_id', $connector->organization_id)
            ->orderBy('op.sku')
            ->get(['op.sku', 'op.product_id', 'p.platform_sku', 'p.display_name', 'op.local_name', 'op.status', 'op.default_selling_price', 'op.currency_code'])
            ->map(fn ($r) => [
                'sku' => $r->sku,
                'product_external_id' => $this->externalIds($connector)[$r->product_id] ?? '',
                'platform_sku' => $r->platform_sku,
                'product_name' => $r->display_name,
                'local_name' => $r->local_name,
                'gtin' => $this->gtins()[$r->product_id] ?? '',
                'registration_numbers' => $registrations[$r->product_id] ?? '',
                'status' => $r->status,
                'default_selling_price' => $r->default_selling_price,
                'currency_code' => $r->currency_code,
            ])->all();
    }

    private function externalIds(IntegrationConnector $connector): array
    {
        return $this->externalIds ??= DB::table('integration_external_references')->where('connector_id', $connector->id)->where('entity_type', 'product')
            ->orderBy('id')->get(['internal_id', 'external_id'])->unique('internal_id')->pluck('external_id', 'internal_id')->all();
    }

    /** Primary GTIN per product (else the first recorded). */
    private function gtins(): array
    {
        return $this->gtins ??= DB::table('product_identifiers')->where('type', 'gtin')->orderByDesc('is_primary')->orderBy('value')->get(['product_id', 'value'])
            ->unique('product_id')->pluck('value', 'product_id')->all();
    }

    private function emptyHeader(string $dataset): array
    {
        return $dataset === 'inventory'
            ? ['warehouse_code', 'sku', 'product_external_id', 'gtin', 'batch_number', 'expiry_date', 'stock_state', 'quantity_base_units', 'reserved_base_units', 'as_of']
            : ['sku', 'product_external_id', 'platform_sku', 'product_name', 'local_name', 'gtin', 'registration_numbers', 'status', 'default_selling_price', 'currency_code'];
    }

    /** CSV injection guard: text that starts like a formula is neutralized; numbers are left alone. */
    private function cell(mixed $value): string
    {
        $value = (string) ($value ?? '');

        return preg_match('/^[=+@\t\r]|^-(?![\d.])/', $value) ? "'".$value : $value;
    }
}

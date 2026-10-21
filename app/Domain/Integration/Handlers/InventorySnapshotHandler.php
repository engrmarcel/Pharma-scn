<?php

namespace App\Domain\Integration\Handlers;

use App\Domain\Integration\Models\IntegrationConnector;
use App\Domain\Integration\Models\IntegrationMessage;
use App\Domain\Integration\Models\InventorySnapshot;
use App\Domain\Integration\Services\ReferenceResolver;
use App\Support\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Carbon;

/**
 * Canonical inventory snapshot → the latest position the source system
 * reported for a warehouse, product and batch. Snapshots feed the inventory
 * reconciliation report; they never change platform stock, which only moves
 * through documented receipts, issues, adjustments and counts.
 */
class InventorySnapshotHandler implements IntegrationHandler
{
    public function __construct(private readonly ReferenceResolver $resolver) {}

    public function handle(IntegrationMessage $message, IntegrationConnector $connector, array $data): array
    {
        $warehouse = $this->resolver->warehouse($connector, $data['warehouse_code']);
        $product = $this->resolver->productForReference($connector, $data);

        $levels = $product->packageLevels()->get();
        $code = $data['package_level_code'] ?? null;
        $level = $code === null ? $levels->firstWhere('level', 0) : $levels->firstWhere('code', $code);
        if ($level === null) {
            throw new BusinessRuleViolation('package_level_unknown', "Unknown package level '{$code}' for this product.", 422,
                ['package_level_code' => $code, 'package_levels' => $levels->pluck('code')->all()]);
        }

        $quantity = bcmul((string) $data['quantity'], (string) $level->base_unit_quantity, 4);
        $asOf = Carbon::parse($data['as_of']);
        $batch = isset($data['batch_number']) ? strtoupper(trim($data['batch_number'])) : '';

        $snapshot = InventorySnapshot::query()->where('connector_id', $connector->id)->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)->where('batch_number', $batch)->lockForUpdate()->first();

        if ($snapshot !== null && $snapshot->as_of->greaterThan($asOf)) {
            return ['action' => 'stale_ignored', 'snapshot_id' => $snapshot->id,
                'warnings' => [['code' => 'older_than_recorded', 'message' => "A newer position (as of {$snapshot->as_of->toIso8601String()}) is already recorded."]]];
        }

        $values = ['quantity' => $quantity, 'as_of' => $asOf, 'inbox_id' => $message->id];
        if ($snapshot === null) {
            $snapshot = InventorySnapshot::query()->create(['connector_id' => $connector->id, 'warehouse_id' => $warehouse->id,
                'product_id' => $product->id, 'batch_number' => $batch] + $values);

            return ['action' => 'created', 'snapshot_id' => $snapshot->id, 'warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'base_quantity' => $quantity];
        }
        $snapshot->fill($values)->save();

        return ['action' => 'updated', 'snapshot_id' => $snapshot->id, 'warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'base_quantity' => $quantity];
    }
}

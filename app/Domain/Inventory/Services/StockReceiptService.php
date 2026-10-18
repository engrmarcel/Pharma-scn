<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Catalogue\Models\OrganizationProduct;
use App\Domain\Inventory\Events\BatchCreated;
use App\Domain\Inventory\Models\Batch;
use App\Domain\Inventory\Support\StockLine;
use App\Domain\Organizations\Models\Warehouse;
use App\Support\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Receives stock into a warehouse with batch capture (spec §10.2, §10.3).
 * Used directly for opening balances and supplier deliveries, and by goods
 * receipts once procurement lands (Phase 2.3).
 *
 * Quantities may be given in any package level of the product; they are
 * converted to base units through the Product Master hierarchy.
 */
class StockReceiptService
{
    public function __construct(private readonly InventoryLedger $ledger) {}

    /**
     * @param  list<array{product_id: string, batch_number: string, expiry_date?: ?string, manufacturing_date?: ?string,
     *                    quantity: string|int|float, package_level_code?: ?string, unit_cost?: ?string, quality_status?: ?string,
     *                    bin_location_id?: ?string}>  $lines
     * @return array{posting_id: string, batches: list<string>}
     */
    public function receive(Warehouse $warehouse, array $lines, ?string $supplierId = null, ?string $referenceType = null, ?string $referenceId = null, ?string $reasonCode = 'receipt'): array
    {
        return DB::transaction(function () use ($warehouse, $lines, $supplierId, $referenceType, $referenceId, $reasonCode) {
            $stockLines = [];
            $batchIds = [];

            foreach ($lines as $i => $line) {
                $item = OrganizationProduct::query()->with('product.packageLevels')->where('product_id', $line['product_id'])->first();
                if ($item === null) {
                    throw new BusinessRuleViolation('product_not_in_catalogue', 'Add the product to your catalogue before receiving stock.', 422, ['line' => $i]);
                }

                $level = $this->packageLevel($item, $line['package_level_code'] ?? null, $i);
                $baseQty = bcmul((string) $line['quantity'], (string) $level->base_unit_quantity, 4);
                if (bccomp($baseQty, '0', 4) <= 0) {
                    throw new BusinessRuleViolation('quantity_invalid', 'Received quantity must be positive.', 422, ['line' => $i]);
                }

                $quality = $line['quality_status'] ?? 'released';
                $batch = $this->resolveBatch($item->product_id, $line, $supplierId, $quality, $i);

                if ($batch->isExpired() && $quality === 'released') {
                    throw new BusinessRuleViolation('expired_on_receipt', "Batch {$batch->batch_number} is already expired; receive it as quarantined if it must be recorded.", 422, ['line' => $i]);
                }

                $unitCost = isset($line['unit_cost']) ? bcdiv((string) $line['unit_cost'], (string) $level->base_unit_quantity, 4) : null;
                if ($unitCost !== null && $batch->unit_cost === null) {
                    $batch->forceFill(['unit_cost' => $unitCost, 'currency_code' => $item->currency_code])->save();
                }

                // Stock of a batch that is not released never lands in the sellable state.
                $state = $batch->quality_status === 'released' && $batch->recall_status === 'none' ? 'available' : 'quarantined';
                $stockLines[] = new StockLine($warehouse->id, $batch->id, $state, $baseQty, 'receipt', $unitCost, $line['bin_location_id'] ?? null);
                $batchIds[] = $batch->id;
            }

            $postingId = $this->ledger->post($stockLines, $reasonCode, $referenceType, $referenceId);

            return ['posting_id' => $postingId, 'batches' => array_values(array_unique($batchIds))];
        });
    }

    private function packageLevel(OrganizationProduct $item, ?string $code, int $line)
    {
        $levels = $item->product->packageLevels;
        $level = $code === null ? $levels->firstWhere('level', 0) : $levels->firstWhere('code', $code);

        if ($level === null) {
            throw new BusinessRuleViolation('package_level_unknown', "Unknown package level '{$code}' for this product.", 422, ['line' => $line]);
        }

        return $level;
    }

    private function resolveBatch(string $productId, array $line, ?string $supplierId, string $quality, int $i): Batch
    {
        $number = strtoupper(trim($line['batch_number']));
        $expiry = isset($line['expiry_date']) ? Carbon::parse($line['expiry_date'])->toDateString() : null;

        $batch = Batch::query()->where('product_id', $productId)->where('batch_number', $number)->lockForUpdate()->first();

        if ($batch !== null) {
            // A lot has one expiry date. A mismatch is a data-entry error or a counterfeit signal, never silently merged.
            if ($expiry !== null && $batch->expiry_date?->toDateString() !== $expiry) {
                throw new BusinessRuleViolation('batch_expiry_mismatch', "Batch {$number} is already recorded with expiry {$batch->expiry_date?->toDateString()}.", 409, ['line' => $i]);
            }

            return $batch;
        }

        $batch = Batch::query()->create([
            'product_id' => $productId,
            'batch_number' => $number,
            'expiry_date' => $expiry,
            'manufacturing_date' => $line['manufacturing_date'] ?? null,
            'supplier_id' => $supplierId,
            'quality_status' => $quality,
        ]);
        event(new BatchCreated($batch->id, $batch->organization_id, $productId));

        return $batch;
    }
}

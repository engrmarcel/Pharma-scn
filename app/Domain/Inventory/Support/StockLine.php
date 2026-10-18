<?php

namespace App\Domain\Inventory\Support;

use InvalidArgumentException;

/**
 * One leg of an inventory posting: a signed base-unit quantity change for a
 * (warehouse, batch, stock state) balance.
 */
final class StockLine
{
    public const STATES = ['available', 'quarantined', 'damaged', 'in_transit'];

    public const TYPES = ['receipt', 'adjustment', 'transfer_out', 'transfer_in', 'state_change', 'sale', 'sale_return', 'count', 'write_off', 'supplier_return'];

    public function __construct(
        public readonly string $warehouseId,
        public readonly string $batchId,
        public readonly string $state,
        public readonly string $quantity,
        public readonly string $movementType,
        public readonly ?string $unitCost = null,
        public readonly ?string $binLocationId = null,
        // Change to reserved_quantity applied atomically with the quantity (e.g. consuming a reservation on sale).
        public readonly string $reservedDelta = '0',
    ) {
        if (! in_array($state, self::STATES, true)) {
            throw new InvalidArgumentException("Unknown stock state {$state}.");
        }
        if (! in_array($movementType, self::TYPES, true)) {
            throw new InvalidArgumentException("Unknown movement type {$movementType}.");
        }
        if (! is_numeric($quantity) || bccomp($quantity, '0', 4) === 0) {
            throw new InvalidArgumentException('Stock line quantity must be a non-zero number.');
        }
    }

    public function key(): string
    {
        return "{$this->warehouseId}|{$this->batchId}|{$this->state}";
    }
}

<?php

namespace App\Domain\Logistics\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Tracking history. Append-only. */
class ShipmentEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'latitude' => 'decimal:7', 'longitude' => 'decimal:7'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Shipment events are append-only.'));
        static::deleting(fn () => throw new LogicException('Shipment events are append-only.'));
    }
}

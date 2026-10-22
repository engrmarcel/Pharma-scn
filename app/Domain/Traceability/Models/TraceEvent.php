<?php

namespace App\Domain\Traceability\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One chain-of-custody event (spec §16.2). Append-only: a mistake is fixed by a
 * correction or reversal event that references this one, never by editing it.
 *
 * Not tenant-scoped: events are shared between the organizations they involve.
 * Every read goes through TraceVisibility.
 */
class TraceEvent extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    public const TYPES = [
        'production', 'batch_registered', 'quality_release', 'quality_rejection', 'quarantine', 'stock_receipt', 'pharmacy_receipt', 'import_receipt',
        'shipment_dispatch', 'transfer_dispatch', 'transfer_receipt', 'return_dispatch', 'return_receipt', 'dispensing_or_sale',
        'stock_adjustment', 'stock_count_correction', 'state_change', 'recall', 'identifiers_commissioned', 'correction', 'reversal',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'event_time' => 'datetime', 'recorded_at' => 'datetime', 'details' => 'array', 'sequence' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Traceability events are append-only. Record a correction instead.'));
        static::deleting(fn () => throw new LogicException('Traceability events are append-only. Record a reversal instead.'));
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(TraceEventVerification::class);
    }
}

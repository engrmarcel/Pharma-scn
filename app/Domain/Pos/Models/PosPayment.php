<?php

namespace App\Domain\Pos\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Electronic payments stay pending until a verified provider callback or a supervisor settlement check confirms them.
 */
class PosPayment extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['confirmed_at' => 'datetime', 'recorded_offline' => 'boolean', 'amount' => 'decimal:4', 'tendered' => 'decimal:4'];
    }

    /**
     * The payment state shown to people (spec §13.5). Cash taken offline is in
     * the drawer but stays "offline cash recorded" until its shift has been
     * counted and reviewed; electronic payments are never confirmed offline.
     */
    public function presentationState(?string $shiftStatus): string
    {
        return match (true) {
            $this->status === 'confirmed' && $this->recorded_offline && $this->method === 'cash' && $shiftStatus !== 'reviewed' => 'offline_cash_recorded',
            $this->status === 'confirmed' => 'confirmed',
            $this->status === 'pending' => 'payment_pending',
            $this->status === 'failed' => 'payment_failed',
            default => $this->status,
        };
    }
}

<?php

namespace App\Domain\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class JournalLine extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['debit' => 'decimal:4', 'credit' => 'decimal:4'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Journal lines are immutable.'));
        static::deleting(fn () => throw new LogicException('Journal lines are immutable.'));
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}

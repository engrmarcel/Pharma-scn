<?php

namespace App\Domain\Finance\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Posted journal entry. Immutable: corrections are reversing entries.
 */
class JournalEntry extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['entry_date' => 'date', 'posted_at' => 'datetime', 'total' => 'decimal:4'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Posted journal entries are immutable; post a reversal instead.'));
        static::deleting(fn () => throw new LogicException('Posted journal entries are immutable; post a reversal instead.'));
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }
}

<?php

namespace App\Domain\Traceability\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A recall of lots of a product across the platform. Not tenant-scoped: it
 * reaches every organization holding the lots. Read access is decided in
 * RecallService (issuer, platform staff, notified organizations).
 */
class Recall extends Model
{
    use HasUlids;

    public const CLASSIFICATIONS = ['class_i', 'class_ii', 'class_iii'];

    public const DEPTHS = ['wholesale', 'retail', 'patient'];

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return ['activated_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function batches(): HasMany
    {
        return $this->hasMany(RecallBatch::class);
    }

    public function notices(): HasMany
    {
        return $this->hasMany(RecallNotice::class);
    }

    /** @return list<string> */
    public function batchNumbers(): array
    {
        return $this->batches()->orderBy('batch_number')->pluck('batch_number')->all();
    }
}

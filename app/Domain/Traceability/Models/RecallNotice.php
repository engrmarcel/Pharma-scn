<?php

namespace App\Domain\Traceability\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The recall as it reaches one organization: notified → acknowledged → completed. */
class RecallNotice extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['notified_at' => 'datetime', 'acknowledged_at' => 'datetime', 'completed_at' => 'datetime',
            'held_at_notice' => 'decimal:4', 'quarantined_at_notice' => 'decimal:4', 'chain_sequence_at_notice' => 'integer'];
    }

    public function recall(): BelongsTo
    {
        return $this->belongsTo(Recall::class);
    }
}

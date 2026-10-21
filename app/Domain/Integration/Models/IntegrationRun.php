<?php

namespace App\Domain\Integration\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One intake call or imported file. Outcome counts are read from its inbox messages. */
class IntegrationRun extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime'];
    }

    public function connector(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnector::class, 'connector_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(IntegrationMessage::class, 'run_id');
    }

    public function summary(): array
    {
        $counts = $this->messages()->toBase()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n);
        $pending = ($counts['received'] ?? 0) + ($counts['processing'] ?? 0);
        $errors = ($counts['quarantined'] ?? 0) + ($counts['failed'] ?? 0);

        return [
            'id' => $this->id,
            'connector_id' => $this->connector_id,
            'source' => $this->source,
            'file_name' => $this->file_name,
            'started_at' => $this->started_at->toIso8601String(),
            'state' => $pending > 0 ? 'processing' : ($errors > 0 ? 'completed_with_errors' : 'completed'),
            'counts' => [
                'received' => $this->received_count,
                'duplicates' => $this->duplicate_count,
                'rejected_at_intake' => $this->rejected_count,
                'pending' => $pending,
                'processed' => $counts['processed'] ?? 0,
                'quarantined' => $counts['quarantined'] ?? 0,
                'failed' => $counts['failed'] ?? 0,
                'ignored' => $counts['ignored'] ?? 0,
            ],
        ];
    }
}

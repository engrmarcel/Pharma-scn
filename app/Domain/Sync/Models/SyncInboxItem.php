<?php

namespace App\Domain\Sync\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class SyncInboxItem extends Model
{
    use BelongsToOrganization;

    protected $table = 'sync_inbox';

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'received', 'attempts' => 0];

    /** Warnings collected by a handler during processing (stored with the acknowledgement). */
    public array $pendingWarnings = [];

    protected function casts(): array
    {
        return ['payload' => 'array', 'result' => 'array', 'warnings' => 'array', 'error_details' => 'array',
            'client_created_at' => 'datetime', 'received_at' => 'datetime', 'processed_at' => 'datetime', 'resolved_at' => 'datetime'];
    }

    public function isFinal(): bool
    {
        return in_array($this->status, ['synced', 'conflict', 'rejected'], true);
    }

    /** The acknowledgement returned to the device (identical on every replay). */
    public function acknowledgement(bool $duplicate = false): array
    {
        return array_filter([
            'client_txn_id' => $this->client_txn_id,
            'status' => $this->status === 'received' ? 'retry_required' : $this->status,
            'received_at' => $this->received_at?->toIso8601String(),
            'processed_at' => $this->processed_at?->toIso8601String(),
            'result' => $this->result,
            'warnings' => $this->warnings ?: null,
            'error' => $this->error_code ? ['code' => $this->error_code, 'message' => $this->error_message, 'details' => $this->error_details] : null,
            'duplicate' => $duplicate ?: null,
        ], fn ($v) => $v !== null);
    }
}

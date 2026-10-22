<?php

namespace App\Domain\Traceability\Services;

use App\Domain\Traceability\Models\TraceEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Appends events to an organization's hash chain (spec §16.6: tamper-evident
 * controls). Each event stores the hash of the previous one and its own hash
 * over its content, so editing or removing a past event outside the
 * application breaks the chain, which verify() reports. A blockchain is not
 * used; the chain head is a locked row per organization.
 */
class TraceChain
{
    /** Fields covered by the hash, in this order. */
    private const HASHED = ['id', 'organization_id', 'sequence', 'event_type', 'product_id', 'batch_number', 'serial', 'quantity',
        'source_organization_id', 'destination_organization_id', 'source_location', 'destination_location', 'counterparty_name',
        'related_type', 'related_id', 'event_time', 'recorded_at', 'corrects_event_id', 'reverses_event_id', 'correction_reason', 'prev_hash'];

    public function append(string $organizationId, array $event): TraceEvent
    {
        return DB::transaction(function () use ($organizationId, $event) {
            DB::table('trace_chain_heads')->insertOrIgnore(['organization_id' => $organizationId, 'sequence' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $head = DB::table('trace_chain_heads')->where('organization_id', $organizationId)->lockForUpdate()->first();

            $row = $event + [
                'id' => strtolower((string) Str::ulid()),
                'organization_id' => $organizationId,
                'sequence' => $head->sequence + 1,
                'recorded_at' => now(),
                'actor_user_id' => Auth::id(),
                'prev_hash' => $head->hash,
            ];
            $row['event_time'] ??= $row['recorded_at'];
            $row['quantity'] = isset($row['quantity']) ? bcadd((string) $row['quantity'], '0', 4) : null;
            $row['hash'] = $this->hash($row);
            if (Context::get('correlation_id')) {
                $row['details'] = ($row['details'] ?? []) + ['correlation_id' => Context::get('correlation_id')];
            }

            $saved = TraceEvent::query()->create($row);
            DB::table('trace_chain_heads')->where('organization_id', $organizationId)
                ->update(['sequence' => $row['sequence'], 'hash' => $row['hash'], 'updated_at' => now()]);

            return $saved;
        });
    }

    /**
     * Recomputes an organization's chain.
     *
     * @return array{events: int, intact: bool, first_broken_sequence: ?int}
     */
    public function verify(string $organizationId): array
    {
        $previous = null;
        $count = 0;
        $broken = null;
        foreach (TraceEvent::query()->where('organization_id', $organizationId)->orderBy('sequence')->cursor() as $event) {
            $count++;
            $row = $event->getAttributes();
            $expectedSequence = $count;
            if ($broken === null && ($row['prev_hash'] !== $previous || (int) $row['sequence'] !== $expectedSequence || $this->hash($row) !== $row['hash'])) {
                $broken = (int) $row['sequence'];
            }
            $previous = $row['hash'];
        }

        return ['events' => $count, 'intact' => $broken === null, 'first_broken_sequence' => $broken];
    }

    private function hash(array $row): string
    {
        $parts = [];
        foreach (self::HASHED as $field) {
            $value = $row[$field] ?? null;
            if ($value instanceof \DateTimeInterface) {
                $value = $value->format('Y-m-d H:i:s');
            } elseif (in_array($field, ['event_time', 'recorded_at'], true) && $value !== null) {
                $value = Carbon::parse($value)->format('Y-m-d H:i:s');
            } elseif ($field === 'quantity' && $value !== null) {
                $value = bcadd((string) $value, '0', 4);
            }
            $parts[$field] = $value === null ? null : (string) $value;
        }

        return hash('sha256', json_encode($parts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}

<?php

namespace App\Domain\Sync\Services;

use App\Domain\Sync\Handlers\FieldForceSyncHandler;
use App\Domain\Sync\Handlers\PosSaleSyncHandler;
use App\Domain\Sync\Models\Device;
use App\Domain\Sync\Models\SyncInboxItem;
use App\Models\User;
use App\Support\Exceptions\BusinessRuleViolation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The server side of the sync contract (spec §13.3; docs/OFFLINE_FIRST_DESIGN.md).
 *
 * Each envelope is first written to sync_inbox (durable, unique per
 * organization and client_txn_id), then processed in its own transaction,
 * in the order the device sent them. The acknowledgement says what happened:
 *
 *   synced          applied; result carries the server's ids and statuses
 *   conflict        refused because the server's state disagrees (409 rules); kept for review
 *   rejected        refused as invalid or not permitted; kept for review
 *   retry_required  stored but not processed (temporary failure); send again later
 *
 * A replayed client_txn_id returns the stored acknowledgement and is never applied twice.
 */
class SyncProcessor
{
    public function __construct(
        private readonly FieldForceSyncHandler $field,
        private readonly PosSaleSyncHandler $pos,
    ) {}

    /** @return list<array> acknowledgements in request order */
    public function process(Device $device, User $user, array $envelopes): array
    {
        $acks = array_map(fn (array $e) => $this->one($device, $user, $e), $envelopes);
        $device->forceFill(['last_sync_at' => now()])->saveQuietly();

        return $acks;
    }

    private function one(Device $device, User $user, array $e): array
    {
        $existing = SyncInboxItem::query()->where('client_txn_id', $e['client_txn_id'])->first();
        if ($existing !== null && $existing->device_id !== $device->id) {
            return ['client_txn_id' => $e['client_txn_id'], 'status' => 'rejected', 'error' => ['code' => 'client_txn_id_conflict', 'message' => 'This transaction id was already used by another device.']];
        }
        if ($existing !== null && $existing->isFinal()) {
            return $existing->acknowledgement(duplicate: true);
        }

        $item = $existing ?? $this->store($device, $user, $e);
        if ($item->isFinal()) {
            return $item->acknowledgement(duplicate: true); // lost a race with a parallel request
        }
        $item->forceFill(['attempts' => $item->attempts + 1, 'client_attempt' => $e['attempt'] ?? $item->client_attempt])->save();

        try {
            $this->assertProcessable($item);
            $result = DB::transaction(fn () => $this->dispatch($item));
            $item->forceFill(['status' => 'synced', 'result' => $result, 'warnings' => $item->pendingWarnings ?: null, 'processed_at' => now(),
                'error_code' => null, 'error_message' => null, 'error_details' => null])->save();
        } catch (BusinessRuleViolation $ex) {
            $this->finish($item, $ex->status === 409 ? 'conflict' : 'rejected', $ex->errorCode, $ex->getMessage(), $ex->details ?: null);
        } catch (ValidationException $ex) {
            $this->finish($item, 'rejected', 'validation_failed', $ex->getMessage(), ['errors' => $ex->errors()]);
        } catch (AuthorizationException $ex) {
            $this->finish($item, 'rejected', 'forbidden', 'You are not allowed to perform this action.');
        } catch (ModelNotFoundException) {
            $this->finish($item, 'rejected', 'not_found', 'A record referenced by this transaction was not found.');
        } catch (Throwable $ex) {
            report($ex);
            if ($item->attempts >= config('sync.max_processing_attempts')) {
                $this->finish($item, 'rejected', 'processing_error', 'The server could not process this transaction. It has been kept for review.');
            }
            // Otherwise it stays "received": the device is told to retry later.
        }

        return $item->fresh()->acknowledgement();
    }

    private function store(Device $device, User $user, array $e): SyncInboxItem
    {
        try {
            return SyncInboxItem::query()->create([
                'device_id' => $device->id,
                'user_id' => $user->id,
                'client_txn_id' => $e['client_txn_id'],
                'type' => $e['type'],
                'schema_version' => (int) $e['schema_version'],
                'client_created_at' => Carbon::parse($e['client_created_at']),
                'received_at' => now(),
                'client_attempt' => $e['attempt'] ?? null,
                'payload' => $e['payload'],
            ]);
        } catch (UniqueConstraintViolationException) {
            return SyncInboxItem::query()->where('client_txn_id', $e['client_txn_id'])->firstOrFail();
        }
    }

    private function assertProcessable(SyncInboxItem $item): void
    {
        if (! in_array($item->schema_version, config('sync.types')[$item->type] ?? [], true)) {
            throw new BusinessRuleViolation('unsupported_envelope', "Unsupported transaction type or schema version ({$item->type} v{$item->schema_version}). Update the app.", 422);
        }
        if ($item->client_created_at->greaterThan(now()->addMinutes(config('field_force.max_clock_skew_minutes')))) {
            throw new BusinessRuleViolation('device_clock_ahead', 'The transaction time is in the future. Check the device clock.', 422);
        }
    }

    private function dispatch(SyncInboxItem $item): array
    {
        return str_starts_with($item->type, 'field.') ? $this->field->handle($item) : $this->pos->handle($item);
    }

    private function finish(SyncInboxItem $item, string $status, string $code, string $message, ?array $details = null): void
    {
        $item->forceFill(['status' => $status, 'error_code' => $code, 'error_message' => $message, 'error_details' => $details, 'processed_at' => now()])->save();
    }
}

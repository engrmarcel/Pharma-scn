<?php

namespace App\Domain\Integration\Services;

use App\Domain\Integration\Canonical\CanonicalModel;
use App\Domain\Integration\Handlers\BatchHandler;
use App\Domain\Integration\Handlers\IntegrationHandler;
use App\Domain\Integration\Handlers\InventorySnapshotHandler;
use App\Domain\Integration\Handlers\ProductHandler;
use App\Domain\Integration\Jobs\ProcessIntegrationMessage;
use App\Domain\Integration\Models\IntegrationMessage;
use App\Support\Exceptions\BusinessRuleViolation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Processes one inbox message: field mapping → canonical validation →
 * business service, in its own transaction (spec §15.3).
 *
 *   data problems (validation, unmatched references, business rules) → quarantined, with an actionable error
 *   unexpected errors → retried with backoff; after max_attempts → failed (dead letter)
 *
 * Error messages from unexpected exceptions are never stored or shown (they
 * can contain SQL or payload values); they go to the application log.
 */
class IntegrationProcessor
{
    private const HANDLERS = [
        'product' => ProductHandler::class,
        'batch' => BatchHandler::class,
        'inventory_snapshot' => InventorySnapshotHandler::class,
    ];

    public function __construct(private readonly FieldMapper $mapper) {}

    public function process(int $id): void
    {
        // Claim the message: only one worker may hold it.
        $claimed = IntegrationMessage::query()->whereKey($id)->where('status', 'received')
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->update(['status' => 'processing', 'processing_started_at' => now(), 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);
        if ($claimed !== 1) {
            return;
        }

        $message = IntegrationMessage::query()->with('connector')->findOrFail($id);
        $connector = $message->connector;
        if ($connector->status !== 'active') {
            // Paused or disabled after intake: hold the message; it is picked up when the connector is resumed.
            $message->forceFill(['status' => 'received', 'attempts' => max(0, $message->attempts - 1)])->save();

            return;
        }

        try {
            $canonical = $this->mapper->map($connector, $message->entity_type, $message->payload);
            $message->canonical = $canonical;
            Validator::make($canonical, CanonicalModel::rules($message->entity_type))->validate();

            $handler = $this->handler($message->entity_type);
            $result = DB::transaction(fn () => $handler->handle($message, $connector, $canonical));

            $message->forceFill(['status' => 'processed', 'result' => $result, 'processed_at' => now(), 'next_attempt_at' => null,
                'error_code' => null, 'error_message' => null, 'error_details' => null])->save();
            $connector->forceFill(['last_processed_at' => now()])->saveQuietly();
        } catch (BusinessRuleViolation $e) {
            $this->quarantine($message, $e->errorCode, $e->getMessage(), $e->details ?: null);
        } catch (ValidationException $e) {
            $errors = $this->mapper->explain($connector, $message->entity_type, $e->errors());
            $first = $errors[0];
            $this->quarantine($message, 'validation_failed', "The record does not satisfy the canonical {$message->entity_type} model: {$first['messages'][0]}"
                .(isset($first['hint']) ? ' '.$first['hint'] : ''), ['errors' => $errors]);
        } catch (ModelNotFoundException) {
            $this->quarantine($message, 'not_found', 'A record this message refers to no longer exists.');
        } catch (Throwable $e) {
            report($e);
            $this->retryLater($message);
        }
    }

    private function handler(string $entity): IntegrationHandler
    {
        return app(self::HANDLERS[$entity] ?? throw new BusinessRuleViolation('entity_not_ingestible', "{$entity} records are not applied by the gateway yet."));
    }

    private function quarantine(IntegrationMessage $message, string $code, string $text, ?array $details = null): void
    {
        $message->forceFill(['status' => 'quarantined', 'error_code' => $code, 'error_message' => $text, 'error_details' => $details,
            'processed_at' => now(), 'next_attempt_at' => null])->save();
        $message->connector->forceFill(['last_failure_at' => now()])->saveQuietly();
    }

    private function retryLater(IntegrationMessage $message): void
    {
        $message->connector->forceFill(['last_failure_at' => now()])->saveQuietly();

        if ($message->attempts >= config('integrations.max_attempts')) {
            $message->forceFill(['status' => 'failed', 'error_code' => 'processing_error', 'next_attempt_at' => null, 'processed_at' => now(),
                'error_message' => "Processing failed on {$message->attempts} attempts. The message is kept; retry it once the cause is resolved (see the operations log for the correlation id)."])->save();

            return;
        }

        $backoff = config('integrations.retry_backoff_minutes');
        $minutes = $backoff[min($message->attempts - 1, count($backoff) - 1)];
        $message->forceFill(['status' => 'received', 'next_attempt_at' => now()->addMinutes($minutes),
            'error_code' => 'retry_scheduled', 'error_message' => "A temporary error occurred. Attempt {$message->attempts} failed; the next attempt is scheduled."])->save();
        ProcessIntegrationMessage::dispatch($message->id)->delay($message->next_attempt_at);
    }
}

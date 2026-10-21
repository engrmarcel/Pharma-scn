<?php

namespace App\Domain\Integration\Services;

use App\Domain\Catalogue\Enums\ProductStatus;
use App\Domain\Catalogue\Models\Product;
use App\Domain\Integration\Jobs\ProcessIntegrationMessage;
use App\Domain\Integration\Models\IntegrationMessage;
use App\Domain\Organizations\Models\Warehouse;
use App\Domain\Platform\Services\AuditLogger;
use App\Models\User;
use App\Support\Exceptions\BusinessRuleViolation;

/**
 * Failed-message review (spec §15.4): retry, ignore, or fix a reference and retry.
 * A retry re-runs mapping and validation from the stored source record, so a
 * corrected mapping applies; it never duplicates a business transaction,
 * because handlers are idempotent and a processed message is never retried.
 */
class MessageReviewService
{
    public function __construct(
        private readonly ReferenceResolver $resolver,
        private readonly AuditLogger $audit,
    ) {}

    public function retry(IntegrationMessage $message, User $actor): IntegrationMessage
    {
        if (! in_array($message->status, ['quarantined', 'failed', 'received'], true)) {
            throw new BusinessRuleViolation('message_not_retryable', "A {$message->status} message cannot be retried.", 409);
        }
        if ($message->connector->status !== 'active') {
            throw new BusinessRuleViolation('connector_not_active', 'Activate the connector before retrying its messages.', 409);
        }

        $before = $message->only(['status', 'error_code']);
        $message->forceFill(['status' => 'received', 'next_attempt_at' => null, 'attempts' => 0])->save();
        $this->audit->record('integration.message.retried', $message, $before, ['status' => 'received'], ['message_id' => $message->message_id]);
        ProcessIntegrationMessage::dispatch($message->id);

        return $message->fresh();
    }

    public function ignore(IntegrationMessage $message, User $actor, string $reason): IntegrationMessage
    {
        if (! in_array($message->status, ['quarantined', 'failed', 'received'], true)) {
            throw new BusinessRuleViolation('message_not_ignorable', "A {$message->status} message cannot be ignored.", 409);
        }

        $before = $message->only(['status', 'error_code']);
        $message->forceFill(['status' => 'ignored', 'resolved_by' => $actor->id, 'resolved_at' => now(), 'resolution_notes' => $reason, 'next_attempt_at' => null])->save();
        $this->audit->record('integration.message.ignored', $message, $before, ['status' => 'ignored'], ['message_id' => $message->message_id, 'reason' => $reason]);

        return $message;
    }

    /**
     * Records the reference the message was missing (its product or warehouse), then retries it.
     *
     * @param  array{product_id?: string, warehouse_id?: string}  $target
     */
    public function map(IntegrationMessage $message, User $actor, array $target): IntegrationMessage
    {
        if (! in_array($message->status, ['quarantined', 'failed'], true)) {
            throw new BusinessRuleViolation('message_not_mappable', 'Only quarantined or failed messages can be mapped.', 409);
        }
        $data = $message->canonical ?? [];

        if (isset($target['warehouse_id'])) {
            $code = $data['warehouse_code'] ?? throw new BusinessRuleViolation('mapping_key_missing', 'This message has no warehouse_code to map.', 422);
            $warehouse = Warehouse::query()->findOrFail($target['warehouse_id']);
            $this->resolver->remember($message->connector, 'warehouse', (string) $code, 'warehouse', $warehouse->id, 'manual', $actor->id);
            $this->audit->record('integration.reference.mapped', $message, new: ['entity_type' => 'warehouse', 'external_id' => $code, 'warehouse_id' => $warehouse->id]);
        }

        if (isset($target['product_id'])) {
            $external = $message->entity_type === 'product' ? ($data['external_id'] ?? null) : ($data['product_external_id'] ?? null);
            if ($external === null || $external === '') {
                throw new BusinessRuleViolation('mapping_key_missing', 'This message identifies its product only by GTIN. Add the GTIN to the product through a catalogue change request instead.', 422);
            }
            $product = Product::query()->findOrFail($target['product_id']);
            if ($product->status === ProductStatus::UnderReview) {
                throw new BusinessRuleViolation('product_not_approved', 'Map to an approved product.', 422);
            }
            $this->resolver->remember($message->connector, 'product', (string) $external, 'product', $product->id, 'manual', $actor->id);
            $this->audit->record('integration.reference.mapped', $message, new: ['entity_type' => 'product', 'external_id' => $external, 'product_id' => $product->id]);
        }

        return $this->retry($message, $actor);
    }
}

<?php

namespace App\Domain\Integration\Handlers;

use App\Domain\Catalogue\Models\OrganizationProduct;
use App\Domain\Integration\Models\IntegrationConnector;
use App\Domain\Integration\Models\IntegrationMessage;
use App\Domain\Integration\Services\ReferenceResolver;
use App\Domain\Inventory\Events\BatchCreated;
use App\Domain\Inventory\Models\Batch;
use App\Domain\Inventory\Services\BatchQualityService;
use App\Domain\Platform\Services\AuditLogger;
use App\Support\Events\DomainEvents;
use App\Support\Exceptions\BusinessRuleViolation;

/**
 * Canonical batch → the organization's batch record (no stock).
 *
 * A lot has one expiry date: a record that disagrees with an existing batch
 * is quarantined, never merged. A quarantine or rejection in the source
 * system is applied (it only makes stock less available); a release is
 * reported back as a warning and left for a person, because releasing stock
 * for sale is a quality decision the platform does not take automatically.
 */
class BatchHandler implements IntegrationHandler
{
    public function __construct(
        private readonly ReferenceResolver $resolver,
        private readonly BatchQualityService $quality,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(IntegrationMessage $message, IntegrationConnector $connector, array $data): array
    {
        $product = $this->resolver->productForReference($connector, $data);
        if (! OrganizationProduct::query()->where('product_id', $product->id)->exists()) {
            throw new BusinessRuleViolation('product_not_in_catalogue', 'The product is not in your catalogue. Send its product record first, then retry.', 422,
                ['product_id' => $product->id]);
        }

        $number = strtoupper(trim($data['batch_number']));
        $expiry = substr((string) $data['expiry_date'], 0, 10);
        $desired = $data['quality_status'] ?? null;
        $warnings = [];

        $batch = Batch::query()->where('product_id', $product->id)->where('batch_number', $number)->lockForUpdate()->first();

        if ($batch === null) {
            $batch = Batch::query()->create([
                'product_id' => $product->id,
                'batch_number' => $number,
                'expiry_date' => $expiry,
                'manufacturing_date' => $data['manufacturing_date'] ?? null,
                'quality_status' => $desired ?? 'released',
                'notes' => "Created from {$connector->name}",
            ]);
            DomainEvents::raise(new BatchCreated($batch->id, $batch->organization_id, $product->id));
            $this->audit->record('integration.batch.created', $batch, new: ['batch_number' => $number, 'expiry_date' => $expiry, 'quality_status' => $batch->quality_status],
                metadata: ['connector_id' => $connector->id, 'message_id' => $message->message_id]);

            return ['action' => 'created', 'batch_id' => $batch->id, 'product_id' => $product->id];
        }

        if ($batch->expiry_date?->toDateString() !== $expiry) {
            throw new BusinessRuleViolation('batch_expiry_mismatch',
                "Batch {$number} is recorded with expiry {$batch->expiry_date?->toDateString()}, but this record says {$expiry}. A lot has one expiry date: check the source record before resending.", 409,
                ['batch_id' => $batch->id, 'recorded_expiry' => $batch->expiry_date?->toDateString(), 'received_expiry' => $expiry]);
        }

        $action = 'unchanged';
        if (! empty($data['manufacturing_date']) && $batch->manufacturing_date === null) {
            $batch->forceFill(['manufacturing_date' => $data['manufacturing_date']])->save();
            $action = 'updated';
        }

        if ($desired !== null && $desired !== $batch->quality_status) {
            $reason = "Reported by {$connector->name} (message {$message->message_id})";
            if ($desired === 'released') {
                $warnings[] = ['code' => 'release_not_applied', 'message' => "The source system released batch {$number}; it stays {$batch->quality_status} here until someone releases it."];
            } else {
                $desired === 'quarantined' ? $this->quality->quarantine($batch, $reason) : $this->quality->reject($batch, $reason);
                $action = 'updated';
            }
        }

        return array_filter(['action' => $action, 'batch_id' => $batch->id, 'product_id' => $product->id, 'warnings' => $warnings ?: null]);
    }
}

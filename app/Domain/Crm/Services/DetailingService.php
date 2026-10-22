<?php

namespace App\Domain\Crm\Services;

use App\Domain\Catalogue\Models\OrganizationProduct;
use App\Domain\Crm\Models\DetailingMessage;
use App\Domain\Crm\Models\DetailingRecord;
use App\Domain\FieldForce\Models\FieldVisit;
use App\Domain\FieldForce\Services\FieldForceScope;
use App\Domain\Platform\Services\AuditLogger;
use App\Models\User;
use App\Support\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Product detailing (spec §17). Promotional claims about medicines must come
 * from approved material, so:
 *   - key messages are written as drafts and approved by someone other than their author;
 *   - a representative can record only approved, unexpired messages of the product detailed;
 *   - detailing is recorded against the representative's own visit (in progress, or
 *     completed within the last 24 hours, for notes written up after leaving).
 * Samples handed over are recorded (quantity, batch) for accountability; they
 * are not issued from stock here.
 */
class DetailingService
{
    public function __construct(
        private readonly FieldForceScope $scope,
        private readonly ContactService $contacts,
        private readonly AuditLogger $audit,
    ) {}

    public function createMessage(array $data, User $author): DetailingMessage
    {
        $this->assertInCatalogue($data['product_id']);
        $message = DetailingMessage::query()->create($data + ['status' => 'draft', 'created_by' => $author->id]);
        $this->audit->record('detailing_message.created', $message, new: $data);

        return $message;
    }

    public function updateMessage(DetailingMessage $message, array $data): DetailingMessage
    {
        if ($message->status !== 'draft') {
            throw new BusinessRuleViolation('detailing_message_locked', 'Approved or retired messages cannot be edited. Retire it and write a new one.', 409);
        }
        $original = $message->getAttributes();
        $message->fill($data)->save();
        [$old, $new] = $this->audit->diff($message, $original);
        $this->audit->record('detailing_message.updated', $message, $old, $new);

        return $message;
    }

    public function approve(DetailingMessage $message, User $approver): DetailingMessage
    {
        if ($message->status !== 'draft') {
            throw new BusinessRuleViolation('detailing_message_not_draft', "A {$message->status} message cannot be approved.", 409);
        }
        if ($message->created_by === $approver->id) {
            throw new BusinessRuleViolation('approval_segregation', 'A key message must be approved by someone other than its author.', 403);
        }
        $message->forceFill(['status' => 'approved', 'approved_by' => $approver->id, 'approved_at' => now()])->save();
        $this->audit->record('detailing_message.approved', $message, ['status' => 'draft'], ['status' => 'approved', 'approval_reference' => $message->approval_reference]);

        return $message;
    }

    public function retire(DetailingMessage $message): DetailingMessage
    {
        if ($message->status === 'retired') {
            throw new BusinessRuleViolation('detailing_message_retired', 'The message is already retired.', 409);
        }
        $before = $message->status;
        $message->forceFill(['status' => 'retired'])->save();
        $this->audit->record('detailing_message.retired', $message, ['status' => $before], ['status' => 'retired']);

        return $message;
    }

    /** @return array{record: DetailingRecord, replayed: bool} */
    public function record(FieldVisit $visit, array $data): array
    {
        return DB::transaction(function () use ($visit, $data) {
            if (! empty($data['client_reference']) && ($existing = DetailingRecord::query()->where('client_reference', $data['client_reference'])->first())) {
                if ($existing->representative_id !== Auth::id()) {
                    throw new BusinessRuleViolation('client_reference_conflict', 'This client reference is already used by another record.', 409);
                }

                return ['record' => $existing, 'replayed' => true];
            }

            $locked = FieldVisit::query()->lockForUpdate()->findOrFail($visit->id);
            if ($locked->representative_id !== Auth::id()) {
                throw new BusinessRuleViolation('visit_not_yours', 'Only the representative who made the visit can record its detailing.', 403);
            }
            if ($locked->status === 'cancelled' || ($locked->status === 'completed' && $locked->check_out_at?->lt(now()->subDay()))) {
                throw new BusinessRuleViolation('visit_closed', 'Detailing can be recorded during the visit or within 24 hours of checking out.', 409);
            }
            $this->assertInCatalogue($data['product_id']);

            $messages = DetailingMessage::query()->whereIn('id', $data['message_ids'])->get();
            $unusable = collect($data['message_ids'])->diff($messages->filter(fn ($m) => $m->product_id === $data['product_id'] && $m->isUsable())->pluck('id'));
            if ($unusable->isNotEmpty()) {
                throw new BusinessRuleViolation('detailing_message_not_approved', 'Only approved, current key messages for this product can be recorded.', 422,
                    ['message_ids' => $unusable->values()->all()]);
            }
            $customer = $this->scope->visibleCustomer($locked->customer_id);
            $contact = $this->contacts->contactOf($customer, $data['contact_id'] ?? null);

            $record = DetailingRecord::query()->create([
                'client_reference' => $data['client_reference'] ?? null, 'field_visit_id' => $locked->id, 'customer_id' => $customer->id,
                'contact_id' => $contact?->id, 'representative_id' => Auth::id(), 'product_id' => $data['product_id'],
                'message_ids' => array_values(array_unique($data['message_ids'])), 'samples_quantity' => $data['samples_quantity'] ?? null,
                'sample_batch_number' => isset($data['sample_batch_number']) ? strtoupper(trim($data['sample_batch_number'])) : null,
                'reception' => $data['reception'] ?? 'not_assessed', 'notes' => $data['notes'] ?? null, 'recorded_at' => now(),
            ]);
            $this->audit->record('detailing.recorded', $record, new: ['visit_id' => $locked->id, 'product_id' => $record->product_id,
                'messages' => count($record->message_ids), 'samples_quantity' => $record->samples_quantity]);

            return ['record' => $record, 'replayed' => false];
        });
    }

    private function assertInCatalogue(string $productId): void
    {
        if (! OrganizationProduct::query()->where('product_id', $productId)->exists()) {
            throw new BusinessRuleViolation('product_not_in_catalogue', 'The product is not in your catalogue.', 422);
        }
    }
}

<?php

namespace App\Domain\Crm\Services;

use App\Domain\Crm\Models\CrmInteraction;
use App\Domain\Crm\Models\CustomerFeedback;
use App\Domain\FieldForce\Models\FieldVisit;
use App\Domain\FieldForce\Services\FieldForceScope;
use App\Domain\Platform\Services\AuditLogger;
use App\Support\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Interactions and feedback recorded by field users for customers they can
 * see. Device client references make offline replays idempotent.
 */
class InteractionService
{
    public function __construct(
        private readonly FieldForceScope $scope,
        private readonly ContactService $contacts,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{interaction: CrmInteraction, replayed: bool} */
    public function log(array $data): array
    {
        return DB::transaction(function () use ($data) {
            if (($existing = $this->replay(CrmInteraction::class, $data, 'representative_id')) !== null) {
                return ['interaction' => $existing, 'replayed' => true];
            }
            $customer = $this->scope->visibleCustomer($data['customer_id']);
            $contact = $this->contacts->contactOf($customer, $data['contact_id'] ?? null);
            if ($contact !== null && $contact->contact_consent === 'withdrawn' && in_array($data['channel'], CrmInteraction::PERSONAL_CHANNELS, true)
                && $data['direction'] === 'outbound') {
                throw new BusinessRuleViolation('contact_consent_withdrawn', 'This contact has withdrawn consent to be contacted directly. Do not call, e-mail or message them.', 409);
            }
            $occurred = Carbon::parse($data['occurred_at']);
            if ($occurred->isAfter(now()->addMinutes(config('field_force.max_clock_skew_minutes')))) {
                throw new BusinessRuleViolation('occurred_in_future', 'The interaction time is in the future.', 422);
            }

            $interaction = CrmInteraction::query()->create([
                'client_reference' => $data['client_reference'] ?? null, 'customer_id' => $customer->id, 'contact_id' => $contact?->id,
                'representative_id' => Auth::id(), 'field_visit_id' => $this->ownVisit($data['field_visit_id'] ?? null, $customer->id),
                'channel' => $data['channel'], 'direction' => $data['direction'], 'subject' => $data['subject'], 'summary' => $data['summary'] ?? null,
                'occurred_at' => $occurred,
            ]);
            $this->audit->record('crm_interaction.logged', $interaction, new: ['customer_id' => $customer->id, 'channel' => $interaction->channel]);

            return ['interaction' => $interaction, 'replayed' => false];
        });
    }

    /** @return array{feedback: CustomerFeedback, replayed: bool} */
    public function recordFeedback(array $data): array
    {
        return DB::transaction(function () use ($data) {
            if (($existing = $this->replay(CustomerFeedback::class, $data, 'recorded_by')) !== null) {
                return ['feedback' => $existing, 'replayed' => true];
            }
            $customer = $this->scope->visibleCustomer($data['customer_id']);
            $contact = $this->contacts->contactOf($customer, $data['contact_id'] ?? null);

            $feedback = CustomerFeedback::query()->create([
                'client_reference' => $data['client_reference'] ?? null, 'customer_id' => $customer->id, 'contact_id' => $contact?->id,
                'field_visit_id' => $this->ownVisit($data['field_visit_id'] ?? null, $customer->id), 'product_id' => $data['product_id'] ?? null,
                'category' => $data['category'], 'rating' => $data['rating'] ?? null, 'description' => $data['description'],
                'possible_adverse_event' => (bool) ($data['possible_adverse_event'] ?? false), 'recorded_by' => Auth::id(),
            ]);
            $this->audit->record($feedback->possible_adverse_event ? 'customer_feedback.possible_adverse_event' : 'customer_feedback.recorded', $feedback,
                new: ['customer_id' => $customer->id, 'category' => $feedback->category, 'product_id' => $feedback->product_id]);

            return ['feedback' => $feedback, 'replayed' => false];
        });
    }

    /** in_review or resolved, by a holder of crm.feedback.manage who can see the customer. */
    public function progressFeedback(CustomerFeedback $feedback, string $status, ?string $resolution, ?string $pvReference): CustomerFeedback
    {
        Gate::authorize('crm.feedback.manage');

        return DB::transaction(function () use ($feedback, $status, $resolution, $pvReference) {
            $locked = CustomerFeedback::query()->lockForUpdate()->findOrFail($feedback->id);
            if ($locked->status === 'resolved') {
                throw new BusinessRuleViolation('feedback_resolved', 'This feedback is already resolved.', 409);
            }
            if ($status === 'resolved' && $locked->possible_adverse_event && blank($pvReference)) {
                throw new BusinessRuleViolation('pharmacovigilance_reference_required',
                    'Possible adverse events are resolved only after they have been reported to pharmacovigilance. Enter the safety case reference.', 422);
            }
            $before = $locked->status;
            $locked->forceFill(['status' => $status] + ($status === 'resolved'
                ? ['resolution' => $resolution, 'pharmacovigilance_reference' => $pvReference, 'resolved_by' => Auth::id(), 'resolved_at' => now()]
                : []))->save();
            $this->audit->record("customer_feedback.{$status}", $locked, ['status' => $before], ['status' => $status, 'pharmacovigilance_reference' => $pvReference]);

            return $locked;
        });
    }

    /** A replayed client reference returns the stored record; another user's reference is a conflict. */
    private function replay(string $model, array $data, string $ownerColumn): mixed
    {
        if (empty($data['client_reference']) || ($existing = $model::query()->where('client_reference', $data['client_reference'])->first()) === null) {
            return null;
        }
        if ((int) $existing->{$ownerColumn} !== (int) Auth::id()) {
            throw new BusinessRuleViolation('client_reference_conflict', 'This client reference is already used by another record.', 409);
        }

        return $existing;
    }

    private function ownVisit(?string $visitId, string $customerId): ?string
    {
        if ($visitId === null) {
            return null;
        }
        $visit = FieldVisit::query()->whereKey($visitId)->where('representative_id', Auth::id())->where('customer_id', $customerId)->first();
        if ($visit === null) {
            throw new BusinessRuleViolation('visit_not_found', 'The visit is not one of your visits to this customer.', 422);
        }

        return $visit->id;
    }
}

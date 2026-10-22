<?php

namespace App\Domain\FieldForce\Services;

use App\Domain\Crm\Services\VisitPlanService;
use App\Domain\FieldForce\Models\FieldFollowUp;
use App\Domain\FieldForce\Models\FieldVisit;
use App\Domain\Inventory\Services\DocumentNumberer;
use App\Domain\Platform\Services\AuditLogger;
use App\Support\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Customer visits (spec §12.2–12.3): check in → log → check out.
 * Only the representative who checked in may change a visit, and the
 * location evidence of a check-in or check-out is never edited afterwards.
 * A replayed check-in (same client_reference) returns the stored visit.
 */
class VisitService
{
    public const DETAIL_FIELDS = ['objectives', 'discussion_notes', 'products_discussed', 'customer_feedback', 'competitor_information',
        'order_opportunity', 'outcome', 'next_action', 'follow_up_date'];

    public function __construct(
        private readonly FieldForceScope $scope,
        private readonly LocationEvidence $evidence,
        private readonly DocumentNumberer $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{client_reference: string, customer_id: string, visit_type: string, check_in: array, objectives?: ?string, captured_offline?: bool}  $data
     * @return array{visit: FieldVisit, replayed: bool}
     */
    public function checkIn(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $existing = FieldVisit::query()->where('client_reference', $data['client_reference'])->first();
            if ($existing !== null) {
                if ($existing->representative_id !== Auth::id()) {
                    throw new BusinessRuleViolation('client_reference_conflict', 'This client reference is already used by another record.', 409);
                }

                return ['visit' => $existing, 'replayed' => true];
            }

            $customer = $this->scope->visibleCustomer($data['customer_id']);
            if ($customer->status !== 'active') {
                throw new BusinessRuleViolation('customer_inactive', "The customer is {$customer->status}.", 409);
            }
            // Serialize per representative so two devices cannot open parallel visits.
            DB::table('users')->where('id', Auth::id())->lockForUpdate()->first();
            $open = FieldVisit::query()->where('representative_id', Auth::id())->where('status', 'in_progress')->first();
            if ($open !== null) {
                throw new BusinessRuleViolation('visit_in_progress', "Check out of visit {$open->number} first.", 409, ['visit_id' => $open->id]);
            }

            $visit = FieldVisit::query()->create([
                'number' => $this->numbers->next('VIS'),
                'client_reference' => $data['client_reference'],
                'representative_id' => Auth::id(),
                'customer_id' => $customer->id,
                'territory_id' => $customer->territory_id,
                'visit_type' => $data['visit_type'],
                'objectives' => $data['objectives'] ?? null,
                'captured_offline' => (bool) ($data['captured_offline'] ?? false),
            ] + $this->evidence->evaluate('check_in', $data['check_in'], $customer));

            // A planned call for this customer today is now fulfilled.
            app(VisitPlanService::class)->linkVisit($visit);

            $this->audit->record('field_visit.checked_in', $visit, new: [
                'number' => $visit->number, 'customer_id' => $customer->id, 'location_status' => $visit->check_in_location_status, 'verification' => $visit->check_in_verification,
            ]);

            return ['visit' => $visit, 'replayed' => false];
        });
    }

    public function updateDetails(FieldVisit $visit, array $details): FieldVisit
    {
        return DB::transaction(function () use ($visit, $details) {
            $locked = $this->own($visit);
            if ($locked->status !== 'in_progress') {
                throw new BusinessRuleViolation('visit_closed', "A {$locked->status} visit cannot be edited.", 409);
            }
            $locked->fill($this->details($details))->save();

            return $locked;
        });
    }

    /** @param list<array{activity_type: string, product_id?: ?string, notes?: ?string, recorded_at?: ?string}> $activities */
    public function addActivities(FieldVisit $visit, array $activities): FieldVisit
    {
        return DB::transaction(function () use ($visit, $activities) {
            $locked = $this->own($visit);
            if ($locked->status !== 'in_progress') {
                throw new BusinessRuleViolation('visit_closed', "A {$locked->status} visit cannot be edited.", 409);
            }
            $this->writeActivities($locked, $activities);

            return $locked->load('activities');
        });
    }

    /**
     * @param  array{check_out: array, outcome: string, activities?: array, ...}  $data
     */
    public function checkOut(FieldVisit $visit, array $data): FieldVisit
    {
        return DB::transaction(function () use ($visit, $data) {
            $locked = $this->own($visit);
            if ($locked->status === 'completed' && $locked->check_out_at !== null) {
                return $locked->load('activities'); // replay of an already-synced check-out
            }
            if ($locked->status !== 'in_progress') {
                throw new BusinessRuleViolation('visit_closed', "A {$locked->status} visit cannot be checked out.", 409);
            }

            $customer = $this->scope->visibleCustomer($locked->customer_id);
            $evidence = $this->evidence->evaluate('check_out', $data['check_out'], $customer);
            if ($evidence['check_out_at']->lessThan($locked->check_in_at)) {
                throw new BusinessRuleViolation('check_out_before_check_in', 'Check-out time is earlier than the check-in time.', 422);
            }

            $this->writeActivities($locked, $data['activities'] ?? []);
            $locked->fill($this->details($data) + $evidence + ['status' => 'completed'])->save();

            if ($locked->follow_up_date !== null) {
                FieldFollowUp::query()->create([
                    'customer_id' => $locked->customer_id, 'field_visit_id' => $locked->id, 'assigned_to' => Auth::id(), 'created_by' => Auth::id(),
                    'title' => mb_strimwidth($locked->next_action ?: "Follow up on visit {$locked->number}", 0, 255),
                    'due_on' => $locked->follow_up_date, 'source' => 'visit',
                ]);
            } elseif (in_array($locked->outcome, ['customer_unavailable', 'rescheduled'], true)) {
                // Follow-up automation: an unfinished call is never silently dropped.
                FieldFollowUp::query()->firstOrCreate(['automation_key' => "visit_outcome:{$locked->id}"], [
                    'customer_id' => $locked->customer_id, 'field_visit_id' => $locked->id, 'assigned_to' => Auth::id(), 'created_by' => null,
                    'title' => $locked->outcome === 'rescheduled' ? "Rescheduled visit {$locked->number}: arrange a new date" : "Customer unavailable at visit {$locked->number}: visit again",
                    'due_on' => today()->addWeekday()->toDateString(), 'source' => 'automation',
                ]);
            }

            $this->audit->record('field_visit.checked_out', $locked, new: [
                'outcome' => $locked->outcome, 'location_status' => $locked->check_out_location_status, 'verification' => $locked->check_out_verification,
            ]);

            return $locked->load('activities');
        });
    }

    public function cancel(FieldVisit $visit, string $reason): FieldVisit
    {
        return DB::transaction(function () use ($visit, $reason) {
            $locked = $this->own($visit);
            if ($locked->status !== 'in_progress') {
                throw new BusinessRuleViolation('visit_closed', "A {$locked->status} visit cannot be cancelled.", 409);
            }
            $locked->forceFill(['status' => 'cancelled'])->save();
            $this->audit->record('field_visit.cancelled', $locked, new: ['reason' => $reason]);

            return $locked;
        });
    }

    private function own(FieldVisit $visit): FieldVisit
    {
        $locked = FieldVisit::query()->lockForUpdate()->findOrFail($visit->id);
        if ($locked->representative_id !== Auth::id()) {
            throw new BusinessRuleViolation('visit_not_yours', 'Only the representative who checked in can change this visit.', 403);
        }

        return $locked;
    }

    private function details(array $data): array
    {
        $details = Arr::only($data, self::DETAIL_FIELDS);
        if (filled($details['competitor_information'] ?? null) && ! Gate::allows('field_force.competitor.record')) {
            throw new BusinessRuleViolation('competitor_information_not_allowed', 'You are not authorized to record competitor information.', 403);
        }

        return $details;
    }

    private function writeActivities(FieldVisit $visit, array $activities): void
    {
        foreach ($activities as $a) {
            $visit->activities()->create([
                'activity_type' => $a['activity_type'],
                'product_id' => $a['product_id'] ?? null,
                'notes' => $a['notes'] ?? null,
                'recorded_at' => isset($a['recorded_at']) ? $this->evidence->occurredAt($a['recorded_at']) : now(),
            ]);
        }
    }
}

<?php

namespace App\Domain\FieldForce\Services;

use App\Domain\FieldForce\Models\FieldFollowUp;
use App\Domain\Platform\Services\AuditLogger;
use App\Support\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Follow-up tasks for visible customers. A representative assigns tasks to
 * self; supervisors may assign within their visible team.
 */
class FollowUpService
{
    public function __construct(private readonly FieldForceScope $scope, private readonly AuditLogger $audit) {}

    /** @return array{follow_up: FieldFollowUp, replayed: bool} */
    public function create(array $data): array
    {
        return DB::transaction(function () use ($data) {
            if (isset($data['client_reference']) && ($existing = FieldFollowUp::query()->where('client_reference', $data['client_reference'])->first())) {
                if ($existing->created_by !== Auth::id()) {
                    throw new BusinessRuleViolation('client_reference_conflict', 'This client reference is already used by another record.', 409);
                }

                return ['follow_up' => $existing, 'replayed' => true];
            }

            $customer = $this->scope->visibleCustomer($data['customer_id']);
            $assignee = (int) ($data['assigned_to'] ?? Auth::id());
            if ($assignee !== (int) Auth::id() && ! $this->scope->canSeeRepresentative($assignee)) {
                throw new BusinessRuleViolation('assignee_not_allowed', 'You can assign follow-ups only to yourself or your team.', 403);
            }

            $followUp = FieldFollowUp::query()->create([
                'client_reference' => $data['client_reference'] ?? null, 'customer_id' => $customer->id, 'field_visit_id' => $data['field_visit_id'] ?? null,
                'assigned_to' => $assignee, 'created_by' => Auth::id(), 'title' => $data['title'], 'notes' => $data['notes'] ?? null, 'due_on' => $data['due_on'],
            ]);
            $this->audit->record('field_follow_up.created', $followUp, new: ['assigned_to' => $assignee, 'due_on' => $data['due_on']]);

            return ['follow_up' => $followUp, 'replayed' => false];
        });
    }

    public function close(FieldFollowUp $followUp, string $status, ?string $outcome): FieldFollowUp
    {
        return DB::transaction(function () use ($followUp, $status, $outcome) {
            $locked = FieldFollowUp::query()->lockForUpdate()->findOrFail($followUp->id);
            if ($locked->assigned_to !== Auth::id() && $locked->created_by !== Auth::id()) {
                throw new BusinessRuleViolation('follow_up_not_yours', 'Only the assignee or creator can close this follow-up.', 403);
            }
            if ($locked->status !== 'open') {
                throw new BusinessRuleViolation('follow_up_closed', "The follow-up is already {$locked->status}.", 409);
            }
            $locked->forceFill(['status' => $status, 'completed_at' => now(), 'outcome' => $outcome])->save();
            $this->audit->record("field_follow_up.{$status}", $locked, new: ['outcome' => $outcome]);

            return $locked;
        });
    }
}

<?php

namespace App\Domain\Organizations\Services;

use App\Domain\Organizations\Enums\VerificationStatus;
use App\Domain\Organizations\Events\OrganizationVerificationChanged;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Models\OrganizationVerification;
use App\Domain\Platform\Services\AuditLogger;
use App\Models\User;
use App\Support\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Facades\DB;

/**
 * Platform due-diligence workflow for organizations:
 *
 *   unverified / changes_requested / rejected / revoked --submit--> pending_review
 *   pending_review --approve--> verified
 *   pending_review --reject--> rejected
 *   pending_review --request_changes--> changes_requested
 *   verified --revoke--> revoked
 *
 * "Verified" means the platform reviewed the submitted evidence. It is not,
 * and must never be shown as, approval by a regulator.
 */
class OrganizationVerificationService
{
    private const DECISIONS = [
        'approve' => VerificationStatus::Verified,
        'reject' => VerificationStatus::Rejected,
        'request_changes' => VerificationStatus::ChangesRequested,
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    public function submit(Organization $organization, User $user): Organization
    {
        return DB::transaction(function () use ($organization, $user) {
            $org = $this->lock($organization);

            if (! $org->verification_status->canSubmit()) {
                throw new BusinessRuleViolation('verification_transition_invalid', "Cannot submit for verification from status {$org->verification_status->value}.", 409);
            }

            $required = $org->country?->setting('verification.required_document_categories', []) ?? [];
            $present = $org->documents()->pluck('category')->unique()->all();
            $missing = array_values(array_diff($required, $present));

            if ($present === [] || $missing !== []) {
                throw new BusinessRuleViolation('verification_documents_missing', 'Upload the required verification documents before submitting.', 422, ['missing_categories' => $missing]);
            }

            return $this->transition($org, 'submitted', VerificationStatus::PendingReview, $user, null);
        });
    }

    public function decide(Organization $organization, string $decision, User $reviewer, ?string $notes): Organization
    {
        if (! isset(self::DECISIONS[$decision])) {
            throw new BusinessRuleViolation('verification_decision_invalid', "Unknown decision {$decision}.");
        }
        if ($decision !== 'approve' && blank($notes)) {
            throw new BusinessRuleViolation('verification_notes_required', 'Notes are required when rejecting or requesting changes.');
        }

        return DB::transaction(function () use ($organization, $decision, $reviewer, $notes) {
            $org = $this->lock($organization);
            $this->assertNoConflict($org, $reviewer);

            if (! $org->verification_status->canBeDecided()) {
                throw new BusinessRuleViolation('verification_transition_invalid', "Organization is not pending review (status {$org->verification_status->value}).", 409);
            }

            $action = ['approve' => 'approved', 'reject' => 'rejected', 'request_changes' => 'changes_requested'][$decision];

            return $this->transition($org, $action, self::DECISIONS[$decision], $reviewer, $notes);
        });
    }

    public function revoke(Organization $organization, User $reviewer, string $notes): Organization
    {
        return DB::transaction(function () use ($organization, $reviewer, $notes) {
            $org = $this->lock($organization);
            $this->assertNoConflict($org, $reviewer);

            if ($org->verification_status !== VerificationStatus::Verified) {
                throw new BusinessRuleViolation('verification_transition_invalid', 'Only verified organizations can have verification revoked.', 409);
            }

            return $this->transition($org, 'revoked', VerificationStatus::Revoked, $reviewer, $notes);
        });
    }

    private function lock(Organization $organization): Organization
    {
        return Organization::query()->lockForUpdate()->findOrFail($organization->id);
    }

    private function assertNoConflict(Organization $org, User $reviewer): void
    {
        if (OrganizationMembership::query()->where('organization_id', $org->id)->where('user_id', $reviewer->id)->exists()) {
            throw new BusinessRuleViolation('review_conflict_of_interest', 'Reviewers cannot decide on an organization they belong to.', 403);
        }
    }

    private function transition(Organization $org, string $action, VerificationStatus $to, User $actor, ?string $notes): Organization
    {
        $from = $org->verification_status;

        OrganizationVerification::query()->create([
            'organization_id' => $org->id,
            'action' => $action,
            'from_status' => $from->value,
            'to_status' => $to->value,
            'notes' => $notes,
            'actor_user_id' => $actor->id,
        ]);

        $org->forceFill([
            'verification_status' => $to,
            'verified_at' => $to === VerificationStatus::Verified ? now() : ($to === VerificationStatus::Revoked ? null : $org->verified_at),
        ])->save();

        $this->audit->record("organization.verification_{$action}", $org,
            ['verification_status' => $from->value], ['verification_status' => $to->value], ['notes' => $notes], $org->id);
        event(new OrganizationVerificationChanged($org->id, $from->value, $to->value));

        return $org;
    }
}

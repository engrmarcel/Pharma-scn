<?php

namespace App\Domain\Catalogue\Services;

use App\Domain\Catalogue\Enums\RegulatoryVerificationStatus;
use App\Domain\Catalogue\Events\RegulatoryIdentifierUpdated;
use App\Domain\Catalogue\Models\RegulatoryIdentifier;
use App\Domain\Catalogue\Models\RegulatoryVerification;
use App\Domain\Platform\Models\RegulatoryAuthority;
use App\Domain\Platform\Models\RegulatoryIdentifierType;
use App\Domain\Platform\Services\AuditLogger;
use App\Models\User;
use App\Support\Events\DomainEvents;
use App\Support\Exceptions\BusinessRuleViolation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class RegulatoryIdentifierService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Attach a declared identifier to a product, manufacturer or organization.
     * It starts unverified regardless of what the submitter declared.
     */
    public function attach(Model $subject, array $data, User $actor, ?string $submittedByOrganizationId = null): RegulatoryIdentifier
    {
        $authority = RegulatoryAuthority::query()->where('code', $data['authority_code'])->firstOrFail();
        $value = RegulatoryIdentifier::normalizeValue($data['value']);
        $type = RegulatoryIdentifierType::query()->where('authority_id', $authority->id)->where('identifier_type', $data['identifier_type'])->where('is_active', true)->first();
        if ($type === null) {
            throw new BusinessRuleViolation('identifier_type_not_configured', "{$authority->code} has no {$data['identifier_type']} configured.", 422,
                ['configured' => RegulatoryIdentifierType::query()->where('authority_id', $authority->id)->where('is_active', true)->pluck('identifier_type')->all()]);
        }
        if (! $type->appliesTo($subject->getMorphClass())) {
            throw new BusinessRuleViolation('identifier_type_not_applicable', "A {$type->name} does not identify a {$subject->getMorphClass()}.", 422, ['applies_to' => $type->subject_types]);
        }
        if (! RegulatoryIdentifier::isSynthetic($value) && ! $type->matchesFormat($value)) {
            throw new BusinessRuleViolation('identifier_format_invalid', "This does not look like a {$type->name}.", 422, array_filter(['example' => $type->format_example]));
        }

        if (RegulatoryIdentifier::isSynthetic($value) && app()->isProduction()) {
            throw new BusinessRuleViolation('synthetic_identifier_rejected', 'Identifiers prefixed DEMO- or TEST- are reserved for synthetic data and cannot be used in production.');
        }

        $identifier = RegulatoryIdentifier::query()->firstOrNew([
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'authority_id' => $authority->id,
            'identifier_type' => $data['identifier_type'],
            'value' => $value,
        ]);

        $isNew = ! $identifier->exists;
        $identifier->fill([
            'country_code' => $authority->country_code,
            'declared_status' => $data['declared_status'] ?? $identifier->declared_status ?? 'unknown',
            'registration_date' => $data['registration_date'] ?? $identifier->registration_date,
            'expiry_date' => $data['expiry_date'] ?? $identifier->expiry_date,
            'holder_name' => $data['holder_name'] ?? $identifier->holder_name,
        ]);

        if ($isNew) {
            $identifier->verification_status = RegulatoryVerificationStatus::Unverified;
            $identifier->submitted_by_organization_id = $submittedByOrganizationId;
            $identifier->created_by = $actor->id;
        }
        $identifier->save();

        $this->audit->record($isNew ? 'regulatory_identifier.added' : 'regulatory_identifier.updated', $identifier,
            new: $identifier->only(['authority_id', 'identifier_type', 'value', 'declared_status', 'registration_date', 'expiry_date']),
            metadata: ['subject_type' => $subject->getMorphClass(), 'subject_id' => $subject->getKey()],
            organizationId: false);
        DomainEvents::raise(new RegulatoryIdentifierUpdated($identifier->id, $identifier->subject_type, $identifier->subject_id, $isNew ? 'added' : 'updated', $identifier->verification_status->value));

        return $identifier;
    }

    /**
     * Record the outcome of a platform verification check. History is
     * append-only; the identifier's status reflects the latest result.
     */
    public function recordVerification(RegulatoryIdentifier $identifier, array $data, User $verifier): RegulatoryVerification
    {
        if ($identifier->created_by === $verifier->id) {
            throw new BusinessRuleViolation('verification_conflict_of_interest', 'An identifier cannot be verified by the person who submitted it.', 403);
        }
        // Synthetic data must never be labelled as genuinely verified (spec §29.2, §32).
        if (RegulatoryIdentifier::isSynthetic($identifier->value) && $data['result'] === 'verified') {
            throw new BusinessRuleViolation('synthetic_identifier_unverifiable', 'Synthetic (DEMO-/TEST-) identifiers cannot be marked verified.');
        }

        return DB::transaction(function () use ($identifier, $data, $verifier) {
            $locked = RegulatoryIdentifier::query()->lockForUpdate()->findOrFail($identifier->id);
            $previous = $locked->verification_status;

            $verification = RegulatoryVerification::query()->create([
                'regulatory_identifier_id' => $locked->id,
                'method' => $data['method'],
                'source' => $data['source'] ?? null,
                'result' => $data['result'],
                'notes' => $data['notes'] ?? null,
                'evidence_attachment_id' => $data['evidence_attachment_id'] ?? null,
                'verified_by' => $verifier->id,
                'verified_at' => $data['verified_at'] ?? now(),
            ]);

            $locked->verification_status = RegulatoryVerificationStatus::fromResult($data['result']);
            $locked->last_verified_at = $verification->verified_at;
            $locked->last_verification_id = $verification->id;
            $locked->save();

            $this->audit->record('regulatory_identifier.verification_recorded', $locked,
                ['verification_status' => $previous->value],
                ['verification_status' => $locked->verification_status->value, 'method' => $data['method'], 'result' => $data['result']],
                organizationId: false);
            DomainEvents::raise(new RegulatoryIdentifierUpdated($locked->id, $locked->subject_type, $locked->subject_id, 'verification_recorded', $locked->verification_status->value));

            return $verification;
        });
    }
}

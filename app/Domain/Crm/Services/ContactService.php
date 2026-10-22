<?php

namespace App\Domain\Crm\Services;

use App\Domain\Parties\Models\Customer;
use App\Domain\Parties\Models\PartyContact;
use App\Domain\Platform\Services\AuditLogger;
use App\Support\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Professional contacts at a customer: doctors, pharmacists, buyers (spec §17:
 * "maintain appropriate boundaries around sensitive professional and personal data").
 *
 * - Only work data is held: role, specialty, professional registration, work contact details.
 *   Home addresses, dates of birth and similar personal data have no field.
 * - Consent to be contacted directly is recorded with when and how it was given. A contact
 *   who withdrew it is not called, e-mailed or messaged through the CRM.
 * - Anonymizing removes the personal fields for good and keeps the history of the account.
 */
class ContactService
{
    public const FIELDS = ['name', 'job_title', 'professional_type', 'specialty', 'professional_licence', 'email', 'phone', 'preferred_contact_method', 'is_primary'];

    public const PROFESSIONAL_TYPES = ['doctor', 'pharmacist', 'nurse', 'procurement', 'owner', 'manager', 'other'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function create(Customer $customer, array $data): PartyContact
    {
        return DB::transaction(function () use ($customer, $data) {
            $contact = PartyContact::query()->create(Arr::only($data, self::FIELDS) + ['party_type' => 'customer', 'party_id' => $customer->id]
                + $this->consent($data['contact_consent'] ?? null, $data['consent_source'] ?? null));
            $this->audit->record('customer_contact.created', $contact, new: Arr::only($data, ['name', 'professional_type', 'specialty']) + ['customer_id' => $customer->id]);

            return $contact;
        });
    }

    public function update(PartyContact $contact, array $data): PartyContact
    {
        $this->assertNotAnonymized($contact);
        $original = $contact->getAttributes();
        $contact->fill(Arr::only($data, self::FIELDS))->save();
        [$old, $new] = $this->audit->diff($contact, $original);
        if ($new !== []) {
            // Field names only: values of personal fields are not copied into the audit trail.
            $this->audit->record('customer_contact.updated', $contact, new: ['changed' => array_keys($new)]);
        }

        return $contact;
    }

    public function setConsent(PartyContact $contact, string $consent, string $source): PartyContact
    {
        $this->assertNotAnonymized($contact);
        $before = $contact->contact_consent;
        $contact->forceFill($this->consent($consent, $source))->save();
        $this->audit->record('customer_contact.consent_changed', $contact, ['contact_consent' => $before], ['contact_consent' => $consent, 'source' => $source]);

        return $contact;
    }

    public function anonymize(PartyContact $contact, string $reason): PartyContact
    {
        $this->assertNotAnonymized($contact);
        $contact->forceFill(['name' => 'Anonymized contact', 'job_title' => null, 'specialty' => null, 'professional_licence' => null, 'email' => null,
            'phone' => null, 'preferred_contact_method' => 'none', 'contact_consent' => 'withdrawn', 'consent_recorded_at' => now(),
            'consent_source' => 'anonymized', 'is_primary' => false, 'anonymized_at' => now()])->save();
        $this->audit->record('customer_contact.anonymized', $contact, new: ['reason' => $reason]);

        return $contact;
    }

    /** A contact of the given customer, usable for new records. */
    public function contactOf(Customer $customer, ?string $contactId): ?PartyContact
    {
        if ($contactId === null) {
            return null;
        }
        $contact = PartyContact::query()->where('party_type', 'customer')->where('party_id', $customer->id)->find($contactId);
        if ($contact === null) {
            throw new BusinessRuleViolation('contact_not_found', 'The contact does not belong to this customer.', 422);
        }
        $this->assertNotAnonymized($contact);

        return $contact;
    }

    private function consent(?string $consent, ?string $source): array
    {
        return $consent === null || $consent === 'unknown'
            ? ['contact_consent' => 'unknown']
            : ['contact_consent' => $consent, 'consent_recorded_at' => now(), 'consent_source' => $source];
    }

    private function assertNotAnonymized(PartyContact $contact): void
    {
        if ($contact->anonymized_at !== null) {
            throw new BusinessRuleViolation('contact_anonymized', 'This contact has been anonymized.', 409);
        }
    }
}

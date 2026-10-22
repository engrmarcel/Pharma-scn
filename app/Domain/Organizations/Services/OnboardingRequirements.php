<?php

namespace App\Domain\Organizations\Services;

use App\Domain\Catalogue\Models\RegulatoryIdentifier;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Platform\Models\RegulatoryIdentifierType;
use App\Domain\Platform\Support\CountryProfile;

/**
 * What an organization must provide before submitting for verification, from its country
 * profile and organization type (spec §20 country-specific onboarding): documents, regulatory
 * identifiers (e.g. a pharmacy's premises licence) and profile fields such as the address
 * parts the country uses. The same checklist is shown to the organization and enforced on submit.
 */
class OnboardingRequirements
{
    public function for(Organization $org): array
    {
        $profile = CountryProfile::of($org->country);
        $type = $org->type instanceof OrganizationType ? $org->type->value : $org->type;
        $documents = $org->documents()->pluck('category')->unique();

        $identifiers = collect($profile->requiredIdentifiers($type))->map(function ($r) use ($org) {
            $config = RegulatoryIdentifierType::query()->with('authority')->whereHas('authority', fn ($a) => $a->where('code', $r['authority']))
                ->where('identifier_type', $r['identifier_type'])->first();
            $present = $config && RegulatoryIdentifier::query()->where('subject_type', 'organization')->where('subject_id', $org->id)
                ->where('authority_id', $config->authority_id)->where('identifier_type', $r['identifier_type'])->exists();

            return ['authority' => $r['authority'], 'identifier_type' => $r['identifier_type'], 'name' => $config?->name ?? "{$r['authority']} {$r['identifier_type']}",
                'format_example' => $config?->format_example, 'present' => $present];
        })->values();

        $fields = collect($profile->get('address.required_fields', []))
            ->map(fn ($f) => ['field' => $f, 'label' => $f === 'state_region' ? $profile->get('address.region_label') : str_replace('_', ' ', $f), 'present' => filled($org->{$f})])->values();

        $documentRows = collect($profile->requiredDocuments($type))->map(fn ($c) => ['category' => $c, 'present' => $documents->contains($c)])->values();

        return [
            'country_code' => $org->country_code,
            'organization_type' => $type,
            'labels' => ['tax_identification_number' => $profile->get('tax.registration_label'), 'business_registration_number' => $profile->get('business_registration.label'),
                'state_region' => $profile->get('address.region_label')],
            'documents' => $documentRows,
            'identifiers' => $identifiers,
            'profile_fields' => $fields,
            'any_document' => $documents->isNotEmpty(),
            'ready' => $documents->isNotEmpty() && $documentRows->every('present') && $identifiers->every('present') && $fields->every('present'),
        ];
    }
}

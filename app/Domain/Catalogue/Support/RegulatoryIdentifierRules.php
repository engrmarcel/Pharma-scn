<?php

namespace App\Domain\Catalogue\Support;

use Illuminate\Validation\Rule;

final class RegulatoryIdentifierRules
{
    public static function rules(string $prefix = ''): array
    {
        return [
            $prefix.'authority_code' => ['required', 'string', 'exists:regulatory_authorities,code'],
            $prefix.'identifier_type' => ['required', Rule::in(['registration_number', 'listing_number', 'licence_number'])],
            // Formats vary by authority and over time, so only the character set is enforced.
            $prefix.'value' => ['required', 'string', 'min:2', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9\-\/\. ]*$/'],
            $prefix.'declared_status' => ['sometimes', Rule::in(['registered', 'expired', 'suspended', 'cancelled', 'unknown'])],
            $prefix.'registration_date' => ['nullable', 'date'],
            $prefix.'expiry_date' => ['nullable', 'date', 'after_or_equal:'.$prefix.'registration_date'],
            $prefix.'holder_name' => ['nullable', 'string', 'max:255'],
        ];
    }
}

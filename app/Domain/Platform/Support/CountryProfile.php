<?php

namespace App\Domain\Platform\Support;

use App\Domain\Platform\Models\Country;
use Illuminate\Validation\Rule;

/**
 * Everything that differs between countries, held as data on the country (spec §20):
 * formats, phone and address rules, tax identity and pricing conventions, verification
 * documents and identifiers per organization type, regulatory settings, payment providers,
 * data residency and the reviews that must be completed before the country goes live.
 *
 * Code reads countries only through this class, so adding a country is configuration:
 * no country code appears in business logic.
 */
final class CountryProfile
{
    public const REVIEW_AREAS = ['tax', 'regulatory', 'legal'];

    public const INCIDENT_TYPES = ['stolen', 'lost', 'identifier_data_error', 'unscannable_carrier', 'missing_identifier', 'suspected_falsified'];

    /** Settings every country has; stored values override these. */
    public const DEFAULTS = [
        'formats' => ['date' => null, 'first_day_of_week' => 1],
        'phone' => ['national_number_pattern' => null, 'trunk_prefix' => null],
        'address' => ['region_label' => 'Region', 'required_fields' => ['address_line1', 'city'], 'postal_code_pattern' => null],
        'tax' => ['default_category' => null, 'registration_label' => 'Tax identification number', 'registration_pattern' => null, 'prices_include_tax' => false],
        'business_registration' => ['label' => 'Business registration number', 'pattern' => null],
        'verification' => ['required_document_categories' => ['business_registration'], 'by_organization_type' => []],
        'regulatory' => ['product_registration_authority' => null, 'incident_deadline_hours' => [], 'single_2d_code_on_packs' => false],
        'payments' => ['providers' => []],
        'data' => ['residency_region' => null],
        'reviews' => ['tax' => ['status' => 'pending'], 'regulatory' => ['status' => 'pending'], 'legal' => ['status' => 'pending']],
    ];

    public function __construct(public readonly Country $country) {}

    public static function of(Country $country): self
    {
        return new self($country);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->all(), $key, $default);
    }

    public function all(): array
    {
        return array_replace_recursive(self::DEFAULTS, $this->country->settings ?? []);
    }

    public function currencyCode(): string
    {
        return $this->country->currency_code;
    }

    public function minorUnits(): int
    {
        return (int) config("currencies.{$this->country->currency_code}.minor_units", 2);
    }

    /** Statutory reporting deadline in hours for a traceability incident type, or null when none is configured. */
    public function incidentDeadlineHours(string $type): ?int
    {
        $hours = $this->get("regulatory.incident_deadline_hours.{$type}");

        return $hours === null ? null : (int) $hours;
    }

    /** @return list<string> */
    public function requiredDocuments(?string $organizationType): array
    {
        return array_values(array_unique(array_merge($this->get('verification.required_document_categories', []),
            $organizationType ? $this->get("verification.by_organization_type.{$organizationType}.required_document_categories", []) : [])));
    }

    /** @return list<array{authority: string, identifier_type: string}> */
    public function requiredIdentifiers(?string $organizationType): array
    {
        return $organizationType ? $this->get("verification.by_organization_type.{$organizationType}.required_identifiers", []) : [];
    }

    /** Extra validation for organization and customer profiles in this country. */
    public function partyRules(bool $requireAddress = false): array
    {
        $rules = [];
        foreach (['tax_identification_number' => $this->get('tax.registration_pattern'), 'business_registration_number' => $this->get('business_registration.pattern'),
            'postal_code' => $this->get('address.postal_code_pattern')] as $field => $pattern) {
            if ($pattern) {
                $rules[$field] = ['regex:'.self::delimit($pattern)];
            }
        }
        if ($this->get('phone.national_number_pattern')) {
            $rules['phone'] = [function ($attribute, $value, $fail) {
                if ($value !== null && ! $this->validPhone((string) $value)) {
                    $fail("The phone number is not a valid {$this->country->name} number.");
                }
            }];
        }
        if ($requireAddress) {
            foreach ($this->get('address.required_fields', []) as $field) {
                $rules[$field] = ['required'];
            }
        }

        return $rules;
    }

    /** A national number (with or without the trunk prefix) or an international one with this country's calling code. */
    public function validPhone(string $value): bool
    {
        $digits = preg_replace('/[\s\-().]/', '', $value);
        $code = ltrim((string) $this->country->phone_code, '+');
        if (str_starts_with($digits, '+')) {
            if ($code === '' || ! str_starts_with($digits, '+'.$code)) {
                return false;
            }
            $digits = substr($digits, strlen($code) + 1);
        } elseif (($trunk = $this->get('phone.trunk_prefix')) && str_starts_with($digits, $trunk)) {
            $digits = substr($digits, strlen($trunk));
        }

        return (bool) preg_match(self::delimit('^(?:'.trim($this->get('phone.national_number_pattern'), '^$').')$'), $digits);
    }

    public static function delimit(string $pattern): string
    {
        // '~' as delimiter, so patterns may contain slashes as written (e.g. FDA\/GH\/…).
        return '~'.str_replace('~', '\~', $pattern).'~';
    }

    /** Validation of a settings patch from platform staff. */
    public static function rules(): array
    {
        $regex = ['nullable', 'string', 'max:255', function ($attribute, $value, $fail) {
            if ($value !== null && @preg_match(self::delimit($value), '') === false) {
                $fail("{$attribute} is not a valid regular expression.");
            }
        }];

        return [
            'formats.date' => ['nullable', 'string', 'max:32'],
            'formats.first_day_of_week' => ['integer', 'between:0,6'],
            'phone.national_number_pattern' => $regex,
            'phone.trunk_prefix' => ['nullable', 'string', 'max:4'],
            'address.region_label' => ['string', 'max:40'],
            'address.required_fields' => ['array'],
            'address.required_fields.*' => [Rule::in(['address_line1', 'city', 'state_region', 'postal_code'])],
            'address.postal_code_pattern' => $regex,
            'tax.default_category' => ['nullable', 'string', 'max:32'],
            'tax.registration_label' => ['string', 'max:60'],
            'tax.registration_pattern' => $regex,
            'tax.prices_include_tax' => ['boolean'],
            'business_registration.label' => ['string', 'max:60'],
            'business_registration.pattern' => $regex,
            'verification.required_document_categories' => ['array'],
            'verification.required_document_categories.*' => ['string', 'max:40'],
            'verification.by_organization_type' => ['array'],
            'verification.by_organization_type.*.required_document_categories' => ['array'],
            'verification.by_organization_type.*.required_document_categories.*' => ['string', 'max:40'],
            'verification.by_organization_type.*.required_identifiers' => ['array'],
            'verification.by_organization_type.*.required_identifiers.*.authority' => ['required', 'string', 'exists:regulatory_authorities,code'],
            'verification.by_organization_type.*.required_identifiers.*.identifier_type' => ['required', 'string', 'max:32'],
            'regulatory.product_registration_authority' => ['nullable', 'string', 'exists:regulatory_authorities,code'],
            'regulatory.incident_deadline_hours' => ['array'],
            'regulatory.incident_deadline_hours.*' => ['nullable', 'integer', 'between:1,720'],
            'regulatory.single_2d_code_on_packs' => ['boolean'],
            'payments.providers' => ['array'],
            'payments.providers.*' => ['string', Rule::in(array_keys(config('payments.providers')))],
            'data.residency_region' => ['nullable', 'string', 'max:40'],
            'reviews' => ['array'],
            'reviews.*.status' => [Rule::in(['pending', 'partial', 'completed'])],
            'reviews.*.reference' => ['nullable', 'string', 'max:500'],
            'reviews.*.notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}

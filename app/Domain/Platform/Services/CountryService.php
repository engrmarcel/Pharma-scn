<?php

namespace App\Domain\Platform\Services;

use App\Domain\Platform\Models\Country;
use App\Domain\Platform\Models\RegulatoryAuthority;
use App\Domain\Platform\Models\RegulatoryIdentifierType;
use App\Domain\Platform\Support\CountryProfile;
use App\Domain\Pos\Payments\PaymentProviderRegistry;
use App\Domain\Sales\Models\TaxRate;
use App\Models\User;
use App\Support\Exceptions\BusinessRuleViolation;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use ResourceBundle;

/**
 * Country configuration by platform staff (spec §20, Phase 9): profile updates with
 * validation and audit, the readiness checklist, and activation. A country is activated
 * only when its checklist passes, including completed tax, regulatory and legal reviews
 * with references; the platform never claims compliance it has not reviewed.
 */
class CountryService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function update(Country $country, array $patch, User $actor): Country
    {
        $columns = Validator::make($patch, [
            'name' => ['sometimes', 'string', 'max:255'],
            'currency_code' => ['sometimes', 'string', 'size:3'],
            'timezone' => ['sometimes', 'timezone:all'],
            'default_locale' => ['sometimes', 'string', 'max:10'],
            'phone_code' => ['sometimes', 'nullable', 'regex:/^\+\d{1,4}$/'],
        ])->validate();
        $settings = Validator::make($patch['settings'] ?? [], CountryProfile::rules())->validate();
        if ($country->is_active && isset($columns['currency_code']) && $columns['currency_code'] !== $country->currency_code
            && DB::table('organizations')->where('country_code', $country->code)->exists()) {
            throw new BusinessRuleViolation('country_currency_locked', 'The currency of a country with organizations cannot change; books are kept in it.', 409);
        }

        // Review entries record who completed them and when.
        foreach ($settings['reviews'] ?? [] as $area => $review) {
            if (! in_array($area, CountryProfile::REVIEW_AREAS, true)) {
                throw new BusinessRuleViolation('review_area_unknown', "Unknown review area {$area}.", 422);
            }
            if (($review['status'] ?? null) === 'completed' && blank($review['reference'] ?? null)) {
                throw new BusinessRuleViolation('review_reference_required', "A completed {$area} review needs a reference (document, opinion or ticket).", 422);
            }
            $settings['reviews'][$area] += ['recorded_by' => $actor->id, 'recorded_at' => now()->toIso8601String()];
        }

        $original = $country->getAttributes();
        // Lists are replaced, not merged, so a removed document requirement really disappears.
        $merged = $country->settings ?? [];
        foreach ($settings as $section => $values) {
            foreach ($values as $key => $value) {
                $merged[$section][$key] = $value;
            }
        }
        $country->fill($columns)->forceFill(['settings' => $merged])->save();
        [$old, $new] = $this->audit->diff($country, $original);
        $this->audit->record('country.updated', $country, $old, $new, organizationId: false);

        return $country;
    }

    /** @return array{ready: bool, checks: list<array{check: string, status: string, message: string}>} */
    public function readiness(Country $country): array
    {
        $p = CountryProfile::of($country);
        $checks = [];
        $add = function (string $check, bool $ok, string $message, bool $blocking = true) use (&$checks) {
            $checks[] = ['check' => $check, 'status' => $ok ? 'pass' : ($blocking ? 'fail' : 'warning'), 'message' => $message];
        };

        $add('currency', config("currencies.{$country->currency_code}") !== null,
            config("currencies.{$country->currency_code}") ? "{$country->currency_code} ({$p->minorUnits()} decimal places)." : "{$country->currency_code} is not in config/currencies.php.");
        $add('timezone', in_array($country->timezone, DateTimeZone::listIdentifiers(), true), "Time zone {$country->timezone}.");
        $add('locale', in_array(\Locale::canonicalize($country->default_locale), array_map([\Locale::class, 'canonicalize'], ResourceBundle::getLocales('') ?: []), true),
            "Locale {$country->default_locale} for dates and numbers.");

        $category = $p->get('tax.default_category');
        $categories = TaxRate::query()->where('country_code', $country->code)->where('effective_from', '<=', business_today())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', business_today()))->pluck('category_code')->unique();
        $add('tax_rates', $categories->isNotEmpty(), $categories->isNotEmpty() ? 'Tax categories in force: '.$categories->implode(', ').'.' : 'No tax rates in force.');
        $add('tax_default_category', $category === null || $categories->contains($category),
            $category === null ? 'No default tax category: every catalogue item needs its own.' : "Default category {$category}".($categories->contains($category) ? ' has a rate in force.' : ' has no rate in force.'));

        $authorities = RegulatoryAuthority::query()->where('country_code', $country->code)->where('is_active', true)->pluck('id', 'code');
        $add('regulatory_authority', $authorities->isNotEmpty(), $authorities->isNotEmpty() ? 'Regulators: '.$authorities->keys()->implode(', ').'.' : 'No regulatory authority configured.');
        $productAuthority = $p->get('regulatory.product_registration_authority');
        $productTypes = $productAuthority && isset($authorities[$productAuthority])
            ? RegulatoryIdentifierType::query()->where('authority_id', $authorities[$productAuthority])->where('is_active', true)->get()->filter(fn ($t) => $t->appliesTo('product'))
            : collect();
        $add('product_registration', $productTypes->isNotEmpty(), $productTypes->isNotEmpty()
            ? "Products are registered with {$productAuthority} (".$productTypes->pluck('identifier_type')->implode(', ').').' : 'Set the product registration authority and its identifier types.');
        $required = collect($p->get('verification.by_organization_type', []))->flatMap(fn ($t) => $t['required_identifiers'] ?? []);
        $unknown = $required->reject(fn ($r) => RegulatoryIdentifierType::query()->whereHas('authority', fn ($a) => $a->where('code', $r['authority']))
            ->where('identifier_type', $r['identifier_type'])->exists());
        $add('required_identifiers', $unknown->isEmpty(), $unknown->isEmpty() ? 'Required organization identifiers are configured.' : 'Unconfigured identifier types are required: '
            .$unknown->map(fn ($r) => "{$r['authority']} {$r['identifier_type']}")->implode(', ').'.');
        $add('verification_documents', $p->requiredDocuments(null) !== [], 'Documents required from every organization: '.(implode(', ', $p->requiredDocuments(null)) ?: 'none').'.');

        $incidentTypes = array_filter($p->get('regulatory.incident_deadline_hours', []), fn ($h) => $h !== null);
        $add('incident_deadlines', $incidentTypes !== [], $incidentTypes !== [] ? count($incidentTypes).' traceability incident types have a reporting deadline.'
            : 'No incident reporting deadlines: incidents are recorded without a due date.', false);
        $providers = app(PaymentProviderRegistry::class)->availableFor($country->code, $country->currency_code);
        $add('payments', true, $providers === [] ? 'Cash and bank transfer only; no electronic payment provider for this country yet.' : 'Electronic payments: '.implode(', ', array_keys($providers)).'.', false);
        $add('phone_format', (bool) $p->get('phone.national_number_pattern'), $p->get('phone.national_number_pattern') ? 'Phone numbers are validated.' : 'Phone numbers are not validated.', false);

        foreach (CountryProfile::REVIEW_AREAS as $area) {
            $review = $p->get("reviews.{$area}", []);
            $add("{$area}_review", ($review['status'] ?? 'pending') === 'completed',
                ucfirst($area).' review: '.($review['status'] ?? 'pending').(! empty($review['reference']) ? " ({$review['reference']})" : '').'.');
        }

        return ['ready' => collect($checks)->where('status', 'fail')->isEmpty(), 'checks' => $checks];
    }

    public function activate(Country $country, User $actor): Country
    {
        if ($country->is_active) {
            return $country;
        }
        $readiness = $this->readiness($country);
        if (! $readiness['ready']) {
            throw new BusinessRuleViolation('country_not_ready', "{$country->name} cannot be activated yet.", 409,
                ['failing' => collect($readiness['checks'])->where('status', 'fail')->values()->all()]);
        }
        $country->forceFill(['is_active' => true])->save();
        $this->audit->record('country.activated', $country, ['is_active' => false], ['is_active' => true], organizationId: false);

        return $country;
    }

    /** New organizations can no longer register in the country; existing ones keep working. */
    public function deactivate(Country $country, string $reason): Country
    {
        $country->forceFill(['is_active' => false])->save();
        $this->audit->record('country.deactivated', $country, ['is_active' => true], ['is_active' => false], metadata: ['reason' => $reason], organizationId: false);

        return $country;
    }
}

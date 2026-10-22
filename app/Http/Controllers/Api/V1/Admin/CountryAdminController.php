<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Platform\Models\Country;
use App\Domain\Platform\Models\RegulatoryAuthority;
use App\Domain\Platform\Models\RegulatoryIdentifierType;
use App\Domain\Platform\Services\AuditLogger;
use App\Domain\Platform\Services\CountryService;
use App\Domain\Platform\Support\CountryProfile;
use App\Domain\Sales\Models\TaxRate;
use App\Http\Controllers\Controller;
use App\Support\Exceptions\BusinessRuleViolation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Country configuration for platform staff (spec §20, Phase 9): profile, tax rates,
 * regulators and their identifier types, readiness and activation.
 */
class CountryAdminController extends Controller
{
    public function __construct(private readonly CountryService $countries) {}

    public function index(): JsonResponse
    {
        Gate::authorize('platform.countries.manage');

        return response()->json(['data' => Country::query()->orderBy('name')->get()->map(fn (Country $c) => [
            'code' => $c->code, 'name' => $c->name, 'is_active' => $c->is_active, 'currency_code' => $c->currency_code,
            'organizations' => DB::table('organizations')->where('country_code', $c->code)->count(),
            'ready' => $this->countries->readiness($c)['ready'],
        ])]);
    }

    public function show(string $code): JsonResponse
    {
        Gate::authorize('platform.countries.manage');
        $country = Country::query()->findOrFail(strtoupper($code));

        return response()->json(['data' => $this->present($country)]);
    }

    public function update(Request $request, string $code): JsonResponse
    {
        Gate::authorize('platform.countries.manage');
        $country = Country::query()->findOrFail(strtoupper($code));

        return response()->json(['data' => $this->present($this->countries->update($country, $request->all(), $request->user()))]);
    }

    public function activate(Request $request, string $code): JsonResponse
    {
        Gate::authorize('platform.countries.manage');

        return response()->json(['data' => $this->present($this->countries->activate(Country::query()->findOrFail(strtoupper($code)), $request->user()))]);
    }

    public function deactivate(Request $request, string $code): JsonResponse
    {
        Gate::authorize('platform.countries.manage');
        $reason = $request->validate(['reason' => ['required', 'string', 'max:2000']])['reason'];

        return response()->json(['data' => $this->present($this->countries->deactivate(Country::query()->findOrFail(strtoupper($code)), $reason))]);
    }

    /** Effective-dated tax rate; an earlier open-ended rate for the category is closed the day before. */
    public function storeTaxRate(Request $request, string $code, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('platform.countries.manage');
        $country = Country::query()->findOrFail(strtoupper($code));
        $data = $request->validate([
            'category_code' => ['required', 'string', 'max:32', 'regex:/^[A-Z][A-Z0-9_]*$/'],
            'name' => ['required', 'string', 'max:255'],
            'rate' => ['required', 'decimal:0,4', 'between:0,100'],
            'effective_from' => ['required', 'date'],
            'source' => ['required', 'string', 'max:255'],
        ]);
        if (TaxRate::query()->where('country_code', $country->code)->where('category_code', $data['category_code'])->where('effective_from', '>=', $data['effective_from'])->exists()) {
            throw new BusinessRuleViolation('tax_rate_overlap', 'A rate for this category already starts on or after that date.', 409);
        }
        TaxRate::query()->where('country_code', $country->code)->where('category_code', $data['category_code'])->whereNull('effective_to')
            ->update(['effective_to' => now()->parse($data['effective_from'])->subDay()->toDateString()]);
        $rate = TaxRate::query()->create(['country_code' => $country->code] + $data);
        $audit->record('tax_rate.created', $rate, new: $rate->only('country_code', 'category_code', 'rate', 'effective_from', 'source'), organizationId: false);

        return response()->json(['data' => $rate], 201);
    }

    public function storeIdentifierType(Request $request, string $authorityCode, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('platform.countries.manage');
        $authority = RegulatoryAuthority::query()->where('code', $authorityCode)->firstOrFail();
        $data = $request->validate([
            'identifier_type' => ['required', 'string', 'max:32', 'regex:/^[a-z][a-z0-9_]*$/'],
            'name' => ['required', 'string', 'max:255'],
            'subject_types' => ['required', 'array', 'min:1'],
            'subject_types.*' => [Rule::in(RegulatoryIdentifierType::SUBJECTS)],
            'format_pattern' => CountryProfile::rules()['tax.registration_pattern'],
            'format_example' => ['nullable', 'string', 'max:64'],
            'source' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);
        if (($data['format_pattern'] ?? null) && ($data['format_example'] ?? null) && ! preg_match(CountryProfile::delimit($data['format_pattern']), $data['format_example'])) {
            throw new BusinessRuleViolation('format_example_mismatch', 'The example does not match the format pattern.', 422);
        }
        $type = RegulatoryIdentifierType::query()->updateOrCreate(['authority_id' => $authority->id, 'identifier_type' => $data['identifier_type']], $data);
        $audit->record('regulatory_identifier_type.saved', $type, new: $data + ['authority' => $authority->code], organizationId: false);

        return response()->json(['data' => $type], 201);
    }

    private function present(Country $country): array
    {
        $authorities = RegulatoryAuthority::query()->where('country_code', $country->code)->orderBy('code')->get();

        return [
            'code' => $country->code, 'name' => $country->name, 'is_active' => $country->is_active, 'currency_code' => $country->currency_code,
            'timezone' => $country->timezone, 'default_locale' => $country->default_locale, 'phone_code' => $country->phone_code,
            'profile' => CountryProfile::of($country)->all(),
            'tax_rates' => TaxRate::query()->where('country_code', $country->code)->orderBy('category_code')->orderBy('effective_from')->get(['category_code', 'name', 'rate', 'effective_from', 'effective_to', 'source']),
            'regulators' => $authorities->map(fn ($a) => ['code' => $a->code, 'name' => $a->name, 'identifier_types' => RegulatoryIdentifierType::query()->where('authority_id', $a->id)
                ->orderBy('identifier_type')->get(['identifier_type', 'name', 'subject_types', 'format_pattern', 'format_example', 'is_active'])]),
            'readiness' => $this->countries->readiness($country),
        ];
    }
}

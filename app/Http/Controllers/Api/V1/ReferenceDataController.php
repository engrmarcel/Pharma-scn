<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalogue\Models\ProductCategory;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Platform\Models\AdministrationRoute;
use App\Domain\Platform\Models\Country;
use App\Domain\Platform\Models\DosageForm;
use App\Domain\Platform\Models\RegulatoryAuthority;
use App\Domain\Platform\Models\UnitOfMeasure;
use App\Domain\Platform\Services\BrandingService;
use App\Domain\Platform\Support\CountryProfile;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class ReferenceDataController extends Controller
{
    public function branding(BrandingService $branding): JsonResponse
    {
        $brand = $branding->platform();
        unset($brand['email_from_address']);

        return response()->json(['data' => $brand]);
    }

    public function countries(): JsonResponse
    {
        // What a client needs to build forms and format values for each country (CountryProfile).
        return response()->json(['data' => Country::query()->where('is_active', true)->orderBy('name')->get()->map(function (Country $c) {
            $p = CountryProfile::of($c);

            return $c->only('code', 'name', 'currency_code', 'timezone', 'default_locale', 'phone_code') + [
                'currency_minor_units' => $p->minorUnits(),
                'date_format' => $p->get('formats.date'),
                'first_day_of_week' => $p->get('formats.first_day_of_week'),
                'labels' => ['state_region' => $p->get('address.region_label'), 'tax_identification_number' => $p->get('tax.registration_label'),
                    'business_registration_number' => $p->get('business_registration.label')],
                'address_required_for_verification' => $p->get('address.required_fields'),
                'prices_include_tax' => $p->get('tax.prices_include_tax'),
            ];
        })]);
    }

    public function organizationTypes(): JsonResponse
    {
        return response()->json(['data' => array_map(fn ($t) => ['code' => $t->value, 'name' => $t->label()], OrganizationType::cases())]);
    }

    public function regulatoryAuthorities(): JsonResponse
    {
        return response()->json(['data' => RegulatoryAuthority::query()->where('is_active', true)->orderBy('code')
            ->get(['id', 'code', 'name', 'country_code', 'website_url'])]);
    }

    public function catalogueReference(): JsonResponse
    {
        return response()->json(['data' => [
            'dosage_forms' => DosageForm::query()->orderBy('name')->get(),
            'administration_routes' => AdministrationRoute::query()->orderBy('name')->get(),
            'units_of_measure' => UnitOfMeasure::query()->orderBy('code')->get(),
            'categories' => ProductCategory::query()->orderBy('name')->get(['id', 'parent_id', 'code', 'name']),
        ]]);
    }
}

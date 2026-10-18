<?php

namespace Database\Seeders;

use App\Domain\Catalogue\Models\ProductCategory;
use App\Domain\Platform\Models\AdministrationRoute;
use App\Domain\Platform\Models\Country;
use App\Domain\Platform\Models\DosageForm;
use App\Domain\Platform\Models\RegulatoryAuthority;
use App\Domain\Platform\Models\UnitOfMeasure;
use App\Domain\Sales\Models\TaxRate;
use Illuminate\Database\Seeder;

/**
 * Idempotent reference data. Regulator entries are directory information
 * only; their presence implies no integration with, or endorsement by, the
 * regulator. Only Nigeria is active at launch.
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $countries = [
            // code, name, currency, timezone, locale, phone, active
            ['NG', 'Nigeria', 'NGN', 'Africa/Lagos', 'en_NG', '+234', true],
            ['GH', 'Ghana', 'GHS', 'Africa/Accra', 'en_GH', '+233', false],
            ['KE', 'Kenya', 'KES', 'Africa/Nairobi', 'en_KE', '+254', false],
            ['ZA', 'South Africa', 'ZAR', 'Africa/Johannesburg', 'en_ZA', '+27', false],
            ['RW', 'Rwanda', 'RWF', 'Africa/Kigali', 'en_RW', '+250', false],
            ['EG', 'Egypt', 'EGP', 'Africa/Cairo', 'ar_EG', '+20', false],
        ];

        foreach ($countries as [$code, $name, $currency, $tz, $locale, $phone, $active]) {
            $defaults = [
                // Document requirements are a compliance decision; this is a minimal starting point to be reviewed.
                'verification' => ['required_document_categories' => ['business_registration']],
                // Products without a tax category on the catalogue item use this category. Medicines are commonly
                // VAT-exempt in Nigeria; confirm per product with a tax professional.
                'tax' => ['default_category' => $code === 'NG' ? 'EXEMPT' : null],
            ];

            // Runs on every deploy: create missing countries, add missing setting keys,
            // and never overwrite what platform staff have configured (activation, settings).
            $country = Country::query()->find($code);
            if ($country === null) {
                Country::query()->create([
                    'code' => $code, 'name' => $name, 'currency_code' => $currency, 'timezone' => $tz,
                    'default_locale' => $locale, 'phone_code' => $phone, 'is_active' => $active, 'settings' => $defaults,
                ]);
            } else {
                $country->forceFill(['settings' => array_replace_recursive($defaults, $country->settings ?? [])])->save();
            }
        }

        $this->taxRates();

        $authorities = [
            ['NAFDAC', 'NG', 'National Agency for Food and Drug Administration and Control', 'https://nafdac.gov.ng'],
            ['PCN', 'NG', 'Pharmacists Council of Nigeria', 'https://pcn.gov.ng'],
            ['FDA_GH', 'GH', 'Food and Drugs Authority (Ghana)', 'https://fdaghana.gov.gh'],
            ['PPB_KE', 'KE', 'Pharmacy and Poisons Board (Kenya)', 'https://web.pharmacyboardkenya.org'],
            ['SAHPRA', 'ZA', 'South African Health Products Regulatory Authority', 'https://www.sahpra.org.za'],
            ['RWANDA_FDA', 'RW', 'Rwanda Food and Drugs Authority', 'https://rwandafda.gov.rw'],
            ['EDA', 'EG', 'Egyptian Drug Authority', 'https://www.edaegypt.gov.eg'],
        ];
        foreach ($authorities as [$code, $country, $name, $url]) {
            RegulatoryAuthority::query()->updateOrCreate(['code' => $code], ['country_code' => $country, 'name' => $name, 'website_url' => $url]);
        }

        $units = [
            'mg' => ['Milligram', 'mass'], 'g' => ['Gram', 'mass'], 'mcg' => ['Microgram', 'mass'], 'kg' => ['Kilogram', 'mass'],
            'ml' => ['Millilitre', 'volume'], 'l' => ['Litre', 'volume'],
            'iu' => ['International unit', 'activity'], 'mmol' => ['Millimole', 'other'], 'percent' => ['Percent (w/w or w/v)', 'other'],
            'unit' => ['Unit', 'count'], 'tablet' => ['Tablet', 'count'], 'capsule' => ['Capsule', 'count'], 'dose' => ['Dose', 'count'],
            'ampoule' => ['Ampoule', 'count'], 'vial' => ['Vial', 'count'], 'sachet' => ['Sachet', 'count'], 'bottle' => ['Bottle', 'count'],
            'tube' => ['Tube', 'count'], 'blister' => ['Blister', 'count'], 'pack' => ['Pack', 'count'], 'box' => ['Box', 'count'],
            'carton' => ['Carton', 'count'], 'case' => ['Shipping case', 'count'], 'pallet' => ['Pallet', 'count'],
        ];
        foreach ($units as $code => [$name, $dimension]) {
            UnitOfMeasure::query()->updateOrCreate(['code' => $code], ['name' => $name, 'dimension' => $dimension]);
        }

        $forms = [
            'tablet' => 'Tablet', 'film_coated_tablet' => 'Film-coated tablet', 'dispersible_tablet' => 'Dispersible tablet',
            'capsule' => 'Capsule', 'syrup' => 'Syrup', 'oral_suspension' => 'Oral suspension', 'oral_solution' => 'Oral solution',
            'powder_for_suspension' => 'Powder for oral suspension', 'injection' => 'Injection', 'infusion' => 'Infusion',
            'cream' => 'Cream', 'ointment' => 'Ointment', 'gel' => 'Gel', 'eye_drops' => 'Eye drops', 'ear_drops' => 'Ear drops',
            'nasal_spray' => 'Nasal spray', 'inhaler' => 'Inhaler', 'suppository' => 'Suppository', 'pessary' => 'Pessary',
            'lozenge' => 'Lozenge', 'transdermal_patch' => 'Transdermal patch', 'sachet_powder' => 'Powder in sachet',
        ];
        foreach ($forms as $code => $name) {
            DosageForm::query()->updateOrCreate(['code' => $code], ['name' => $name]);
        }

        $routes = [
            'oral' => 'Oral', 'intravenous' => 'Intravenous', 'intramuscular' => 'Intramuscular', 'subcutaneous' => 'Subcutaneous',
            'topical' => 'Topical', 'ophthalmic' => 'Ophthalmic', 'otic' => 'Otic', 'nasal' => 'Nasal', 'inhalation' => 'Inhalation',
            'rectal' => 'Rectal', 'vaginal' => 'Vaginal', 'sublingual' => 'Sublingual', 'transdermal' => 'Transdermal',
        ];
        foreach ($routes as $code => $name) {
            AdministrationRoute::query()->updateOrCreate(['code' => $code], ['name' => $name]);
        }

        $categories = [
            'analgesics' => 'Analgesics & antipyretics', 'antimalarials' => 'Antimalarials', 'antibacterials' => 'Antibacterials',
            'antiretrovirals' => 'Antiretrovirals', 'antihypertensives' => 'Antihypertensives', 'antidiabetics' => 'Antidiabetics',
            'vitamins_supplements' => 'Vitamins & supplements', 'vaccines' => 'Vaccines', 'dermatologicals' => 'Dermatologicals',
            'gastrointestinal' => 'Gastrointestinal', 'respiratory' => 'Respiratory', 'other' => 'Other',
        ];
        foreach ($categories as $code => $name) {
            ProductCategory::query()->updateOrCreate(['code' => $code], ['name' => $name]);
        }
    }

    /**
     * Starting tax configuration. These are editable platform settings, not tax advice;
     * platform staff must confirm current rates before relying on them.
     */
    private function taxRates(): void
    {
        $source = 'Configured default. Confirm with a tax professional before use.';
        foreach ([
            ['NG', 'EXEMPT', 'VAT exempt', '0', '2000-01-01'],
            ['NG', 'ZERO', 'VAT zero-rated', '0', '2000-01-01'],
            ['NG', 'VAT_STANDARD', 'VAT standard rate', '7.5', '2020-02-01'],
        ] as [$country, $category, $name, $rate, $from]) {
            TaxRate::query()->firstOrCreate(
                ['country_code' => $country, 'category_code' => $category, 'effective_from' => $from],
                ['name' => $name, 'rate' => $rate, 'source' => $source],
            );
        }
    }
}

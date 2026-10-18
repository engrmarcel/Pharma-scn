<?php

namespace Database\Seeders;

use App\Domain\Access\Models\Role;
use App\Domain\Catalogue\Models\OrganizationProduct;
use App\Domain\Catalogue\Models\Product;
use App\Domain\Catalogue\Models\ProductCategory;
use App\Domain\Catalogue\Services\ProductCatalogueService;
use App\Domain\Inventory\Services\DirectReceiptService;
use App\Domain\Organizations\Enums\VerificationStatus;
use App\Domain\Organizations\Models\Branch;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\Warehouse;
use App\Domain\Organizations\Services\OrganizationOnboardingService;
use App\Domain\Parties\Models\Customer;
use App\Domain\Parties\Models\Supplier;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Services\GoodsReceiptService;
use App\Domain\Procurement\Services\PurchaseOrderService;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

/**
 * SYNTHETIC development data (spec §32). Refuses to run in production.
 *
 * Every organization name starts with "Demo", every email ends in
 *
 * @demo.test, and every regulatory identifier uses the reserved DEMO-
 * prefix, is held by "FICTIONAL DEMO HOLDER" and stays unverified: the
 * platform refuses to mark DEMO-/TEST- identifiers verified.
 *
 * Sales orders are added with the Phase 2
 * modules that own them.
 */
class DemoSeeder extends Seeder
{
    private const PASSWORD = 'Password123!';

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('DemoSeeder refuses to run in production.');

            return;
        }

        $admin = $this->platformUser('admin@demo.test', 'Demo Platform Admin', 'platform_super_admin');
        $this->platformUser('compliance@demo.test', 'Demo Compliance Officer', 'compliance_officer');
        $this->platformUser('ops@demo.test', 'Demo Operations Admin', 'platform_operations_admin');

        $organizations = [
            // email prefix, name, type, verified, extra members [email prefix => role]
            ['manufacturer', 'Demo Pharma Manufacturing Ltd', 'manufacturer', true, ['mfr.rep' => 'sales_representative', 'mfr.integration' => 'integration_admin']],
            ['importer', 'Demo Pharma Imports Ltd', 'importer', true, ['imp.inventory' => 'inventory_manager']],
            ['distributor', 'Demo National Distribution Ltd', 'national_distributor', true, ['dist.sales' => 'sales_manager', 'dist.rep1' => 'sales_representative', 'dist.rep2' => 'sales_representative', 'dist.warehouse' => 'warehouse_officer']],
            ['wholesaler', 'Demo Wholesale Medicines', 'wholesaler', false, ['whl.procurement' => 'procurement_officer']],
            ['pharmacy1', 'Demo Community Pharmacy', 'pharmacy', true, ['ph1.pharmacist' => 'pharmacist', 'ph1.cashier' => 'pos_cashier', 'ph1.supervisor' => 'pos_supervisor']],
            ['pharmacy2', 'Demo Neighbourhood Pharmacy', 'pharmacy', false, ['ph2.cashier' => 'pos_cashier']],
            ['hospital', 'Demo General Hospital', 'hospital', true, ['hosp.procurement' => 'hospital_procurement_officer', 'hosp.auditor' => 'auditor']],
        ];

        foreach ($organizations as [$prefix, $name, $type, $verified, $members]) {
            $this->organization($prefix, $name, $type, $verified, $members);
        }

        Auth::setUser($admin);
        $this->products($admin);
        $this->stock();
        $this->purchasing();

        $this->command?->info('SYNTHETIC demo data loaded. All demo users use password '.self::PASSWORD.' (e.g. admin@demo.test, compliance@demo.test, owner.pharmacy1@demo.test, ph1.cashier@demo.test).');
    }

    private function platformUser(string $email, string $name, string $role): User
    {
        $user = $this->user($email, $name);
        $user->platformRoles()->syncWithoutDetaching(Role::query()->platform()->where('code', $role)->pluck('id'));

        return $user;
    }

    private function user(string $email, string $name): User
    {
        return User::query()->firstOrCreate(['email' => $email], ['name' => $name, 'password' => self::PASSWORD, 'email_verified_at' => now()]);
    }

    private function organization(string $prefix, string $name, string $type, bool $verified, array $members): void
    {
        if (Organization::query()->where('name', $name)->exists()) {
            return;
        }

        $owner = $this->user("owner.{$prefix}@demo.test", "{$name} Owner");
        $org = app(OrganizationOnboardingService::class)->register($owner, [
            'name' => $name, 'type' => $type, 'country_code' => 'NG',
            'city' => 'Lagos', 'state_region' => 'Lagos',
            'email' => "contact.{$prefix}@demo.test",
        ]);

        if ($verified) {
            $org->forceFill(['verification_status' => VerificationStatus::Verified, 'verified_at' => now()])->save();
        }

        foreach ($members as $memberPrefix => $role) {
            $user = $this->user("{$memberPrefix}@demo.test", ucwords(str_replace('.', ' ', $memberPrefix)));
            $membership = $org->memberships()->firstOrCreate(['user_id' => $user->id], ['status' => 'active', 'joined_at' => now()]);
            $membership->roles()->syncWithoutDetaching(Role::query()->whereNull('organization_id')->where('scope', 'organization')->where('code', $role)->pluck('id'));
        }

        app(TenantContext::class)->runAs($org, function () use ($type) {
            $hq = Branch::query()->create(['code' => 'HQ', 'name' => 'Head Office', 'type' => 'head_office', 'city' => 'Lagos']);
            Warehouse::query()->create(['code' => 'WH-MAIN', 'name' => 'Main Warehouse', 'branch_id' => $hq->id]);

            // Synthetic trading parties (codes and names marked DEMO).
            foreach ([['DEMO-SUP-1', 'Demo Supplier One Ltd', 'distributor'], ['DEMO-SUP-2', 'Demo Supplier Two Ltd', 'manufacturer']] as [$code, $name, $supplierType]) {
                Supplier::query()->create(['code' => $code, 'name' => $name, 'type' => $supplierType, 'currency_code' => 'NGN', 'payment_terms_days' => 30, 'lead_time_days' => 5, 'email' => strtolower($code).'@demo.test']);
            }
            foreach ([['DEMO-CUS-1', 'Demo Customer Pharmacy', 'pharmacy', 6.4474, 3.3903], ['DEMO-CUS-2', 'Demo Customer Clinic', 'clinic', 6.6018, 3.3515]] as [$code, $name, $customerType, $lat, $lng]) {
                Customer::query()->create(['code' => $code, 'name' => $name, 'type' => $customerType, 'currency_code' => 'NGN', 'payment_terms_days' => 14, 'latitude' => $lat, 'longitude' => $lng, 'email' => strtolower($code).'@demo.test']);
            }

            if (in_array($type, ['manufacturer', 'importer', 'national_distributor'], true)) {
                $north = Branch::query()->create(['code' => 'ABJ', 'name' => 'Abuja Branch', 'city' => 'Abuja']);
                Warehouse::query()->create(['code' => 'WH-ABJ', 'name' => 'Abuja Warehouse', 'branch_id' => $north->id]);
                Warehouse::query()->create(['code' => 'WH-COLD', 'name' => 'Cold Room', 'type' => 'cold_room', 'branch_id' => $hq->id]);
                Warehouse::query()->create(['code' => 'WH-QUAR', 'name' => 'Quarantine Store', 'type' => 'quarantine', 'branch_id' => $hq->id]);
            }
        });
    }

    /**
     * Catalogue mappings and synthetic batches (DEMO- lot numbers) with a spread of
     * expiry dates, including one near expiry and one expired-and-quarantined lot.
     */
    private function stock(): void
    {
        $products = Product::query()->where('description', 'like', 'SYNTHETIC DEMO PRODUCT%')->orderBy('generic_name')->get();

        foreach (Organization::query()->where('name', 'like', 'Demo%')->get() as $org) {
            if (! $org->hasModule('inventory')) {
                continue;
            }
            $membership = $org->memberships()->whereHas('roles', fn ($q) => $q->where('code', 'org_owner'))->first();
            Auth::setUser($membership->user);

            app(TenantContext::class)->runAs($org, function () use ($products, $org) {
                if (OrganizationProduct::query()->exists()) {
                    return;
                }
                $warehouse = Warehouse::query()->where('code', 'WH-MAIN')->firstOrFail();

                foreach ($products as $i => $product) {
                    OrganizationProduct::query()->create([
                        'product_id' => $product->id, 'sku' => 'DEMO-SKU-'.($i + 1), 'currency_code' => 'NGN',
                        'default_selling_price' => (string) (500 * ($i + 1)), 'default_purchase_cost' => (string) (350 * ($i + 1)),
                        'reorder_level' => 100, 'reorder_quantity' => 500,
                    ]);

                    $prefix = 'DEMO-'.strtoupper(substr($org->id, -4)).'-'.($i + 1);
                    app(DirectReceiptService::class)->receive($warehouse, [
                        ['product_id' => $product->id, 'batch_number' => "{$prefix}A", 'expiry_date' => now()->addMonths(2)->toDateString(), 'quantity' => 40 + 10 * $i, 'unit_cost' => (string) (3 * ($i + 1))],
                        ['product_id' => $product->id, 'batch_number' => "{$prefix}B", 'expiry_date' => now()->addMonths(18)->toDateString(), 'quantity' => 200, 'unit_cost' => (string) (3 * ($i + 1))],
                        ['product_id' => $product->id, 'batch_number' => "{$prefix}X", 'expiry_date' => now()->subMonth()->toDateString(), 'quantity' => 15, 'quality_status' => 'quarantined'],
                    ], reasonCode: 'opening_balance');
                }
            }, $membership);
        }
    }

    /**
     * One synthetic purchase order for the demo pharmacy, auto-approved under its
     * approval threshold and partially received (procurement demo workflow, spec §32 #3).
     */
    private function purchasing(): void
    {
        $org = Organization::query()->where('name', 'Demo Community Pharmacy')->first();
        if ($org === null || PurchaseOrder::acrossTenants()->where('organization_id', $org->id)->exists()) {
            return;
        }
        $membership = $org->memberships()->whereHas('roles', fn ($q) => $q->where('code', 'org_owner'))->first();
        Auth::setUser($membership->user);

        app(TenantContext::class)->runAs($org, function () use ($org, $membership) {
            $settings = $org->settings ?? [];
            data_set($settings, 'procurement.approval_threshold', '500000');
            $org->forceFill(['settings' => $settings])->save();

            $supplier = Supplier::query()->where('code', 'DEMO-SUP-1')->firstOrFail();
            $warehouse = Warehouse::query()->where('code', 'WH-MAIN')->firstOrFail();
            $product = Product::query()->where('generic_name', 'Paracetamol')->firstOrFail();

            $orders = app(PurchaseOrderService::class);
            $po = $orders->createOrder($supplier, $warehouse, [['product_id' => $product->id, 'package_level_code' => 'pack', 'quantity' => '20', 'unit_price' => '450']], notes: 'SYNTHETIC DEMO ORDER');
            $po = $orders->submitOrder($po, $membership->user);

            app(GoodsReceiptService::class)->receive($po, [[
                'purchase_order_line_id' => $po->lines->first()->id, 'batch_number' => 'DEMO-PO-LOT-1',
                'expiry_date' => now()->addMonths(20)->toDateString(), 'quantity' => '12',
            ]], 'DEMO-DN-1');
        }, $membership);
    }

    private function products(User $admin): void
    {
        $tablet = fn (string $form, int $perBlister, int $blisters) => [
            ['level' => 0, 'code' => 'unit', 'name' => ucfirst($form), 'unit_code' => $form],
            ['level' => 1, 'code' => 'blister', 'name' => "Blister of {$perBlister}", 'unit_code' => 'blister', 'quantity_of_child' => $perBlister],
            ['level' => 2, 'code' => 'pack', 'name' => "Pack of {$blisters} blisters", 'unit_code' => 'pack', 'quantity_of_child' => $blisters],
            ['level' => 3, 'code' => 'carton', 'name' => 'Carton of 50 packs', 'unit_code' => 'carton', 'quantity_of_child' => 50],
        ];

        $products = [
            ['Paracetamol', 'Demopara', '500 mg', 'tablet', 'oral', 'analgesics', 'otc', [['Paracetamol', 500, 'mg']], $tablet('tablet', 10, 10), 'DEMO-A4-0001'],
            ['Artemether/Lumefantrine', 'Demoartem', '20 mg/120 mg', 'tablet', 'oral', 'antimalarials', 'prescription_only', [['Artemether', 20, 'mg'], ['Lumefantrine', 120, 'mg']], $tablet('tablet', 6, 4), 'DEMO-A4-0002'],
            ['Amoxicillin', 'Demomox', '500 mg', 'capsule', 'oral', 'antibacterials', 'prescription_only', [['Amoxicillin trihydrate', 500, 'mg']], $tablet('capsule', 10, 2), 'DEMO-A4-0003'],
            ['Amlodipine', 'Demolodip', '5 mg', 'tablet', 'oral', 'antihypertensives', 'prescription_only', [['Amlodipine besilate', 5, 'mg']], $tablet('tablet', 10, 3), 'DEMO-A4-0004'],
            ['Oral rehydration salts', 'DemORS', '20.5 g', 'sachet_powder', 'oral', 'gastrointestinal', 'otc', [['Oral rehydration salts', 20.5, 'g']], [
                ['level' => 0, 'code' => 'sachet', 'name' => 'Sachet', 'unit_code' => 'sachet'],
                ['level' => 1, 'code' => 'box', 'name' => 'Box of 20 sachets', 'unit_code' => 'box', 'quantity_of_child' => 20],
            ], 'DEMO-A4-0005'],
        ];

        foreach ($products as [$generic, $brand, $strength, $form, $route, $category, $class, $ingredients, $levels, $regNo]) {
            if (Product::query()->where('generic_name', $generic)->exists()) {
                continue;
            }

            app(ProductCatalogueService::class)->create([
                'generic_name' => $generic,
                'brand_name' => $brand,
                'strength_text' => $strength,
                'dosage_form_code' => $form,
                'route_code' => $route,
                'category_id' => ProductCategory::query()->where('code', $category)->value('id'),
                'status' => 'active',
                'description' => 'SYNTHETIC DEMO PRODUCT for development only. Not a real registered medicine.',
                'ingredients' => array_map(fn ($i) => ['name' => $i[0], 'strength_value' => $i[1], 'strength_unit' => $i[2]], $ingredients),
                'package_levels' => $levels,
                'classifications' => [['country_code' => 'NG', 'classification' => $class, 'source' => 'SYNTHETIC DEMO DATA']],
                'regulatory_identifiers' => [[
                    'authority_code' => 'NAFDAC',
                    'identifier_type' => 'registration_number',
                    'value' => $regNo,
                    'declared_status' => 'unknown',
                    'holder_name' => 'FICTIONAL DEMO HOLDER',
                ]],
            ], $admin);
        }
    }
}

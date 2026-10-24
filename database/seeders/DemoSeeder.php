<?php

namespace Database\Seeders;

use App\Domain\Access\Models\Role;
use App\Domain\Catalogue\Models\OrganizationProduct;
use App\Domain\Catalogue\Models\Product;
use App\Domain\Catalogue\Models\ProductCategory;
use App\Domain\Catalogue\Services\ProductCatalogueService;
use App\Domain\Crm\Models\CustomerSegment;
use App\Domain\Crm\Services\ContactService;
use App\Domain\Crm\Services\CrmAutomation;
use App\Domain\Crm\Services\DetailingService;
use App\Domain\Crm\Services\InteractionService;
use App\Domain\Crm\Services\OpportunityService;
use App\Domain\Crm\Services\SegmentService;
use App\Domain\Crm\Services\TargetService;
use App\Domain\Crm\Services\VisitPlanService;
use App\Domain\FieldForce\Models\FieldVisit;
use App\Domain\FieldForce\Services\FieldOrderService;
use App\Domain\FieldForce\Services\TerritoryService;
use App\Domain\FieldForce\Services\VisitService;
use App\Domain\Integration\Canonical\MappingTemplates;
use App\Domain\Integration\Models\IntegrationConnector;
use App\Domain\Integration\Models\IntegrationMessage;
use App\Domain\Integration\Models\WebhookSubscription;
use App\Domain\Integration\Services\ConnectorService;
use App\Domain\Integration\Services\IntegrationInbox;
use App\Domain\Integration\Services\IntegrationProcessor;
use App\Domain\Integration\Webhooks\WebhookService;
use App\Domain\Inventory\Services\DirectReceiptService;
use App\Domain\Marketplace\Models\MarketplaceListing;
use App\Domain\Marketplace\Models\MarketplaceOrder;
use App\Domain\Marketplace\Models\MarketplaceShipment;
use App\Domain\Marketplace\Models\TradingRelationship;
use App\Domain\Marketplace\Services\ListingService;
use App\Domain\Marketplace\Services\MarketplaceFulfilmentService;
use App\Domain\Marketplace\Services\MarketplaceOrderService;
use App\Domain\Marketplace\Services\RelationshipService;
use App\Domain\Marketplace\Services\RfqService;
use App\Domain\Organizations\Enums\VerificationStatus;
use App\Domain\Organizations\Models\Branch;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationModule;
use App\Domain\Organizations\Models\Warehouse;
use App\Domain\Organizations\Services\OrganizationOnboardingService;
use App\Domain\Parties\Models\Customer;
use App\Domain\Parties\Models\Supplier;
use App\Domain\Pos\Models\PosTerminal;
use App\Domain\Pos\Services\PosSaleService;
use App\Domain\Pos\Services\PosSessionService;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Services\GoodsReceiptService;
use App\Domain\Procurement\Services\PurchaseOrderService;
use App\Domain\Sales\Services\FulfilmentService;
use App\Domain\Sales\Services\SalesOrderService;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

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
            ['distributor', 'Demo National Distribution Ltd', 'national_distributor', true, ['dist.sales' => 'sales_manager', 'dist.rep1' => 'sales_representative', 'dist.rep2' => 'sales_representative', 'dist.supervisor' => 'field_supervisor', 'dist.warehouse' => 'warehouse_officer']],
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
        $this->pointOfSale();
        $this->fieldForce();
        $this->crm();
        $this->marketplace();
        $this->integrations();

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
                        'default_selling_price' => (string) (5 * ($i + 1)), 'default_purchase_cost' => (string) (3 * ($i + 1)), // per base unit (tablet, capsule, sachet)
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

            // Sales demo (spec §32): order priced from the catalogue selling price, delivered FEFO and invoiced.
            $customer = Customer::query()->where('code', 'DEMO-CUS-1')->firstOrFail();
            $sales = app(SalesOrderService::class);
            $order = $sales->createOrder($customer, $warehouse, [['product_id' => $product->id, 'package_level_code' => 'pack', 'quantity' => '2']], notes: 'SYNTHETIC DEMO ORDER');
            $order = $sales->confirm($order);
            app(FulfilmentService::class)->deliver($order, [['sales_order_line_id' => $order->lines->first()->id, 'quantity' => '2']]);
            app(FulfilmentService::class)->invoiceOrder($order);
        }, $membership);
    }

    /**
     * POS demo (spec §32 #4, #5): a terminal, a cashier shift with one cash sale
     * through invoice and stock deduction, closed and reviewed by the supervisor.
     */
    private function pointOfSale(): void
    {
        $org = Organization::query()->where('name', 'Demo Community Pharmacy')->first();
        if ($org === null || PosTerminal::acrossTenants()->where('organization_id', $org->id)->exists()) {
            return;
        }
        $member = fn (string $email) => $org->memberships()->whereHas('user', fn ($q) => $q->where('email', $email))->with('user')->firstOrFail();
        $owner = $org->memberships()->whereHas('roles', fn ($q) => $q->where('code', 'org_owner'))->with('user')->firstOrFail();
        $cashier = $member('ph1.cashier@demo.test');
        $supervisor = $member('ph1.supervisor@demo.test');
        $context = app(TenantContext::class);

        Auth::setUser($owner->user);
        $terminal = $context->runAs($org, fn () => app(PosSessionService::class)->registerTerminal(
            Warehouse::query()->where('code', 'WH-MAIN')->firstOrFail(), 'T1', 'Front counter (demo)'), $owner);

        Auth::setUser($cashier->user);
        $shift = $context->runAs($org, function () use ($terminal, $cashier) {
            $sessions = app(PosSessionService::class);
            $sales = app(PosSaleService::class);
            $shift = $sessions->openShift($terminal, $cashier->user, '10000');

            $sale = $sales->start($shift, $cashier->user, strtolower((string) Str::ulid()));
            $sales->setLines($sale, [['product_id' => Product::query()->where('generic_name', 'Oral rehydration salts')->value('id'), 'package_level_code' => 'box', 'quantity' => '1']]);
            $sale = $sales->checkout($sale);
            $sales->addPayment($sale, 'cash', (string) $sale->total, 'cash', (string) (ceil((float) $sale->total / 1000) * 1000));

            return $sessions->closeShift($shift, $sessions->drawer($shift), 'SYNTHETIC DEMO SHIFT');
        }, $cashier);

        Auth::setUser($supervisor->user);
        $context->runAs($org, fn () => app(PosSessionService::class)->reviewShift($shift, $supervisor->user, null), $supervisor);
    }

    /**
     * Field-force demo (spec §12, §32): two territories at the distributor, a verified
     * visit with an accepted mobile order, and a visit where location permission was
     * denied, recorded as such. Coordinates and client references are synthetic.
     */
    private function fieldForce(): void
    {
        $org = Organization::query()->where('name', 'Demo National Distribution Ltd')->first();
        if ($org === null || FieldVisit::acrossTenants()->where('organization_id', $org->id)->exists()) {
            return;
        }
        $member = fn (string $email) => $org->memberships()->whereHas('user', fn ($q) => $q->where('email', $email))->with('user')->firstOrFail();
        [$manager, $rep1, $rep2, $supervisor] = [$member('dist.sales@demo.test'), $member('dist.rep1@demo.test'), $member('dist.rep2@demo.test'), $member('dist.supervisor@demo.test')];
        $context = app(TenantContext::class);

        Auth::setUser($manager->user);
        $context->runAs($org, function () use ($rep1, $rep2, $supervisor) {
            $territories = app(TerritoryService::class);
            $warehouse = Warehouse::query()->where('code', 'WH-MAIN')->value('id');
            $island = $territories->save(null, ['code' => 'DEMO-LAG-ISL', 'name' => 'Demo Lagos Island', 'warehouse_id' => $warehouse]);
            $mainland = $territories->save(null, ['code' => 'DEMO-LAG-MLD', 'name' => 'Demo Lagos Mainland', 'warehouse_id' => $warehouse]);
            $territories->setMembers($island, [['user_id' => $rep1->user_id, 'role' => 'representative'], ['user_id' => $supervisor->user_id, 'role' => 'supervisor']]);
            $territories->setMembers($mainland, [['user_id' => $rep2->user_id, 'role' => 'representative'], ['user_id' => $supervisor->user_id, 'role' => 'supervisor']]);
            $territories->assignCustomers($island, [Customer::query()->where('code', 'DEMO-CUS-1')->value('id')], $rep1->user_id, true);
            $territories->assignCustomers($mainland, [Customer::query()->where('code', 'DEMO-CUS-2')->value('id')], $rep2->user_id, true);
        }, $manager);

        $device = ['platform' => 'android', 'app_version' => 'demo'];

        Auth::setUser($rep1->user);
        $context->runAs($org, function () use ($device) {
            $customer = Customer::query()->where('code', 'DEMO-CUS-1')->firstOrFail();
            $at = now()->subHours(3);
            $fix = fn ($t) => ['occurred_at' => $t->toIso8601String(), 'location_status' => 'granted', 'latitude' => (float) $customer->latitude + 0.0003,
                'longitude' => (float) $customer->longitude, 'accuracy_m' => 12, 'mock_location' => false, 'device' => $device];
            $visits = app(VisitService::class);
            $visit = $visits->checkIn(['client_reference' => 'demo-visit-1', 'customer_id' => $customer->id, 'visit_type' => 'order_taking',
                'objectives' => 'SYNTHETIC DEMO VISIT: restock and introduce the 20-pack', 'check_in' => $fix($at)])['visit'];
            $visits->checkOut($visit, ['check_out' => $fix($at->copy()->addMinutes(25)), 'outcome' => 'successful', 'order_opportunity' => true,
                'discussion_notes' => 'Synthetic notes.', 'activities' => [['activity_type' => 'detailing', 'recorded_at' => $at->copy()->addMinutes(5)->toIso8601String()]]]);

            app(FieldOrderService::class)->capture(['client_reference' => 'demo-order-1', 'customer_id' => $customer->id, 'field_visit_id' => $visit->id,
                'captured_at' => $at->copy()->addMinutes(20)->toIso8601String(), 'submit' => true, 'notes' => 'SYNTHETIC DEMO ORDER',
                'lines' => [['product_id' => Product::query()->where('generic_name', 'Paracetamol')->value('id'), 'package_level_code' => 'pack', 'quantity' => '2']]]);
        }, $rep1);

        Auth::setUser($rep2->user);
        $context->runAs($org, function () use ($device) {
            $customer = Customer::query()->where('code', 'DEMO-CUS-2')->firstOrFail();
            $at = now()->subHours(2);
            $visits = app(VisitService::class);
            $visit = $visits->checkIn(['client_reference' => 'demo-visit-2', 'customer_id' => $customer->id, 'visit_type' => 'follow_up',
                'check_in' => ['occurred_at' => $at->toIso8601String(), 'location_status' => 'denied', 'device' => $device]])['visit'];
            $visits->checkOut($visit, ['check_out' => ['occurred_at' => $at->copy()->addMinutes(15)->toIso8601String(), 'location_status' => 'denied', 'device' => $device],
                'outcome' => 'rescheduled', 'next_action' => 'Return with the pharmacist present', 'follow_up_date' => today()->addDays(5)->toDateString()]);
        }, $rep2);
    }

    /**
     * Marketplace demo (spec §14, §32): the distributor lists two products, the community
     * pharmacy trades with it, and one order runs through acceptance, shipment, receipt
     * and invoice. Prices and quantities are synthetic.
     */
    private function marketplace(): void
    {
        $org = fn (string $name) => Organization::query()->where('name', $name)->first();
        [$dist, $pharmacy, $importer, $manufacturer, $wholesaler, $hospital, $pharmacy2] = [$org('Demo National Distribution Ltd'), $org('Demo Community Pharmacy'),
            $org('Demo Pharma Imports Ltd'), $org('Demo Pharma Manufacturing Ltd'), $org('Demo Wholesale Medicines'), $org('Demo General Hospital'), $org('Demo Neighbourhood Pharmacy')];
        if (in_array(null, [$dist, $pharmacy, $importer, $manufacturer, $wholesaler, $hospital, $pharmacy2], true)) {
            return;
        }
        // Every demo organization trades on the marketplace (new organizations get it by default).
        foreach ([$dist, $pharmacy, $importer, $manufacturer, $wholesaler, $hospital, $pharmacy2] as $o) {
            // organization_modules has no primary key: update with an explicit where, never updateOrCreate.
            $row = OrganizationModule::query()->where('organization_id', $o->id)->where('module', 'marketplace');
            $row->exists() ? $row->update(['enabled' => true]) : OrganizationModule::query()->create(['organization_id' => $o->id, 'module' => 'marketplace', 'enabled' => true]);
        }
        $owner = fn (Organization $o) => $o->memberships()->whereHas('roles', fn ($q) => $q->where('code', 'org_owner'))->with('user')->firstOrFail();
        $context = app(TenantContext::class);
        $product = fn (string $generic) => Product::query()->where('generic_name', $generic)->value('id');
        $as = function (Organization $o, callable $work) use ($owner, $context) {
            $m = $owner($o);
            Auth::setUser($m->user);

            return $context->runAs($o, $work, $m);
        };

        // Supplier storefronts: profile and listings (pack, price, minimum order, lead time, availability, buyer types).
        $storefronts = [
            [$dist, ['headline' => 'SYNTHETIC DEMO: nationwide pharmaceutical distribution', 'description' => 'Fictional distributor for demonstrations. Next-day delivery across the South West.',
                'regions_served' => ['Lagos', 'Ogun', 'Oyo'], 'delivery_options' => ['delivery', 'pickup'], 'minimum_order_value' => '5000', 'contact_email' => 'marketplace.distributor@demo.test'], [
                    ['Paracetamol', 'blister', '70', '2', 2, null],
                    ['Oral rehydration salts', 'box', '1800', '1', 1, 'in_stock'],
                    ['Amoxicillin', 'pack', '950', '5', 2, null],
                    ['Artemether/Lumefantrine', 'pack', '1450', '3', 2, null],
                ]],
            [$importer, ['headline' => 'SYNTHETIC DEMO: imported antimalarials and antibiotics', 'description' => 'Fictional importer. Full cartons for distributors, wholesalers and hospitals.',
                'regions_served' => ['Lagos', 'FCT'], 'delivery_options' => ['delivery'], 'minimum_order_value' => '50000', 'contact_email' => 'marketplace.importer@demo.test'], [
                    ['Artemether/Lumefantrine', 'carton', '68000', '1', 7, 'in_stock', ['national_distributor', 'regional_distributor', 'wholesaler', 'hospital']],
                    ['Amoxicillin', 'carton', '42000', '1', 5, 'in_stock'],
                    ['Amlodipine', 'pack', '820', '10', 3, 'limited'],
                ]],
            [$manufacturer, ['headline' => 'SYNTHETIC DEMO: manufacturer direct supply', 'description' => 'Fictional manufacturer. Factory cartons to licensed distributors, chains and hospitals.',
                'regions_served' => ['Nationwide'], 'delivery_options' => ['delivery'], 'minimum_order_value' => '250000', 'contact_email' => 'marketplace.manufacturer@demo.test'], [
                    ['Paracetamol', 'carton', '2900', '20', 10, 'in_stock'],
                    ['Amlodipine', 'carton', '36000', '2', 10, 'in_stock'],
                ]],
            [$wholesaler, ['headline' => 'SYNTHETIC DEMO: wholesale OTC medicines for pharmacies', 'description' => 'Fictional wholesaler, not yet verified: lists over-the-counter products only.',
                'regions_served' => ['Lagos'], 'delivery_options' => ['delivery', 'pickup'], 'minimum_order_value' => '2000', 'contact_email' => 'marketplace.wholesaler@demo.test'], [
                    ['Paracetamol', 'pack', '640', '1', 1, null, ['pharmacy', 'pharmacy_chain']],
                    ['Oral rehydration salts', 'box', '1750', '2', 1, 'in_stock'],
                ]],
        ];
        $listings = [];
        foreach ($storefronts as [$supplier, $profile, $items]) {
            $listings[$supplier->id] = $as($supplier, function () use ($profile, $items, $product) {
                if (MarketplaceListing::query()->exists()) {
                    return MarketplaceListing::query()->orderBy('created_at')->orderBy('id')->get()->all();
                }
                $service = app(ListingService::class);
                $service->saveProfile(['listed' => true] + $profile);

                return array_map(fn ($i) => $service->save(null, array_filter([
                    'product_id' => $product($i[0]), 'package_level_code' => $i[1], 'unit_price' => $i[2], 'minimum_order_quantity' => $i[3], 'lead_time_days' => $i[4],
                    'availability_mode' => $i[5] ? 'declared' : 'live', 'declared_availability' => $i[5], 'allowed_buyer_types' => $i[6] ?? null,
                ], fn ($v) => $v !== null)), $items);
            });
        }

        // Trading partners: approved, and two still waiting for the supplier's decision.
        $relate = function (Organization $buyer, Organization $supplier, ?int $approveWithTermsDays, string $message) use ($as) {
            if (TradingRelationship::query()->withoutGlobalScopes()->where('buyer_organization_id', $buyer->id)->where('supplier_organization_id', $supplier->id)->exists()) {
                return;
            }
            $relationship = $as($buyer, fn () => app(RelationshipService::class)->request($supplier, $message));
            if ($approveWithTermsDays !== null) {
                $as($supplier, fn () => app(RelationshipService::class)->decide($relationship, true, 'Approved for demo trading', $approveWithTermsDays));
            }
        };
        $relate($pharmacy, $dist, 30, 'SYNTHETIC DEMO: weekly replenishment');
        $relate($hospital, $importer, 30, 'SYNTHETIC DEMO: antimalarial supply for the hospital pharmacy');
        $relate($hospital, $dist, 14, 'SYNTHETIC DEMO: top-up orders');
        $relate($pharmacy, $wholesaler, null, 'SYNTHETIC DEMO: OTC lines');
        $relate($pharmacy2, $dist, null, 'SYNTHETIC DEMO: new pharmacy looking for a distributor');

        if (MarketplaceOrder::query()->withoutGlobalScopes()->where('supplier_organization_id', $dist->id)->exists()) {
            return;
        }
        $warehouse = fn () => Warehouse::query()->where('code', 'WH-MAIN')->value('id');

        // A completed order: submitted, accepted, shipped, received and invoiced.
        $order = $as($pharmacy, function () use ($listings, $dist, $owner, $pharmacy, $warehouse) {
            $orders = app(MarketplaceOrderService::class);

            return $orders->submit($orders->createFromListings($dist, [['listing_id' => $listings[$dist->id][0]->id, 'quantity' => '2']], $warehouse(), null, 'SYNTHETIC DEMO ORDER'), $owner($pharmacy)->user);
        });
        $shipment = $as($dist, function () use ($order) {
            app(MarketplaceOrderService::class)->accept($order, null, 'Demo acceptance');

            return app(MarketplaceFulfilmentService::class)->ship($order, [['order_line_id' => $order->lines()->value('id'), 'quantity' => '2']], 'Demo Haulage', 'DEMO-TRK-0001');
        });
        $as($pharmacy, fn () => app(MarketplaceFulfilmentService::class)->receive(MarketplaceShipment::query()->findOrFail($shipment->id)));
        $as($dist, fn () => app(MarketplaceFulfilmentService::class)->invoice(MarketplaceOrder::query()->findOrFail($order->id)));

        // An order waiting for the supplier to accept it.
        $as($pharmacy, function () use ($listings, $dist, $owner, $pharmacy, $warehouse) {
            $orders = app(MarketplaceOrderService::class);
            $orders->submit($orders->createFromListings($dist, [['listing_id' => $listings[$dist->id][2]->id, 'quantity' => '5'], ['listing_id' => $listings[$dist->id][1]->id, 'quantity' => '2']],
                $warehouse(), null, 'SYNTHETIC DEMO: monthly antibiotics and ORS'), $owner($pharmacy)->user);
        });

        // An open request for quotation from the hospital to two suppliers, one of which has quoted.
        $rfq = $as($hospital, function () use ($dist, $importer, $product, $warehouse) {
            $rfqs = app(RfqService::class);
            $rfq = $rfqs->create(['title' => 'SYNTHETIC DEMO: antimalarial restock', 'response_deadline' => now()->addDays(7)->toDateTimeString(), 'delivery_warehouse_id' => $warehouse(),
                'notes' => 'Delivery within two weeks.', 'lines' => [['product_id' => $product('Artemether/Lumefantrine'), 'package_level_code' => 'pack', 'quantity' => '100']],
                'supplier_organization_ids' => [$importer->id, $dist->id]]);

            return $rfqs->transition($rfq, 'open');
        });
        $as($importer, function () use ($rfq, $hospital) {
            app(RfqService::class)->submitQuote($rfq, $hospital, ['valid_until' => now()->addDays(14)->toDateString(), 'notes' => 'SYNTHETIC DEMO quote: delivered in 5 days.',
                'lines' => [['rfq_line_id' => $rfq->lines()->value('id'), 'quantity' => '100', 'unit_price' => '1390', 'lead_time_days' => 5]]]);
        });
    }

    /**
     * A synthetic SAP S/4HANA connector for the manufacturer: SAP-style material and batch records
     * processed through the gateway (two matched by NAFDAC number, one quarantined for review),
     * and a paused webhook subscription to a non-resolving .invalid address.
     */
    private function integrations(): void
    {
        $org = Organization::query()->where('name', 'Demo Pharma Manufacturing Ltd')->first();
        $membership = $org?->memberships()->whereHas('user', fn ($q) => $q->where('email', 'mfr.integration@demo.test'))->with('user')->first();
        if ($membership === null || IntegrationConnector::acrossTenants()->where('organization_id', $org->id)->exists()) {
            return;
        }
        Auth::setUser($membership->user);

        app(TenantContext::class)->runAs($org, function () use ($membership) {
            $connectors = app(ConnectorService::class);
            $connector = $connectors->create(['code' => 'S4-DEMO', 'name' => 'SYNTHETIC DEMO SAP S/4HANA', 'system_type' => 'sap_s4hana',
                'system_version' => '2023 (fictional)', 'deployment_model' => 'on_premise', 'inbound_transport' => 'rest_push',
                'interface_notes' => 'SYNTHETIC DEMO: SAP-style extracts pushed as JSON by middleware. In a real project, record the SAP release, middleware and interface confirmed with the customer.'], $membership->user);
            $connectors->rotateKey($connector); // not shown; rotate it again from the API to obtain a usable key
            $connectors->setStatus($connector, 'active');
            foreach (['product' => 'sap_table_material', 'batch' => 'sap_table_batch'] as $entity => $template) {
                $connectors->replaceMappings($connector, $entity, MappingTemplates::get($template)['mappings']);
            }

            $records = [
                ['DEMO-MAT-1', 'product', ['MATNR' => '000000000000500001', 'MAKTX' => 'SYNTHETIC PARACETAMOL 500MG TABS', 'ZZREGNO' => 'DEMO-A4-0001', 'VERPR' => '2,40', 'WAERS' => 'NGN']],
                ['DEMO-MAT-2', 'product', ['MATNR' => '000000000000500003', 'MAKTX' => 'SYNTHETIC AMOXICILLIN 500MG CAPS', 'ZZREGNO' => 'DEMO-A4-0003', 'VERPR' => '7,10', 'WAERS' => 'NGN']],
                ['DEMO-MAT-3', 'product', ['MATNR' => '000000000000509999', 'MAKTX' => 'SYNTHETIC UNLISTED MATERIAL', 'VERPR' => '1,00', 'WAERS' => 'NGN']],
                ['DEMO-BAT-1', 'batch', ['MATNR' => '000000000000500001', 'CHARG' => 'S4-DEMO-PCM-01', 'VFDAT' => now()->addMonths(20)->format('Ymd'), 'HSDAT' => now()->subMonths(4)->format('Ymd'), 'ZUSTD' => 'F']],
            ];
            app(IntegrationInbox::class)->receive($connector, array_map(fn ($r) => ['message_id' => $r[0], 'entity_type' => $r[1], 'record' => $r[2]], $records), 'api');
            // Process now, so the demo does not depend on a running queue worker.
            foreach (IntegrationMessage::query()->where('connector_id', $connector->id)->orderBy('id')->pluck('id') as $id) {
                app(IntegrationProcessor::class)->process($id);
            }

            [$subscription] = app(WebhookService::class)->create(['name' => 'SYNTHETIC DEMO ERP receiver', 'url' => 'https://erp.demo.invalid/webhooks/pharma',
                'event_types' => ['inventory.stock_received', 'inventory.adjusted', 'sales.order_confirmed'], 'connector_id' => $connector->id], $membership->user);
            app(WebhookService::class)->setStatus(WebhookSubscription::query()->findOrFail($subscription->id), 'paused');
        }, $membership);
    }

    /**
     * Synthetic CRM data for the distributor: account tiers and call frequencies, a rule
     * segment, a professional contact with recorded consent, an approved key message and a
     * detailing record, a submitted visit plan awaiting the supervisor, targets, an
     * opportunity, feedback, and one automation run.
     */
    private function crm(): void
    {
        $org = Organization::query()->where('name', 'Demo National Distribution Ltd')->first();
        if ($org === null || CustomerSegment::acrossTenants()->where('organization_id', $org->id)->exists()) {
            return;
        }
        $member = fn (string $email) => $org->memberships()->whereHas('user', fn ($q) => $q->where('email', $email))->with('user')->firstOrFail();
        $owner = $org->memberships()->whereHas('roles', fn ($q) => $q->where('code', 'org_owner'))->with('user')->firstOrFail();
        [$manager, $rep1] = [$member('dist.sales@demo.test'), $member('dist.rep1@demo.test')];
        $context = app(TenantContext::class);
        $paracetamol = Product::query()->where('generic_name', 'Paracetamol')->value('id');

        Auth::setUser($manager->user);
        $message = $context->runAs($org, function () use ($manager, $rep1, $paracetamol) {
            $customer1 = Customer::query()->where('code', 'DEMO-CUS-1')->firstOrFail();
            $customer1->forceFill(['tier' => 'A', 'visit_frequency_days' => 14, 'potential_value' => '750000'])->save();
            Customer::query()->where('code', 'DEMO-CUS-2')->update(['tier' => 'B', 'visit_frequency_days' => 30]);
            app(SegmentService::class)->create(['code' => 'DEMO-TIER-A', 'name' => 'SYNTHETIC DEMO: tier A accounts', 'type' => 'rule', 'criteria' => ['tiers' => ['A']]], $manager->user);
            app(ContactService::class)->create($customer1, ['name' => 'Pharm. Demo Contact (fictional)', 'professional_type' => 'pharmacist', 'specialty' => 'Community pharmacy',
                'email' => 'demo.contact@example.invalid', 'contact_consent' => 'granted', 'consent_source' => 'SYNTHETIC DEMO consent form']);
            $period = ['period_start' => today()->startOfMonth()->toDateString(), 'period_end' => today()->endOfMonth()->toDateString()];
            app(TargetService::class)->create(['scope_type' => 'representative', 'representative_id' => $rep1->user_id, 'metric' => 'visits_completed', 'target_value' => '20',
                'notes' => 'SYNTHETIC DEMO TARGET'] + $period, $manager->user);
            app(TargetService::class)->create(['scope_type' => 'representative', 'representative_id' => $rep1->user_id, 'metric' => 'field_order_value', 'target_value' => '50000',
                'notes' => 'SYNTHETIC DEMO TARGET'] + $period, $manager->user);

            return app(DetailingService::class)->createMessage(['product_id' => $paracetamol, 'title' => 'SYNTHETIC DEMO: dosing reminder',
                'key_message' => 'Fictional key message for demonstration only.', 'approval_reference' => 'DEMO-APPROVAL-001'], $manager->user);
        }, $manager);

        Auth::setUser($owner->user);
        $context->runAs($org, fn () => app(DetailingService::class)->approve($message, $owner->user), $owner);

        Auth::setUser($rep1->user);
        $context->runAs($org, function () use ($message, $paracetamol) {
            $customer = Customer::query()->where('code', 'DEMO-CUS-1')->firstOrFail();
            $visit = FieldVisit::query()->where('client_reference', 'demo-visit-1')->first();
            if ($visit !== null) {
                // Recorded within the 24 hours after check-out that the rules allow.
                app(DetailingService::class)->record($visit, ['client_reference' => 'demo-detailing-1', 'product_id' => $paracetamol, 'message_ids' => [$message->id],
                    'reception' => 'positive', 'notes' => 'SYNTHETIC DEMO DETAILING']);
            }
            $plans = app(VisitPlanService::class);
            $plan = $plans->create(['period_start' => today()->addDays(7)->toDateString(), 'period_end' => today()->addDays(11)->toDateString(),
                'notes' => 'SYNTHETIC DEMO PLAN', 'items' => [['customer_id' => $customer->id, 'planned_date' => today()->addDays(7)->toDateString(), 'objective' => 'Review the 20-pack uptake']]]);
            $plans->submit($plan);
            app(OpportunityService::class)->create(['client_reference' => 'demo-opportunity-1', 'customer_id' => $customer->id, 'title' => 'SYNTHETIC DEMO: analgesics range extension',
                'stage' => 'proposal', 'expected_value' => '250000', 'next_step' => 'Send the price proposal', 'next_step_date' => today()->addDays(3)->toDateString()]);
            app(InteractionService::class)->recordFeedback(['client_reference' => 'demo-feedback-1', 'customer_id' => $customer->id, 'category' => 'delivery', 'rating' => 4,
                'description' => 'SYNTHETIC DEMO FEEDBACK: deliveries arrive on time.']);
        }, $rep1);

        Auth::setUser($manager->user);
        $context->runAs($org, fn () => app(CrmAutomation::class)->run(), $manager);
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

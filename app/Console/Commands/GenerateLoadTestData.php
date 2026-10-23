<?php

namespace App\Console\Commands;

use App\Domain\Access\Models\Role;
use App\Domain\Billing\Models\SubscriptionPlan;
use App\Domain\Billing\Services\EntitlementService;
use App\Domain\Catalogue\Models\OrganizationProduct;
use App\Domain\Catalogue\Models\Product;
use App\Domain\Catalogue\Models\ProductCategory;
use App\Domain\Catalogue\Services\ProductCatalogueService;
use App\Domain\Inventory\Services\DirectReceiptService;
use App\Domain\Organizations\Enums\VerificationStatus;
use App\Domain\Organizations\Models\Branch;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationModule;
use App\Domain\Organizations\Models\Warehouse;
use App\Domain\Organizations\Services\OrganizationOnboardingService;
use App\Domain\Parties\Models\Customer;
use App\Domain\Pos\Services\PosSaleService;
use App\Domain\Pos\Services\PosSessionService;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Synthetic data at volume for load testing (Phase 10). Everything goes through the
 * domain services, so ledgers, trace chains and journals are as production would hold
 * them. Names are marked LOAD TEST and users use the reserved .test domain.
 *
 * Refuses production, and refuses any database whose name does not end in "_load"
 * so a development or test database is never filled by mistake.
 */
class GenerateLoadTestData extends Command
{
    protected $signature = 'perf:generate
        {--products=500 : Products in the product master and the chain\'s catalogue}
        {--customers=500 : Customer accounts}
        {--terminals=8 : POS terminals, each with its own cashier}
        {--days=60 : Days of POS history}
        {--sales-per-day=40 : POS sales per day across all terminals}';

    protected $description = 'Fill a *_load database with LOAD TEST data through the domain services';

    public const PASSWORD = 'LoadTest-2026!';

    public const ORG_NAME = 'LOAD TEST Pharmacy Chain';

    public function handle(TenantContext $tenant): int
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');
        if (app()->isProduction() || ! str_ends_with($database, '_load')) {
            $this->error("Refusing to run: the database must end in _load and the environment must not be production (database: {$database}).");

            return self::FAILURE;
        }
        if (Organization::query()->where('name', self::ORG_NAME)->exists()) {
            $this->error('LOAD TEST data already exists. Run migrate:fresh --seed on this database first.');

            return self::FAILURE;
        }

        $started = microtime(true);
        $admin = $this->user('admin@load.test', 'LOAD TEST Platform Admin');
        $admin->platformRoles()->syncWithoutDetaching(Role::query()->platform()->where('code', 'platform_super_admin')->pluck('id'));
        Auth::setUser($admin);

        $owner = $this->user('owner@load.test', 'LOAD TEST Owner');
        $org = app(OrganizationOnboardingService::class)->register($owner, [
            'name' => self::ORG_NAME, 'type' => 'pharmacy_chain', 'country_code' => 'NG', 'city' => 'Lagos', 'state_region' => 'Lagos', 'email' => 'contact@load.test',
        ]);
        $org->forceFill(['verification_status' => VerificationStatus::Verified, 'verified_at' => now()])->save();
        app(EntitlementService::class)->subscribe($org, SubscriptionPlan::query()->where('code', 'enterprise')->firstOrFail(), $admin);
        foreach (['analytics', 'traceability'] as $module) {
            OrganizationModule::query()->firstOrCreate(['organization_id' => $org->id, 'module' => $module], ['enabled' => true, 'changed_by' => $owner->id]);
        }
        $ownerMembership = $org->memberships()->where('user_id', $owner->id)->firstOrFail();

        $products = $this->products((int) $this->option('products'), $admin);
        $this->line(sprintf('Products: %d (%.0fs)', count($products), microtime(true) - $started));

        Auth::setUser($owner);
        [$warehouses, $customers] = $tenant->runAs($org, function () use ($products) {
            $warehouses = collect(range(1, 3))->map(function ($i) {
                $branch = Branch::query()->create(['code' => "BR{$i}", 'name' => "LOAD TEST Branch {$i}", 'city' => 'Lagos']);

                return Warehouse::query()->create(['code' => "WH{$i}", 'name' => "LOAD TEST Store {$i}", 'branch_id' => $branch->id]);
            });
            $customers = collect(range(1, (int) $this->option('customers')))->map(fn ($i) => Customer::query()->create([
                'code' => sprintf('LT-CUS-%05d', $i), 'name' => "LOAD TEST Customer {$i}", 'type' => 'pharmacy', 'currency_code' => 'NGN', 'payment_terms_days' => 14,
            ])->id);

            // Catalogue, then two batches per product in every store, received 30 lines at a time.
            foreach ($products as $i => $productId) {
                OrganizationProduct::query()->create(['product_id' => $productId, 'sku' => sprintf('LT-SKU-%05d', $i + 1), 'currency_code' => 'NGN',
                    'default_selling_price' => (string) (10 + $i % 90), 'default_purchase_cost' => (string) (6 + $i % 50), 'reorder_level' => 50, 'reorder_quantity' => 500]);
            }
            foreach ($warehouses as $w => $warehouse) {
                foreach (collect($products)->chunk(15) as $chunk) {
                    $lines = [];
                    foreach ($chunk as $i => $productId) {
                        foreach (['A' => 8, 'B' => 20] as $suffix => $months) {
                            $lines[] = ['product_id' => $productId, 'batch_number' => sprintf('LT-%d-%05d%s', $w + 1, $i + 1, $suffix),
                                'expiry_date' => now()->addMonths($months + $i % 6)->toDateString(), 'quantity' => 5000, 'unit_cost' => (string) (6 + $i % 50)];
                        }
                    }
                    app(DirectReceiptService::class)->receive($warehouse, $lines, reasonCode: 'opening_balance');
                }
            }

            return [$warehouses, $customers];
        }, $ownerMembership);
        $this->line(sprintf('Stores, customers and stock: %.0fs', microtime(true) - $started));

        $this->posHistory($org, $owner, $warehouses->all(), $products, $customers->all());
        $this->line(sprintf('Done in %.0fs. Users: owner@load.test, cashier1..%d@load.test, admin@load.test; password %s',
            microtime(true) - $started, (int) $this->option('terminals'), self::PASSWORD));

        return self::SUCCESS;
    }

    /** @return list<string> product ids */
    private function products(int $count, User $admin): array
    {
        $categories = ProductCategory::query()->pluck('id')->all();
        $catalogue = app(ProductCatalogueService::class);
        $ids = [];
        for ($i = 1; $i <= $count; $i++) {
            $ids[] = $catalogue->create([
                'generic_name' => sprintf('Loadtestamine %05d', $i),
                'brand_name' => sprintf('LoadBrand %05d', $i),
                'strength_text' => (5 * ($i % 40 + 1)).' mg',
                'dosage_form_code' => 'tablet',
                'route_code' => 'oral',
                'category_id' => $categories[$i % count($categories)],
                'status' => 'active',
                'description' => 'LOAD TEST PRODUCT. Synthetic data for performance testing only.',
                'ingredients' => [['name' => sprintf('Loadtestamine %05d', $i), 'strength_value' => 5 * ($i % 40 + 1), 'strength_unit' => 'mg']],
                'package_levels' => [
                    ['level' => 0, 'code' => 'unit', 'name' => 'Tablet', 'unit_code' => 'tablet'],
                    ['level' => 1, 'code' => 'pack', 'name' => 'Pack of 10', 'unit_code' => 'pack', 'quantity_of_child' => 10],
                ],
                'classifications' => [['country_code' => 'NG', 'classification' => 'otc', 'source' => 'LOAD TEST DATA']],
            ], $admin)->id;
        }

        return $ids;
    }

    /** Daily shifts on every terminal with sales spread over the period, clock moved back for each day. */
    private function posHistory(Organization $org, User $owner, array $warehouses, array $products, array $customers): void
    {
        $tenant = app(TenantContext::class);
        $sessions = app(PosSessionService::class);
        $sales = app(PosSaleService::class);
        $terminalCount = (int) $this->option('terminals');
        $days = (int) $this->option('days');
        $perDay = (int) $this->option('sales-per-day');
        $cashierRole = Role::query()->whereNull('organization_id')->where('scope', 'organization')->where('code', 'pos_cashier')->pluck('id');

        $ownerMembership = $org->memberships()->where('user_id', $owner->id)->firstOrFail();
        $tills = [];
        for ($t = 1; $t <= $terminalCount; $t++) {
            $cashier = $this->user("cashier{$t}@load.test", "LOAD TEST Cashier {$t}");
            $membership = $org->memberships()->firstOrCreate(['user_id' => $cashier->id], ['status' => 'active', 'joined_at' => now(), 'all_locations' => true]);
            $membership->roles()->syncWithoutDetaching($cashierRole);
            Auth::setUser($owner);
            $terminal = $tenant->runAs($org, fn () => $sessions->registerTerminal($warehouses[($t - 1) % count($warehouses)], "T{$t}", "LOAD TEST Till {$t}"), $ownerMembership);
            $tills[] = [$cashier, $membership->fresh(), $terminal];
        }

        $today = Carbon::now();
        $made = 0;
        $started = microtime(true);
        try {
            for ($d = $days; $d >= 1; $d--) {
                Carbon::setTestNow($today->copy()->subDays($d)->setTime(9, 0));
                foreach ($tills as $k => [$cashier, $membership, $terminal]) {
                    Auth::setUser($cashier);
                    $tenant->runAs($org, function () use ($sessions, $sales, $cashier, $terminal, $perDay, $terminalCount, $products, $customers, $k, $d, &$made) {
                        $shift = $sessions->openShift($terminal, $cashier, '20000');
                        $count = intdiv($perDay, $terminalCount) + ($k < $perDay % $terminalCount ? 1 : 0);
                        for ($s = 0; $s < $count; $s++) {
                            Carbon::setTestNow(Carbon::now()->addMinutes(7));
                            $sale = $sales->start($shift, $cashier, strtolower((string) Str::ulid()), $s % 5 === 0 ? Customer::query()->find($customers[($d * 31 + $s) % count($customers)]) : null);
                            $lines = [];
                            foreach (range(0, ($s + $d) % 4) as $n) {
                                $lines[] = ['product_id' => $products[($d * 97 + $k * 13 + $s * 7 + $n * 31) % count($products)], 'package_level_code' => $n % 2 ? 'unit' : 'pack', 'quantity' => (string) (1 + ($s + $n) % 3)];
                            }
                            $sales->setLines($sale, $lines);
                            $sale = $sales->checkout($sale);
                            $sales->addPayment($sale, 'cash', (string) $sale->total, 'cash', (string) (ceil((float) $sale->total / 500) * 500));
                            $made++;
                        }
                        $sessions->closeShift($shift, $sessions->drawer($shift), 'LOAD TEST shift');
                    }, $membership);
                }
                if ($d % 10 === 0) {
                    $this->line(sprintf('  POS history: %d days left, %d sales, %.1f sales/s', $d, $made, $made / max(1, microtime(true) - $started)));
                }
            }
        } finally {
            Carbon::setTestNow();
        }
        $this->line(sprintf('POS sales: %d in %.0fs (%.1f/s through the services, single process)', $made, microtime(true) - $started, $made / max(1, microtime(true) - $started)));
    }

    private function user(string $email, string $name): User
    {
        return User::query()->where('email', $email)->first()
            ?? tap((new User)->forceFill(['email' => $email, 'name' => $name, 'password' => self::PASSWORD, 'email_verified_at' => now()]))->save();
    }
}

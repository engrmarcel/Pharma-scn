<?php

namespace Database\Seeders;

use App\Domain\Billing\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

/**
 * Starting plan catalogue (spec §21.1). Plans are created once and then
 * belong to the commercial team: re-running this seeder never overwrites
 * names, prices or entitlements that were edited afterwards. Prices are left
 * NULL ("by agreement") because pricing is a commercial decision.
 */
class SubscriptionPlanSeeder extends Seeder
{
    public function run(): void
    {
        $allModules = ['catalogue', 'inventory', 'procurement', 'sales', 'finance', 'pos', 'field_force', 'marketplace', 'traceability', 'logistics', 'integrations', 'analytics'];

        $plans = [
            'trial' => ['Trial', 30, false, ['users' => 10, 'branches' => 3, 'warehouses' => 3, 'pos_terminals' => 2, 'mobile_users' => 5, 'integration_connectors' => 1, 'storage_mb' => 1024, 'api_requests_per_minute' => 120], $allModules, []],
            'starter' => ['Starter', 0, true, ['users' => 3, 'branches' => 1, 'warehouses' => 1, 'pos_terminals' => 1, 'mobile_users' => 0, 'integration_connectors' => 0, 'storage_mb' => 512, 'api_requests_per_minute' => 60], ['catalogue', 'inventory', 'sales', 'pos'], []],
            'pharmacy' => ['Pharmacy', 0, true, ['users' => 10, 'branches' => 3, 'warehouses' => 3, 'pos_terminals' => 5, 'mobile_users' => 0, 'integration_connectors' => 0, 'storage_mb' => 2048, 'api_requests_per_minute' => 120], ['catalogue', 'inventory', 'procurement', 'sales', 'finance', 'pos', 'marketplace', 'traceability'], []],
            'professional' => ['Professional', 0, true, ['users' => 50, 'branches' => 10, 'warehouses' => 10, 'pos_terminals' => 20, 'mobile_users' => 20, 'integration_connectors' => 2, 'storage_mb' => 10240, 'api_requests_per_minute' => 300], $allModules, ['advanced_reporting']],
            'distributor' => ['Distributor', 0, true, ['users' => 200, 'branches' => 30, 'warehouses' => 50, 'pos_terminals' => 20, 'mobile_users' => 100, 'integration_connectors' => 5, 'storage_mb' => 51200, 'api_requests_per_minute' => 600], $allModules, ['advanced_reporting']],
            'enterprise' => ['Enterprise', 0, false, [], $allModules, ['advanced_reporting', 'ai_features']],
        ];

        foreach ($plans as $code => [$name, $trialDays, $public, $limits, $modules, $features]) {
            $plan = SubscriptionPlan::query()->firstOrCreate(['code' => $code], [
                'name' => $name, 'trial_days' => $trialDays, 'is_public' => $public, 'currency_code' => 'NGN',
            ]);

            if (! $plan->wasRecentlyCreated) {
                continue;
            }

            foreach (array_keys(config('entitlements.keys')) as $key) {
                if (config('entitlements.keys')[$key][0] === 'limit') {
                    // Enterprise: every limit unlimited (NULL).
                    $plan->setEntitlement($key, true, $code === 'enterprise' ? null : ($limits[$key] ?? 0));
                } elseif (str_starts_with($key, 'module.')) {
                    $plan->setEntitlement($key, in_array(substr($key, 7), $modules, true));
                } else {
                    $plan->setEntitlement($key, in_array($key, $features, true));
                }
            }
        }
    }
}

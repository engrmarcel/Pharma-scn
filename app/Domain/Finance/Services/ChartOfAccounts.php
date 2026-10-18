<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\Account;
use App\Support\Exceptions\BusinessRuleViolation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Default chart of accounts, created per organization on first use. Codes
 * and names can be changed by the organization; automatic postings find
 * accounts by system_key, never by code. Country-specific charts can replace
 * this template without code changes to posting logic.
 */
class ChartOfAccounts
{
    /** system_key => [code, name, type] */
    public const TEMPLATE = [
        'cash' => ['1000', 'Cash on hand', 'asset'],
        'bank' => ['1010', 'Bank', 'asset'],
        'mobile_money' => ['1020', 'Mobile money / card settlements', 'asset'],
        'accounts_receivable' => ['1100', 'Accounts receivable', 'asset'],
        'inventory' => ['1200', 'Inventory', 'asset'],
        'input_tax' => ['1300', 'Input tax recoverable', 'asset'],
        'returns_pending_credit' => ['1400', 'Supplier returns awaiting credit', 'asset'],
        'accounts_payable' => ['2000', 'Accounts payable', 'liability'],
        'output_tax' => ['2100', 'Output tax payable', 'liability'],
        'grni' => ['2200', 'Goods received not invoiced', 'liability'],
        'customer_advances' => ['2300', 'Customer advances', 'liability'],
        'equity' => ['3000', "Owner's equity", 'equity'],
        'retained_earnings' => ['3100', 'Retained earnings', 'equity'],
        'sales_revenue' => ['4000', 'Sales revenue', 'revenue'],
        'sales_returns' => ['4100', 'Sales returns and allowances', 'revenue'],
        'cogs' => ['5000', 'Cost of goods sold', 'expense'],
        'inventory_adjustment' => ['5100', 'Inventory adjustments and write-offs', 'expense'],
        'purchase_price_variance' => ['5200', 'Purchase price variance', 'expense'],
        'other_expenses' => ['5900', 'Other purchase charges', 'expense'],
    ];

    /** @var array<string, array<string, string>> tenant => system_key => account id */
    private array $cache = [];

    public function __construct(private readonly TenantContext $tenant) {}

    public function ensure(): void
    {
        $orgId = $this->orgId();
        if (Account::query()->whereNotNull('system_key')->count() >= count(self::TEMPLATE)) {
            return;
        }

        $now = now();
        DB::table('accounts')->insertOrIgnore(array_map(fn ($key, $def) => [
            'id' => strtolower((string) Str::ulid()),
            'organization_id' => $orgId,
            'code' => $def[0],
            'name' => $def[1],
            'type' => $def[2],
            'system_key' => $key,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ], array_keys(self::TEMPLATE), self::TEMPLATE));
    }

    public function id(string $systemKey): string
    {
        $orgId = $this->orgId();
        if (! isset($this->cache[$orgId])) {
            $this->ensure();
            $this->cache[$orgId] = Account::query()->whereNotNull('system_key')->pluck('id', 'system_key')->all();
        }

        return $this->cache[$orgId][$systemKey]
            ?? throw new BusinessRuleViolation('account_missing', "System account '{$systemKey}' is not configured.", 500);
    }

    /** Cash-type account for a payment method. */
    public function forPaymentMethod(string $method): string
    {
        return $this->id(match ($method) {
            'cash' => 'cash',
            'card', 'mobile_money' => 'mobile_money',
            default => 'bank',
        });
    }

    private function orgId(): string
    {
        return $this->tenant->id() ?? throw new \LogicException('Accounting requires a tenant context.');
    }
}

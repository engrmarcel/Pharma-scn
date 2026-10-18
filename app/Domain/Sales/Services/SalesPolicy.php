<?php

namespace App\Domain\Sales\Services;

use App\Domain\Parties\Models\Customer;
use App\Models\User;
use App\Support\Exceptions\BusinessRuleViolation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Organization sales settings (organizations.settings.sales):
 *   max_discount_percent - line discounts above this need approval (default 0: any discount needs approval)
 *   allow_self_approval  - false: approval must come from someone other than the order's creator
 * Customer credit limit NULL means no credit limit is enforced for that customer.
 */
class SalesPolicy
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function settings(): array
    {
        $s = $this->tenant->organization()?->settings ?? [];

        return [
            'max_discount_percent' => (string) data_get($s, 'sales.max_discount_percent', '0'),
            'allow_self_approval' => (bool) data_get($s, 'sales.allow_self_approval', false),
        ];
    }

    /** Open receivables (issued invoices less payments and credits). */
    public function openReceivables(Customer $customer): string
    {
        $open = DB::table('sales_invoices')->where('organization_id', $this->tenant->id())->where('customer_id', $customer->id)->where('status', 'issued')
            ->selectRaw('COALESCE(SUM(total - amount_paid - amount_credited), 0) as open')->value('open');

        return bcadd((string) $open, '0', 4);
    }

    /**
     * Reasons this order needs approval before stock is committed.
     *
     * @return list<array{reason: string}>
     */
    public function approvalReasons(Customer $customer, string $orderTotal, iterable $lines): array
    {
        $reasons = [];
        $maxDiscount = $this->settings()['max_discount_percent'];
        foreach ($lines as $line) {
            if (bccomp((string) $line->discount_percent, $maxDiscount, 4) > 0) {
                $reasons[] = ['reason' => 'discount_above_limit', 'line_id' => $line->id, 'discount_percent' => (string) $line->discount_percent, 'limit' => $maxDiscount];
            }
        }

        if ($customer->credit_limit !== null) {
            $exposure = bcadd($this->openReceivables($customer), $orderTotal, 4);
            if (bccomp($exposure, (string) $customer->credit_limit, 4) > 0) {
                $reasons[] = ['reason' => 'credit_limit_exceeded', 'credit_limit' => (string) $customer->credit_limit, 'exposure' => $exposure];
            }
        }

        return $reasons;
    }

    public function assertMayApprove(?int $creatorId, User $approver): void
    {
        if ($creatorId === $approver->id && ! $this->settings()['allow_self_approval']) {
            throw new BusinessRuleViolation('self_approval_not_allowed', 'Approval must come from someone other than the person who raised the order.', 403);
        }
    }
}

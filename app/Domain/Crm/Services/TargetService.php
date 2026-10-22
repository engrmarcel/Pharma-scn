<?php

namespace App\Domain\Crm\Services;

use App\Domain\Crm\Models\SalesTarget;
use App\Domain\FieldForce\Models\Territory;
use App\Domain\FieldForce\Services\FieldForceScope;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Platform\Services\AuditLogger;
use App\Models\User;
use App\Support\Exceptions\BusinessRuleViolation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Sales targets and their achievement (spec §17).
 *
 * Attribution (documented in the response):
 *   invoiced_revenue   issued sales invoices less credits, by invoice date, for the customers
 *                      assigned to the representative / in the territory (current assignment)
 *   product_units      invoiced base units of the product, less credited units, same customers
 *   field_order_value  ERP sales-order value of accepted mobile orders, by capture date
 *                      (representative: own orders; territory: orders for its customers)
 *   visits_completed   completed visits by check-in date (representative: own; territory: visits
 *                      made in the territory)
 *
 * Achievement never shows more than the viewer may see: unless the viewer has
 * field_force.view_all or is the target's own representative, the figures are
 * limited to customers visible to the viewer (scope "partial" in the response).
 */
class TargetService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly FieldForceScope $scope,
        private readonly AuditLogger $audit,
    ) {}

    public function create(array $data, User $actor): SalesTarget
    {
        if ($data['scope_type'] === 'representative') {
            if (! OrganizationMembership::query()->where('organization_id', $this->tenant->id())->where('user_id', $data['representative_id'] ?? 0)->where('status', 'active')->exists()) {
                throw new BusinessRuleViolation('representative_invalid', 'The representative must be an active member of the organization.', 422);
            }
            $data['territory_id'] = null;
        } else {
            Territory::query()->findOrFail($data['territory_id'] ?? '');
            $data['representative_id'] = null;
        }
        if (($data['metric'] === 'product_units') !== isset($data['product_id'])) {
            throw new BusinessRuleViolation('target_product_invalid', 'A product is required for product_units targets, and only for them.', 422);
        }
        if (Carbon::parse($data['period_end'])->lt(Carbon::parse($data['period_start']))) {
            throw new BusinessRuleViolation('target_period_invalid', 'The period ends before it starts.', 422);
        }
        $duplicate = SalesTarget::query()->where('scope_type', $data['scope_type'])->where('metric', $data['metric'])
            ->where('representative_id', $data['representative_id'])->where('territory_id', $data['territory_id'])->where('product_id', $data['product_id'] ?? null)
            ->where('period_start', '<=', $data['period_end'])->where('period_end', '>=', $data['period_start'])->exists();
        if ($duplicate) {
            throw new BusinessRuleViolation('target_overlap', 'A target for the same scope and metric already covers part of this period.', 409);
        }

        $target = SalesTarget::query()->create($data + ['created_by' => $actor->id]);
        $this->audit->record('sales_target.created', $target, new: $data);

        return $target;
    }

    /** Targets the viewer may see: their own and their team's, and those of their territories. */
    public function visible(): Builder
    {
        $query = SalesTarget::query();
        if (Gate::allows('field_force.view_all') || Gate::allows('crm.targets.manage')) {
            return $query;
        }
        $team = $this->scope->visibleRepresentativeIds() ?? [];
        $territories = $this->scope->territoryIds();

        return $query->where(fn ($q) => $q->whereIn('representative_id', $team)->orWhereIn('territory_id', $territories));
    }

    public function achievement(SalesTarget $target): array
    {
        $full = Gate::allows('field_force.view_all') || $target->representative_id === Auth::id();
        $start = $target->period_start->toDateString();
        $end = $target->period_end->toDateString();

        $actual = match ($target->metric) {
            'invoiced_revenue' => $this->invoices($target, $full)->sum(DB::raw('i.total - i.amount_credited')),
            'product_units' => $this->invoices($target, $full)->join('sales_invoice_lines as l', 'l.sales_invoice_id', '=', 'i.id')
                ->where('l.product_id', $target->product_id)->sum(DB::raw('l.base_quantity - l.credited_base_quantity')),
            'field_order_value' => $this->limit(DB::table('field_orders as fo')->join('sales_orders as so', 'so.id', '=', 'fo.sales_order_id')
                ->where('fo.organization_id', $target->organization_id)->where('fo.status', 'accepted')
                ->whereBetween('fo.captured_at', [$start.' 00:00:00', $end.' 23:59:59'])
                ->when($target->scope_type === 'representative', fn ($q) => $q->where('fo.representative_id', $target->representative_id),
                    fn ($q) => $q->whereIn('fo.customer_id', DB::table('customers')->where('territory_id', $target->territory_id)->select('id'))), 'fo.customer_id', $full)
                ->sum('so.total'),
            'visits_completed' => $this->limit(DB::table('field_visits as v')->where('v.organization_id', $target->organization_id)->where('v.status', 'completed')
                ->whereBetween('v.check_in_at', [$start.' 00:00:00', $end.' 23:59:59'])
                ->when($target->scope_type === 'representative', fn ($q) => $q->where('v.representative_id', $target->representative_id),
                    fn ($q) => $q->where('v.territory_id', $target->territory_id)), 'v.customer_id', $full)
                ->count(),
        };

        $actual = bcadd((string) ($actual ?? '0'), '0', 4);
        $days = $target->period_start->diffInDays($target->period_end) + 1;
        $elapsed = max(0, min($days, (int) $target->period_start->diffInDays(business_today(), false) + 1));
        $expected = bcmul((string) $target->target_value, (string) round($elapsed / $days, 6), 4);
        $percent = bccomp((string) $target->target_value, '0', 4) > 0 ? round((float) $actual / (float) $target->target_value * 100, 1) : null;

        return [
            'actual' => $actual,
            'target' => (string) $target->target_value,
            'attainment_percent' => $percent,
            'expected_to_date' => $expected,
            'pace' => $elapsed === 0 ? 'not_started' : (bccomp($actual, $expected, 4) >= 0 ? 'on_track' : 'behind'),
            'elapsed_days' => $elapsed,
            'period_days' => $days,
            'scope' => $full ? 'full' : 'partial',
            'note' => $full ? null : 'Limited to the customers you can see.',
        ];
    }

    private function invoices(SalesTarget $target, bool $full)
    {
        $query = DB::table('sales_invoices as i')->join('customers as c', 'c.id', '=', 'i.customer_id')
            ->where('i.organization_id', $target->organization_id)->where('i.status', 'issued')
            ->whereBetween('i.invoice_date', [$target->period_start->toDateString(), $target->period_end->toDateString()])
            ->when($target->scope_type === 'representative', fn ($q) => $q->where('c.assigned_representative_id', $target->representative_id),
                fn ($q) => $q->where('c.territory_id', $target->territory_id));

        return $this->limit($query, 'i.customer_id', $full);
    }

    private function limit($query, string $customerColumn, bool $full)
    {
        return $full ? $query : $this->scope->scopeCustomers($query, $customerColumn);
    }
}

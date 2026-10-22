<?php

namespace App\Domain\FieldForce\Services;

use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Supervisor reports (spec §12.5) over the representatives the caller may
 * see: visit completion and location verification, order capture vs. ERP
 * acceptance, and follow-up compliance.
 */
class FieldForceReports
{
    public function __construct(private readonly TenantContext $tenant, private readonly FieldForceScope $scope) {}

    public function activity(string $from, string $to): array
    {
        $range = [$from.' 00:00:00', $to.' 23:59:59'];
        $org = $this->tenant->id();

        $visits = $this->scope->scopeActivity(DB::table('field_visits')->where('organization_id', $org)->whereBetween('check_in_at', $range))
            ->groupBy('representative_id')
            ->selectRaw("representative_id,
                COUNT(*) as visits,
                SUM(status = 'completed') as completed,
                SUM(status = 'cancelled') as cancelled,
                SUM(check_in_verification = 'verified') as verified_check_ins,
                SUM(check_in_verification <> 'verified') as unverified_check_ins,
                SUM(check_in_location_status <> 'granted') as without_location,
                COUNT(DISTINCT customer_id) as customers_visited,
                AVG(CASE WHEN status = 'completed' THEN TIMESTAMPDIFF(MINUTE, check_in_at, check_out_at) END) as average_minutes")
            ->get()->keyBy('representative_id');

        $orders = $this->scope->scopeActivity(DB::table('field_orders as fo')->where('fo.organization_id', $org)->whereBetween('fo.captured_at', $range), 'fo.representative_id', 'fo.customer_id')
            ->leftJoin('sales_orders as so', 'so.id', '=', 'fo.sales_order_id')
            ->groupBy('fo.representative_id')
            ->selectRaw("fo.representative_id as representative_id,
                COUNT(*) as orders_captured,
                SUM(fo.status IN ('submitted', 'accepted', 'rejected')) as orders_submitted,
                SUM(fo.status = 'accepted') as orders_accepted,
                SUM(fo.status = 'rejected') as orders_rejected,
                SUM(fo.status = 'draft') as orders_draft,
                COALESCE(SUM(CASE WHEN fo.status = 'accepted' THEN so.total END), 0) as accepted_value")
            ->get()->keyBy('representative_id');

        $followUps = $this->scope->scopeActivity(DB::table('field_follow_ups')->where('organization_id', $org)->whereBetween('due_on', [$from, $to]), 'assigned_to')
            ->groupBy('assigned_to')
            ->selectRaw("assigned_to as representative_id,
                COUNT(*) as follow_ups_due,
                SUM(status = 'done' AND DATE(completed_at) <= due_on) as follow_ups_done_on_time,
                SUM(status = 'done' AND DATE(completed_at) > due_on) as follow_ups_done_late,
                SUM(status = 'open' AND due_on < ?) as follow_ups_overdue", [today()->toDateString()])
            ->get()->keyBy('representative_id');

        $ids = $visits->keys()->merge($orders->keys())->merge($followUps->keys())->unique()->values();
        $names = DB::table('users')->whereIn('id', $ids)->pluck('name', 'id');

        $rows = $ids->map(function ($id) use ($visits, $orders, $followUps, $names) {
            $v = $visits->get($id);
            $o = $orders->get($id);
            $f = $followUps->get($id);
            $due = (int) ($f->follow_ups_due ?? 0);

            return [
                'representative_id' => (int) $id,
                'name' => $names[$id] ?? null,
                'visits' => (int) ($v->visits ?? 0),
                'completed' => (int) ($v->completed ?? 0),
                'cancelled' => (int) ($v->cancelled ?? 0),
                'verified_check_ins' => (int) ($v->verified_check_ins ?? 0),
                'unverified_check_ins' => (int) ($v->unverified_check_ins ?? 0),
                'without_location' => (int) ($v->without_location ?? 0),
                'customers_visited' => (int) ($v->customers_visited ?? 0),
                'average_visit_minutes' => isset($v->average_minutes) ? round((float) $v->average_minutes, 1) : null,
                'orders_captured' => (int) ($o->orders_captured ?? 0),
                'orders_submitted' => (int) ($o->orders_submitted ?? 0),
                'orders_accepted' => (int) ($o->orders_accepted ?? 0),
                'orders_rejected' => (int) ($o->orders_rejected ?? 0),
                'orders_draft' => (int) ($o->orders_draft ?? 0),
                'accepted_value' => bcadd((string) ($o->accepted_value ?? '0'), '0', 4),
                'follow_ups_due' => $due,
                'follow_ups_done_on_time' => (int) ($f->follow_ups_done_on_time ?? 0),
                'follow_ups_done_late' => (int) ($f->follow_ups_done_late ?? 0),
                'follow_ups_overdue' => (int) ($f->follow_ups_overdue ?? 0),
                'follow_up_compliance_percent' => $due > 0 ? round(($f->follow_ups_done_on_time ?? 0) / $due * 100, 1) : null,
            ];
        })->sortBy('name')->values();

        return [
            'from' => $from, 'to' => $to,
            'representatives' => $rows,
            'note' => 'Verified check-ins require a real, precise device fix within the configured radius of the customer\'s recorded location. Accepted value is the ERP sales-order total, which may differ from prices shown on the device.',
        ];
    }
}
